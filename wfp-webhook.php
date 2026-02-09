<?php
// wfp-webhook.php

file_put_contents(__DIR__.'/parse_check.log', date('c')." HIT\n", FILE_APPEND);

$raw = file_get_contents('php://input') ?: '';
$ct  = $_SERVER['CONTENT_TYPE'] ?? '';

file_put_contents(
  __DIR__.'/webhook.log',
  date('c')
  ." METHOD=".$_SERVER['REQUEST_METHOD']
  ." CT=".$ct
  ." POST=".json_encode($_POST, JSON_UNESCAPED_UNICODE)
  ." RAW=".substr($raw, 0, 5000)
  ."\n",
  FILE_APPEND
);

function envv($k, $default = '') {
  $v = getenv($k);
  return ($v !== false && $v !== '') ? $v : $default;
}

function upstash_cmd(array $cmd) {
  $url = envv('UPSTASH_REDIS_REST_URL');
  $token = envv('UPSTASH_REDIS_REST_TOKEN');
  if (!$url || !$token) { throw new Exception("Upstash env missing"); }

  $ch = curl_init(rtrim($url, '/') . '/pipeline');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $token,
      'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode([$cmd], JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
  ]);

  $res  = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($res === false) throw new Exception("Upstash curl error: $err");
  if ($code < 200 || $code >= 300) throw new Exception("Upstash HTTP $code: $res");

  $j = json_decode($res, true);
  return $j[0]['result'] ?? null;
}

function ok_json($arr = []) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

// 1) читаем вход
$data = null;

/**
 * A. сначала пробуем JSON из RAW
 */
if ($raw !== '') {
  $j = json_decode($raw, true);
  if (is_array($j)) {
    $data = $j;
  }
}

/**
 * B. кейс WayForPay:
 * application/x-www-form-urlencoded
 * где ВЕСЬ JSON лежит в имени единственного поля
 */
if (!is_array($data) && !empty($_POST) && count($_POST) === 1) {
  $k = array_key_first($_POST);
  $v = $_POST[$k];

  if (
    is_string($k) &&
    $v === '' &&
    $k !== '' &&
    ($k[0] === '{' || $k[0] === '[')
  ) {
    $j2 = json_decode($k, true);
    if (is_array($j2)) {
      $data = $j2;
    }
  }
}

/**
 * C. обычный form POST
 */
if (!is_array($data) && !empty($_POST)) {
  $data = $_POST;
}

/**
 * D. крайний fallback — parse_str из RAW
 */
if (!is_array($data) && $raw !== '') {
  $tmp = [];
  parse_str($raw, $tmp);
  if (!empty($tmp)) {
    $data = $tmp;
  }
}

if (!is_array($data)) {
  http_response_code(400);
  ok_json(['error' => 'bad payload']);
}

file_put_contents(__DIR__.'/parse_check.log',
  date('c')." keys=".implode(',', array_keys($data))." orderReference=".($data['orderReference'] ?? 'NULL')."\n",
  FILE_APPEND
);

// ✅ ЛОГ (временно) — после того как $data уже распарсен
$log = $data;
unset(
  $log['cardPan'],
  $log['card_pan'],
  $log['merchantSignature'],
  $log['signature'],
  $log['email'],
  $log['phone']
);
file_put_contents(__DIR__.'/wfp.log', date('c')." CT=".$ct." ".json_encode($log, JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);

// 2) забираем базовые поля
$order_id = $data['orderReference'] ?? $data['order_reference'] ?? '';
$status   = $data['transactionStatus'] ?? $data['status'] ?? '';
$signature= $data['merchantSignature'] ?? $data['signature'] ?? '';

if ($order_id === '') { http_response_code(400); ok_json(['error' => 'orderReference missing']); }

// 3) Проверка подписи (ВАЖНО: порядок полей должен совпасть со спецификацией WFP callback)
//
// Ниже — типовой вариант для many callbacks WFP:
// merchantAccount; orderReference; amount; currency; authCode; cardPan; transactionStatus; reasonCode
//
// Но: если в твоём callback другие поля — я подправлю 1 раз,
// просто пришли пример payload из webhook (без карты/персональных).
//
$secret = envv('WFP_SECRET');
if (!$secret) { http_response_code(500); ok_json(['error'=>'WFP secret missing']); }
$merchantAccount = $data['merchantAccount'] ?? envv('WFP_MERCHANT', '');

$amount   = (string)($data['amount'] ?? '');
$currency = (string)($data['currency'] ?? '');
$authCode = (string)($data['authCode'] ?? '');
$cardPan  = (string)($data['cardPan'] ?? '');
$reasonCode = (string)($data['reasonCode'] ?? '');

$signStr = implode(';', [
  $merchantAccount,
  $order_id,
  $amount,
  $currency,
  $authCode,
  $cardPan,
  $status,
  $reasonCode,
]);

$calc = hash_hmac('md5', $signStr, $secret);

// ✅ DEV bypass (временно) — чтобы тестировать путь до Meta без реальной подписи
$dev = envv('DEV_BYPASS_WFP_SIGNATURE', '0');

if ($dev !== '1') {
  if (!hash_equals(strtolower($calc), strtolower($signature))) {
    http_response_code(403);
    ok_json(['error' => 'bad signature']);
  }
} else {
  // полезно логировать, что ты реально в bypass
  file_put_contents(__DIR__.'/parse_check.log', date('c')." DEV_BYPASS_WFP_SIGNATURE=1\n", FILE_APPEND);
}

// 4) проверяем статус успеха
$success = in_array(strtoupper($status), ['APPROVED', 'SUCCESS', 'ACCEPTED'], true);
if (!$success) {
  // можно логировать как failed payment
  ok_json(['ok' => true, 'ignored' => 'not success', 'status' => $status]);
}

// 5) Дедуп: если уже отправляли Purchase по этому order_id — выходим OK
$dedupeKey = "wfp:purchase_sent:$order_id";
$setnx = upstash_cmd(["SET", $dedupeKey, "1", "NX", "EX", 60*60*24*90]); // 90 дней
if ($setnx !== "OK") {
  ok_json(['ok' => true, 'deduped' => true]);
}

// 6) Достаём token по order_id
$mapKey = "wfp:order:$order_id";
$mapJson = upstash_cmd(["GET", $mapKey]);
$map = $mapJson ? json_decode($mapJson, true) : null;
$token = is_array($map) ? ($map['token'] ?? '') : '';

if ($token === '') {
  // если маппинга нет — всё равно можно отправить Purchase только по order_id,
  // но атрибуция будет хуже
  $token = 'missing';
}

// 7) (опционально) достаём lead data по token: ph_sha256 / fbp / fbc / ua / ip
$lead = null;
if ($token !== 'missing') {
  $leadJson = upstash_cmd(["GET", "lead:$token"]);
  $lead = $leadJson ? json_decode($leadJson, true) : null;
}

// 8) Отправляем Meta Purchase (тут вызываешь свой meta-capi.php / или HTTP на Railway endpoint)
// Главное: event_id = order_id
// Ниже пример: дергаем ваш Railway endpoint /meta_purchase.php (поставь свой путь)
$metaEndpoint = envv('META_PURCHASE_ENDPOINT', 'https://metacapioporathesourceacademy-production.up.railway.app/meta_purchase.php');

$payload = [
  'event_name' => 'Purchase',
  'event_time' => time(),
  'event_id'   => $order_id,
  'token'      => $token,
  'lead'       => $lead,
  'event_source_url' => (is_array($lead) ? ($lead['page_url'] ?? null) : null),
  'custom_data' => [
    'currency' => $currency,
    'value'    => (float)$amount,
  ],
];

$ch = curl_init($metaEndpoint);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 10,
]);
$res = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

file_put_contents(
  __DIR__.'/meta_response.log',
  date('c')
  ." order=".$order_id
  ." http=".$code
  ." err=".$err
  ." body=".substr((string)$res, 0, 2000)
  ."\n",
  FILE_APPEND
);

if ($res === false) {
  upstash_cmd(["DEL", $dedupeKey]);
  http_response_code(502);
  ok_json(['error' => 'meta curl error', 'message' => $err]);
}

if ($code < 200 || $code >= 300) {
  // ВАЖНО: если Meta упало — ты уже поставил dedupeKey.
  // В проде лучше: при фейле удалить dedupeKey, чтобы WFP retry ещё раз сработал.
  // Я обычно делаю так:
  upstash_cmd(["DEL", $dedupeKey]);
  http_response_code(502);
  ok_json(['error' => 'meta failed', 'http' => $code, 'body' => $res]);
}

ok_json(['ok' => true]);