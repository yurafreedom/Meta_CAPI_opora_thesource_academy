<?php
header('Content-Type: application/json');

function out($c, $a){
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

function upstash_lpush_json($key, $valueArr){
  // Upstash REST LPUSH: POST body must be [value]
  return upstash_request('/lpush/' . rawurlencode($key), 'POST', [ $valueArr ]);
}

function upstash_get_json($key){
  list($code, $resp) = upstash_request('/get/' . rawurlencode($key));
  if (!($code >= 200 && $code < 300)) return [$code, $resp, null];

  $data = json_decode($resp, true);
  $result = $data['result'] ?? null;
  if ($result === null) return [$code, $resp, null];

  // result is usually stringified JSON
  $decoded = is_string($result) ? json_decode($result, true) : $result;
  return [$code, $resp, $decoded];
}

function upstash_set($key, $value, $ttlSeconds = 60*60*24*14){
  list($code1, $resp1) = upstash_request('/set/' . rawurlencode($key) . '/' . rawurlencode($value));
  if (!($code1 >= 200 && $code1 < 300)) return [$code1, $resp1];

  list($code2, $resp2) = upstash_request('/expire/' . rawurlencode($key) . '/' . intval($ttlSeconds));
  if (!($code2 >= 200 && $code2 < 300)) return [$code2, $resp2];

  return [$code1, $resp1];
}

// ---- debug logging ----
$DEBUG_ID = 'p_' . substr(bin2hex(random_bytes(8)), 0, 16);
$token = ''; // объявим заранее, чтобы log_step не ругался

function log_step($step, $data = [], $level = 'info'){
  global $DEBUG_ID, $token;

  $row = [
    'debug_id' => $DEBUG_ID,
    'level' => $level,
    'step' => $step,
    'token' => $token ?? '',
    'ts_server' => time(),
    'data' => $data
  ];

  // пишем в Upstash LIST purchase_logs
  upstash_lpush_json('purchase_logs', $row);
}

// -------- input --------
$token = trim($_GET['token'] ?? '');
if ($token === '') {
  // лог даже для bad token полезен
  log_step('0_input_bad_token', ['query' => $_GET], 'error');
  out(400, ['error'=>'bad token', 'debug_id'=>$DEBUG_ID]);
}

log_step('0_input_ok', [
  'token' => $token,
  'u' => (string)($_GET['u'] ?? ''),
  'value' => (string)($_GET['value'] ?? ''),
  'currency' => (string)($_GET['currency'] ?? '')
]);

// 1) дедуп покупки
log_step('1_dedup_check_start', ['key' => 'purchase:' . $token]);

list($dc, $dr, $already) = upstash_get_json('purchase:' . $token);

log_step('1_dedup_check_result', [
  'http' => $dc,
  'already' => (bool)$already,
  'raw' => is_string($dr) ? mb_substr($dr, 0, 400) : $dr,
]);

if ($already) {
  out(200, ['ok'=>true, 'dedup'=>true, 'debug_id'=>$DEBUG_ID]);
}

// 2) достаём лид из Upstash
log_step('2_get_lead_start', ['key' => 'lead:' . $token]);

list($code, $raw, $lead) = upstash_get_json('lead:' . $token);

log_step('2_get_lead_result', [
  'http' => $code,
  'has_lead' => (bool)$lead,
  'raw' => is_string($raw) ? mb_substr($raw, 0, 700) : $raw,
]);

if (!$lead) {
  log_step('2_get_lead_failed', ['reason'=>'lead_not_found'], 'error');
  out(404, ['error'=>'lead not found', 'debug_id'=>$DEBUG_ID, 'upstash'=>[$code, is_string($raw)?mb_substr($raw,0,500):$raw]]);
}

// иногда lead мог сохраниться как массив [ {..} ] — нормализуем
if (is_array($lead) && isset($lead[0]) && is_array($lead[0])) {
  log_step('2_lead_normalized_from_array');
  $lead = $lead[0];
}

// проверка ph_sha256
$ph_sha256 = strtolower(trim($lead['ph_sha256'] ?? ''));
log_step('2_ph_sha256_check', [
  'present' => ($ph_sha256 !== ''),
  'lead_keys' => is_array($lead) ? array_keys($lead) : null
]);

if ($ph_sha256 === '') {
  log_step('2_ph_sha256_missing', ['lead' => $lead], 'error');
  out(400, ['error'=>'no ph_sha256 in lead', 'debug_id'=>$DEBUG_ID]);
}

// 3) собираем payload
$value = (float)($_GET['value'] ?? 1);
$currency = (string)($_GET['currency'] ?? 'UAH');

$event_source_url = $lead['page_url'] ?? ($_GET['u'] ?? '');

$payload = [
  'event_name' => 'Purchase',
  'event_time' => time(),
  'event_source_url' => $event_source_url,
  'lead' => [
    'ph_sha256' => $ph_sha256,
    'token' => $token,
    'value' => $value,
    'currency' => $currency
  ],
  'custom_data' => ['value'=>$value, 'currency'=>$currency],
];

log_step('3_payload_built', [
  'event_source_url' => $event_source_url,
  'value' => $value,
  'currency' => $currency
]);

// 4) вызываем meta-capi.php
$metaUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}/meta-capi.php";
log_step('4_meta_call_start', ['metaUrl' => $metaUrl]);

$ch = curl_init($metaUrl);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT => 12,
]);
$res = curl_exec($ch);
$err = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

log_step('4_meta_call_result', [
  'http' => $http,
  'curl_err' => $err ?: null,
  'resp' => is_string($res) ? mb_substr($res, 0, 900) : $res
], ($err || $http < 200 || $http >= 300) ? 'error' : 'info');

if ($err) out(500, ['error'=>$err, 'debug_id'=>$DEBUG_ID]);
if ($http < 200 || $http >= 300) out($http ?: 500, ['error'=>'meta failed', 'resp'=>$res, 'debug_id'=>$DEBUG_ID]);

// 5) ставим дедуп-флаг
log_step('5_dedup_set_start', ['key' => 'purchase:' . $token]);

list($sc, $sr) = upstash_set('purchase:' . $token, '1', 60*60*24*30);

log_step('5_dedup_set_result', [
  'http' => $sc,
  'raw' => is_string($sr) ? mb_substr($sr, 0, 300) : $sr
], ($sc < 200 || $sc >= 300) ? 'error' : 'info');

out(200, ['ok'=>true, 'debug_id'=>$DEBUG_ID, 'meta'=>$res]);