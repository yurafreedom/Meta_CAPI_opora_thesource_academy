<?php
header('Content-Type: application/json');

function out($c,$a){
  http_response_code($c);
  echo json_encode($a, JSON_UNESCAPED_UNICODE);
  exit;
}

function upstash_request($path, $method = 'GET', $jsonBody = null){
  $url   = getenv('UPSTASH_REDIS_REST_URL');
  $token = getenv('UPSTASH_REDIS_REST_TOKEN');
  if (!$url || !$token) return [0, 'missing_upstash_env'];

  $ch = curl_init(rtrim($url, '/') . $path);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
  ]);

  if ($method === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    if ($jsonBody !== null) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_UNESCAPED_UNICODE));
    }
  }

  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  return [$code, $resp];
}

function upstash_get_value($key){
  list($code, $resp) = upstash_request('/get/' . rawurlencode($key));
  if (!($code >= 200 && $code < 300)) return [$code, $resp, null];

  $j = json_decode($resp, true);
  // Upstash обычно возвращает {"result": "..."} или {"result": null}
  $val = is_array($j) && array_key_exists('result', $j) ? $j['result'] : null;
  return [$code, $resp, $val];
}

function upstash_exists($key){
  list($code, $resp) = upstash_request('/exists/' . rawurlencode($key));
  if (!($code >= 200 && $code < 300)) return [$code, $resp, false];
  $j = json_decode($resp, true);
  $n = is_array($j) && array_key_exists('result', $j) ? intval($j['result']) : 0;
  return [$code, $resp, $n > 0];
}

function upstash_set_ttl($key, $value, $ttlSeconds){
  list($code, $resp) = upstash_request('/set/' . rawurlencode($key) . '/' . rawurlencode($value));
  if (!($code >= 200 && $code < 300)) return [$code, $resp];
  list($code2, $resp2) = upstash_request('/expire/' . rawurlencode($key) . '/' . intval($ttlSeconds));
  if (!($code2 >= 200 && $code2 < 300)) return [$code2, $resp2];
  return [$code, $resp];
}

// ---- input ----
$token = trim($_GET['token'] ?? '');
if ($token === '') out(400, ['error'=>'bad token']);

// 1) дедуп (чтобы /thanks можно было безопасно перезагружать)
$dedupKey = 'purchase_done:' . $token;
list($ec, $er, $exists) = upstash_exists($dedupKey);
if ($exists) out(200, ['ok'=>true, 'dedup'=>true]);

// 2) достаем лид из Upstash
$leadKey = 'lead:' . $token;
list($gc, $gr, $leadRaw) = upstash_get_value($leadKey);
if (!($gc >= 200 && $gc < 300)) out(500, ['error'=>'upstash_get_failed', 'debug'=>[$gc,$gr]]);
if ($leadRaw === null || $leadRaw === '') out(404, ['error'=>'lead not found']);

// leadRaw — строка с JSON
$lead = json_decode($leadRaw, true);

// иногда может быть ситуация двойного JSON (если где-то уже сохраняли массив как строку в строке)
if (!is_array($lead)) {
  $tmp = json_decode($leadRaw, true);
  if (is_array($tmp)) $lead = $tmp;
}
if (!is_array($lead)) out(500, ['error'=>'corrupt lead', 'raw'=>$leadRaw]);

$ph_sha256 = strtolower(trim($lead['ph_sha256'] ?? ''));
if ($ph_sha256 === '' || !preg_match('/^[a-f0-9]{64}$/', $ph_sha256)) {
  out(400, ['error'=>'no ph_sha256 in lead']);
}

// 3) шлем Purchase в meta-capi.php
$payload = [
  'event_name' => 'Purchase',
  'event_time' => time(),
  'event_source_url' => $lead['page_url'] ?? ($_GET['u'] ?? ''),
  'event_id' => $token, // дедуп в Meta
  'lead' => [
    'ph_sha256' => $ph_sha256,
    'token' => $token,
    'value' => 1,
    'currency' => 'UAH'
  ],
  'custom_data' => ['value'=>1, 'currency'=>'UAH'],
];

$metaUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}/meta-capi.php";

$ch = curl_init($metaUrl);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT => 10,
]);
$res = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) out(500, ['error'=>$err]);
if ($code < 200 || $code >= 300) out($code ?: 500, ['error'=>'meta failed', 'resp'=>$res]);

// 4) ставим дедуп-маркер в Upstash на 30 дней
upstash_set_ttl($dedupKey, '1', 60*60*24*30);

http_response_code(200);
echo $res;