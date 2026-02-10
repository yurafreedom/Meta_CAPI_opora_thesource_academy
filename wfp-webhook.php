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
  file_put_contents(
    __DIR__ . '/wfp_dedup.log',
    date('c') . " DEDUP order={$order_id}\n",
    FILE_APPEND
  );
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

// 7.1) Если нет phone hash — НЕ отправляем Purchase (качество будет мусор)
// Логируем пропуск, но webhook отвечаем быстро (ok:true), чтобы WFP не ретраил бесконечно.
$ph = (is_array($lead) ? ($lead['ph_sha256'] ?? '') : '');
$ph = is_string($ph) ? strtolower(trim($ph)) : '';
$is_ph_valid = (bool)preg_match('~^[0-9a-f]{64}$~', $ph);

if (!$is_ph_valid) {
  // Лог пропуска
  file_put_contents(
    __DIR__ . '/wfp_skip.log',
    date('c') . " SKIP_NO_PHONE order={$order_id} token={$token} has_lead=" . (is_array($lead) ? '1' : '0') .
    " fbp=" . (is_array($lead) ? ($lead['fbp'] ?? '') : '') .
    " fbc=" . (is_array($lead) ? ($lead['fbc'] ?? '') : '') .
    " page=" . (is_array($lead) ? ($lead['page_url'] ?? '') : '') .
    "\n",
    FILE_APPEND
  );

  // ВАЖНО: дедуп-ключ уже мог быть поставлен выше.
  // Чтобы не “навсегда похоронить” заказ без телефона — удалим дедуп,
  // чтобы WFP retry (или ручной повтор) мог отправить позже, когда phone появится.
  upstash_cmd(["DEL", $dedupeKey]);

  ok_json(['ok' => true, 'skipped' => 'no_phone_hash']);
}

// 8) Отправляем Purchase напрямую в Meta Graph API (без self-call на /meta_purchase.php)
function log_meta_graph($msg) {
  @file_put_contents(__DIR__ . '/meta_graph.log', date('c') . " " . $msg . "\n", FILE_APPEND);
}

$ACCESS_TOKEN    = envv('META_CAPI_TOKEN');
$PIXEL_ID        = envv('META_PIXEL_ID');
$TEST_EVENT_CODE = envv('META_TEST_EVENT_CODE'); // если задан — будет тестовый ивент
$API_VERSION     = envv('META_API_VERSION', 'v21.0');

if (!$ACCESS_TOKEN || !$PIXEL_ID) {
  upstash_cmd(["DEL", $dedupeKey]);
  http_response_code(500);
  ok_json([
    'error' => 'meta env missing',
    'has_token' => (bool)$ACCESS_TOKEN,
    'has_pixel' => (bool)$PIXEL_ID,
  ]);
}

// user_data: минимум external_id (sha256) + по возможности fbp/fbc + ip/ua
$external_id_sha = ($token && $token !== 'missing')
  ? hash('sha256', $token)
  : hash('sha256', $order_id);

$ph_sha = $ph; // уже провалидирован выше

$user_data = array_filter([
  // ✅ phone hash (самое важное)
  'ph' => ($ph_sha !== '' ? [ $ph_sha ] : null),

  // ✅ external_id (sha256)
  'external_id' => [ $external_id_sha ],

  // ✅ fbp/fbc + ip/ua
  'fbp' => (is_array($lead) ? ($lead['fbp'] ?? null) : null),
  'fbc' => (is_array($lead) ? ($lead['fbc'] ?? null) : null),
  'client_ip_address' => (is_array($lead) ? ($lead['ip'] ?? null) : null),
  'client_user_agent' => (is_array($lead) ? ($lead['ua'] ?? null) : null),
], function($v){
  if ($v === null) return false;
  if (is_string($v) && trim($v) === '') return false;
  if (is_array($v) && empty($v)) return false;
  return true;
});

// ✅ чтобы в логах видеть, был ли phone hash в lead
log_meta_graph(
  "USERDATA order={$order_id} token={$token} has_ph=" . (isset($user_data['ph']) ? '1' : '0') .
  " has_fbp=" . (isset($user_data['fbp']) ? '1' : '0') .
  " has_fbc=" . (isset($user_data['fbc']) ? '1' : '0')
);

$event = array_filter([
  'event_name' => 'Purchase',
  'event_time' => time(),
  'event_id'   => $order_id, // дедуп на стороне Meta
  'action_source' => 'website',
  'event_source_url' => (is_array($lead) ? ($lead['page_url'] ?? null) : null) ?? envv('DEFAULT_EVENT_SOURCE_URL', ''),
  'user_data' => $user_data,
  'custom_data' => [
    'currency' => $currency,
    'value' => (float)$amount,
  ],
], function($v){
  if ($v === null) return false;
  if (is_string($v) && trim($v) === '') return false;
  if (is_array($v) && empty($v)) return false;
  return true;
});

$bodyArr = ['data' => [$event]];
if (!empty($TEST_EVENT_CODE)) $bodyArr['test_event_code'] = $TEST_EVENT_CODE;

$url = "https://graph.facebook.com/{$API_VERSION}/{$PIXEL_ID}/events?access_token=" . urlencode($ACCESS_TOKEN);
$safeUrl = "https://graph.facebook.com/{$API_VERSION}/{$PIXEL_ID}/events";

$did_retry = 0;

log_meta_graph("SEND event_id=" . ($event['event_id'] ?? 'NULL') .
  " event_name=" . ($event['event_name'] ?? 'NULL') .
  " test=" . (!empty($TEST_EVENT_CODE) ? '1' : '0') .
  " url={$safeUrl}");

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode($bodyArr, JSON_UNESCAPED_UNICODE),

  // чтобы не зависало и не превращалось в 499/502
  CURLOPT_CONNECTTIMEOUT => 3,
  CURLOPT_TIMEOUT => 8,
]);

$res = curl_exec($ch);
$err = curl_error($ch);
$errno = curl_errno($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$tt = curl_getinfo($ch, CURLINFO_TOTAL_TIME);

$timed_out = ($errno === 28) || (($code === 0) && $err && stripos($err, 'timed out') !== false);

if ($timed_out) {
  $did_retry = 1;
  log_meta_graph("RETRY after timeout...");

  curl_close($ch);
  usleep(200000); // 200ms

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($bodyArr, JSON_UNESCAPED_UNICODE),
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 8,
  ]);

  $res = curl_exec($ch);
  $err = curl_error($ch);
  $errno = curl_errno($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $tt = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
}

log_meta_graph("RESP http={$code} errno={$errno} err=" . ($err ?: '-') .
  " t={$tt} retry={$did_retry} body=" . substr((string)$res, 0, 800));

curl_close($ch);

// если Meta не принял — удаляем dedupeKey, чтобы WFP мог повторить вебхук
if ($err || $code < 200 || $code >= 300) {
  upstash_cmd(["DEL", $dedupeKey]);
  http_response_code(502);
  ok_json(['error' => 'meta failed', 'http' => $code, 'errno' => $errno, 'err' => $err, 'body' => substr((string)$res, 0, 800)]);
}

ok_json(['ok' => true]);