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

// ---------- helpers ----------

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

// Декодим JSON “в глубину”: когда result = строка, внутри которой ещё JSON строка и т.д.
function deep_decode($x, $max = 3){
  $cur = $x;
  for ($i=0; $i<$max; $i++){
    $d = try_json_decode($cur);
    if ($d === null) break;

    // если декодирование вернуло строку, и она похожа на JSON — пробуем дальше
    if (is_string($d)) {
      $s = trim($d);
      if ($s === '' || ($s[0] !== '{' && $s[0] !== '[')) return $d;
      $cur = $d;
      continue;
    }

    return $d;
  }
  return is_string($cur) ? $cur : null;
}

/**
 * Нормализуем "lead" из Upstash:
 * встречающиеся варианты:
 * 1) '{"token":"...","ph_sha256":"..."}'
 * 2) '[{"token":"...","ph_sha256":"..."}]'
 * 3) '["{\"token\":\"...\",\"ph_sha256\":\"...\"}"]'   <-- двойное кодирование
 * 4) '"[{\"token\":\"...\"}]"' <-- ещё один уровень строкой
 */
function normalize_lead($raw){
  $v1 = deep_decode($raw, 3);

  if ($v1 === null) return null;

  // Если после deep_decode всё ещё строка — пробуем ещё раз обычным decode
  if (is_string($v1)) {
    $v1b = try_json_decode($v1);
    if ($v1b !== null) $v1 = $v1b;
  }

  // Массив со строкой JSON в [0]
  if (is_array($v1) && isset($v1[0]) && is_string($v1[0])) {
    $v2 = deep_decode($v1[0], 2);
    if (is_array($v2)) {
      if (isset($v2[0]) && is_array($v2[0])) return $v2[0];
      return $v2;
    }
  }

  // Массив объектов
  if (is_array($v1) && isset($v1[0]) && is_array($v1[0])) return $v1[0];

  // Обычный объект
  if (is_array($v1)) return $v1;

  return null;
}

function detect_scheme() {
  $xfp = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
  if ($xfp) {
    // может быть "https,http"
    $xfp = strtolower(trim(explode(',', $xfp)[0]));
    if ($xfp === 'https') return 'https';
    if ($xfp === 'http')  return 'http';
  }

  $xfs = strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '');
  if ($xfs === 'on') return 'https';

  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return 'https';
  if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return 'https';

  return 'http';
}

function detect_host() {
  // иногда прокси передаёт X-Forwarded-Host
  $xfh = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '';
  if ($xfh) return trim(explode(',', $xfh)[0]);

  return $_SERVER['HTTP_HOST'] ?? '';
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
  upstash_lpush_json('purchase_logs', $row);
}

// ---------------- input (token) ----------------
$token = trim($_GET['token'] ?? '');

// Поддержка /purchase.php?<uuid> (без token=)
if ($token === '' && count($_GET) === 1) {
  $keys = array_keys($_GET);
  $maybe = trim($keys[0]);
  if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $maybe)) {
    $token = $maybe;
  }
}

if ($token === '') {
  log_step('input', ['query' => $_SERVER['QUERY_STRING'] ?? ''], 'error');
  out(400, ['error' => 'bad token', 'debug_id' => $DEBUG_ID, 'hint' => 'use ?token=UUID']);
}

log_step('input', [
  'token' => $token,
  'query' => $_SERVER['QUERY_STRING'] ?? ''
]);

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
  'result_preview' => is_string($leadRaw) ? substr($leadRaw, 0, 220) : $leadRaw
]);

$lead = normalize_lead($leadRaw);
log_step('lead_normalized', [
  'ok' => is_array($lead),
  'type' => gettype($lead),
  'keys' => is_array($lead) ? array_keys($lead) : null,
  'sample' => is_array($lead) ? array_slice($lead, 0, 3, true) : null
]);

if (!$lead) {
  out(404, [
    'error' => 'lead not found',
    'debug_id' => $DEBUG_ID,
    'upstash' => ['http' => $lc, 'raw_preview' => is_string($leadRaw) ? substr($leadRaw, 0, 220) : $leadRaw]
  ]);
}

// 2.1) ph_sha256
$ph_sha256 = strtolower(trim($lead['ph_sha256'] ?? ''));

// запасной вариант: lead->lead->ph_sha256
if ($ph_sha256 === '' && isset($lead['lead']) && is_array($lead['lead'])) {
  $ph_sha256 = strtolower(trim($lead['lead']['ph_sha256'] ?? ''));
}

if ($ph_sha256 === '') {
  log_step('lead_missing_ph_sha256', ['lead' => $lead], 'error');
  out(400, ['error' => 'no ph_sha256 in lead', 'debug_id' => $DEBUG_ID]);
}

log_step('lead_ok', ['ph_sha256_prefix' => substr($ph_sha256, 0, 10) . '...']);

// 3) payload
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

// 4) meta-capi.php
// 4) SEND DIRECTLY TO META GRAPH (NO self-call)

$ACCESS_TOKEN    = getenv('META_CAPI_TOKEN');
$PIXEL_ID        = getenv('META_PIXEL_ID');
$TEST_EVENT_CODE = getenv('META_TEST_EVENT_CODE');
$API_VERSION     = 'v21.0';

if (!$ACCESS_TOKEN || !$PIXEL_ID) {
  out(500, ['error' => 'missing META_CAPI_TOKEN or META_PIXEL_ID', 'debug_id' => $DEBUG_ID]);
}

// user_data (Meta expects ph/em as arrays)
$user_data = array_filter([
  'ph' => $ph_sha256 ? [$ph_sha256] : null,
  'external_id' => $token ? [$token] : null,
  'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
], function($v){
  if ($v === null) return false;
  if (is_string($v) && trim($v) === '') return false;
  if (is_array($v) && count($v) === 0) return false;
  return true;
});

$event = array_filter([
  'event_name' => 'Purchase',
  'event_time' => time(),
  'action_source' => 'website',
  'event_source_url' => $payload['event_source_url'] ?? '',
  'user_data' => $user_data,
  'custom_data' => [
    'currency' => $currency,
    'value' => $value,
  ],
], function($v){
  if ($v === null) return false;
  if (is_string($v) && trim($v) === '') return false;
  return true;
});

$bodyArr = ['data' => [$event]];
if (!empty($TEST_EVENT_CODE)) $bodyArr['test_event_code'] = $TEST_EVENT_CODE;

$url = "https://graph.facebook.com/{$API_VERSION}/{$PIXEL_ID}/events?access_token=" . urlencode($ACCESS_TOKEN);

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode($bodyArr, JSON_UNESCAPED_UNICODE),

  // важно уложиться в лимит edge (15s)
  CURLOPT_CONNECTTIMEOUT => 5,
  CURLOPT_TIMEOUT => 12,

  // стабильнее на некоторых прокси/edge
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,

  // чтобы не было сюрпризов с компрессией
  CURLOPT_ACCEPT_ENCODING => '',
]);

$res  = curl_exec($ch);
$errno = curl_errno($ch);
$err  = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
$eff  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

log_step('meta_graph', [
  'http' => $http,
  'errno' => $errno,
  'err' => $err ?: null,
  'time' => $time,
  'effective_url' => $eff,
  'resp_preview' => is_string($res) ? substr($res, 0, 500) : $res,
  'sent_preview' => substr(json_encode($bodyArr, JSON_UNESCAPED_UNICODE), 0, 500),
], (($errno > 0) || $http < 200 || $http >= 300) ? 'error' : 'info');

if ($errno > 0) out(500, [
  'error' => 'curl_errno',
  'errno' => $errno,
  'message' => $err,
  'debug_id' => $DEBUG_ID,
  'meta_http' => $http ?: 0,
  'effective_url' => $eff,
  'time' => $time,
]);

if ($http < 200 || $http >= 300) out($http ?: 500, [
  'error' => 'meta rejected',
  'debug_id' => $DEBUG_ID,
  'meta_http' => $http,
  'meta_body' => $res,
  'sent_preview' => substr(json_encode($bodyArr, JSON_UNESCAPED_UNICODE), 0, 500),
]);