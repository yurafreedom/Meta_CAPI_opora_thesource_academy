<?php
header('Content-Type: application/json');

function out($c, $a){
  http_response_code($c);
  echo json_encode($a, JSON_UNESCAPED_UNICODE);
  exit;
}

function upstash_request($path, $method='GET', $jsonBody=null){
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
  return upstash_request('/lpush/' . rawurlencode($key), 'POST', [ $valueArr ]);
}

function upstash_get_raw($key){
  list($code, $resp) = upstash_request('/get/' . rawurlencode($key));
  if (!($code >= 200 && $code < 300)) return [$code, $resp, null];

  $data = json_decode($resp, true);
  $result = $data['result'] ?? null;   // string|null
  return [$code, $resp, $result];
}

function upstash_set($key, $value, $ttlSeconds = 60*60*24*30){
  list($code1, $resp1) = upstash_request('/set/' . rawurlencode($key) . '/' . rawurlencode($value));
  if (!($code1 >= 200 && $code1 < 300)) return [$code1, $resp1];

  list($code2, $resp2) = upstash_request('/expire/' . rawurlencode($key) . '/' . intval($ttlSeconds));
  if (!($code2 >= 200 && $code2 < 300)) return [$code2, $resp2];

  return [$code1, $resp1];
}

function try_json_decode($x){
  if ($x === null) return null;
  if (is_array($x) || is_object($x)) return $x;
  if (!is_string($x)) return null;

  $x = trim($x);
  if ($x === '') return null;

  $decoded = json_decode($x, true);
  if (json_last_error() === JSON_ERROR_NONE) return $decoded;

  return null;
}

/**
 * Нормализуем "lead" из Upstash:
 * варианты, которые реально встречаются:
 * 1) '{"token":"...","ph_sha256":"..."}'
 * 2) '[{"token":"...","ph_sha256":"..."}]'
 * 3) '["{\"token\":\"...\",\"ph_sha256\":\"...\"}"]'   <-- твой случай (двойное кодирование)
 * 4) ["{...}"] (после одного decode)
 */
function normalize_lead($raw){
  // raw — это string|null из Upstash result
  $v1 = try_json_decode($raw);

  // если не декодится — значит это не JSON вообще
  if ($v1 === null) return null;

  // если это массив и внутри 1-й элемент строка JSON — декодим ещё раз
  if (is_array($v1) && isset($v1[0]) && is_string($v1[0])) {
    $v2 = try_json_decode($v1[0]);
    if (is_array($v2)) {
      // если это массив объектов — берём первый
      if (isset($v2[0]) && is_array($v2[0])) return $v2[0];
      return $v2;
    }
  }

  // если это массив объектов — берём первый
  if (is_array($v1) && isset($v1[0]) && is_array($v1[0])) return $v1[0];

  // если это объект-массив — возвращаем как есть
  if (is_array($v1)) return $v1;

  return null;
}

// ---------------- debug logging ----------------
$DEBUG_ID = 'p_' . substr(bin2hex(random_bytes(8)), 0, 16);

function log_step($step, $data = [], $level = 'info'){
  global $DEBUG_ID;
  $row = [
    'debug_id' => $DEBUG_ID,
    'level' => $level,
    'step' => $step,
    'ts_server' => time(),
    'data' => $data
  ];
  // пишем в Upstash LIST purchase_logs
  upstash_lpush_json('purchase_logs', $row);
}

// ---------------- input (token) ----------------
// Нормальный вариант: ?token=...
$token = trim($_GET['token'] ?? '');

// Поддержка твоей ошибочной формы: /purchase.php?<uuid> (без token=)
if ($token === '' && count($_GET) === 1) {
  $keys = array_keys($_GET);
  $maybe = trim($keys[0]);
  // если ключ похож на uuid — принимаем как token
  if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $maybe)) {
    $token = $maybe;
  }
}

if ($token === '') {
  log_step('input', ['query' => $_SERVER['QUERY_STRING'] ?? ''], 'error');
  out(400, ['error' => 'bad token', 'debug_id' => $DEBUG_ID, 'hint' => 'use ?token=UUID']);
}

log_step('input', ['token' => $token]);

// 1) дедуп покупки
list($dc, $dr, $dedupRaw) = upstash_get_raw('purchase:' . $token);
log_step('dedup_check', ['key' => 'purchase:' . $token, 'http' => $dc, 'result' => $dedupRaw]);

if ($dedupRaw) {
  out(200, ['ok' => true, 'dedup' => true, 'debug_id' => $DEBUG_ID]);
}

// 2) достаём лид из Upstash
list($lc, $lr, $leadRaw) = upstash_get_raw('lead:' . $token);
log_step('lead_get_raw', [
  'key' => 'lead:' . $token,
  'http' => $lc,
  'has_result' => $leadRaw !== null,
  'result_preview' => is_string($leadRaw) ? substr($leadRaw, 0, 200) : $leadRaw
]);

$lead = normalize_lead($leadRaw);
log_step('lead_normalized', [
  'ok' => is_array($lead),
  'type' => gettype($lead),
  'keys' => is_array($lead) ? array_keys($lead) : null,
  'sample0' => (is_array($lead) && isset($lead[0])) ? (is_string($lead[0]) ? substr($lead[0],0,120) : $lead[0]) : null
]);

if (!$lead) {
  out(404, [
    'error' => 'lead not found',
    'debug_id' => $DEBUG_ID,
    'upstash' => ['http' => $lc, 'raw_preview' => is_string($leadRaw) ? substr($leadRaw, 0, 200) : $leadRaw]
  ]);
}

// 2.1) ph_sha256 — берём как есть
$ph_sha256 = strtolower(trim($lead['ph_sha256'] ?? ''));

// запасной вариант: если вдруг сохранилось как lead->lead->ph_sha256
if ($ph_sha256 === '' && isset($lead['lead']) && is_array($lead['lead'])) {
  $ph_sha256 = strtolower(trim($lead['lead']['ph_sha256'] ?? ''));
}

if ($ph_sha256 === '') {
  log_step('lead_missing_ph_sha256', ['lead' => $lead], 'error');
  out(400, ['error' => 'no ph_sha256 in lead', 'debug_id' => $DEBUG_ID]);
}

log_step('lead_ok', ['ph_sha256_prefix' => substr($ph_sha256, 0, 10) . '...']);

// 3) собираем payload
$value = (float)($_GET['value'] ?? 1);
$currency = (string)($_GET['currency'] ?? 'UAH');

$payload = [
  'event_name' => 'Purchase',
  'event_time' => time(),
  'event_source_url' => $lead['page_url'] ?? ($_GET['u'] ?? ''),
  'lead' => [
    'ph_sha256' => $ph_sha256,
    'token' => $token,
    'value' => $value,
    'currency' => $currency
  ],
  'custom_data' => ['value' => $value, 'currency' => $currency],
];

log_step('payload_built', [
  'event_source_url' => $payload['event_source_url'],
  'value' => $value,
  'currency' => $currency
]);

// 4) вызываем meta-capi.php
$metaUrl = getenv('META_CAPI_URL');
if (!$metaUrl) {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? '';
  $metaUrl = $scheme . '://' . $host . '/meta-capi.php';
}

log_step('meta_call_start', ['meta_url' => $metaUrl]);

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

log_step('meta_call_end', [
  'http' => $http,
  'err' => $err ? $err : null,
  'resp_preview' => is_string($res) ? substr($res, 0, 300) : $res
], ($err || $http < 200 || $http >= 300) ? 'error' : 'info');

if ($err) out(500, ['error' => $err, 'debug_id' => $DEBUG_ID]);
if ($http < 200 || $http >= 300) out($http ?: 500, ['error' => 'meta failed', 'resp' => $res, 'debug_id' => $DEBUG_ID]);

// 5) ставим дедуп-флаг
upstash_set('purchase:' . $token, '1', 60*60*24*30);
log_step('dedup_set', ['key' => 'purchase:' . $token]);

out(200, ['ok' => true, 'debug_id' => $DEBUG_ID, 'meta' => $res]);