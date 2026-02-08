<?php
header('Content-Type: application/json');

// --- CORS ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header("Vary: Origin");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 86400");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
// --- /CORS ---

function j($code, $arr) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function compact_filter($v) {
  if ($v === null) return false;
  if (is_string($v) && trim($v) === '') return false;
  return true;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  j(405, ['error' => 'method not allowed', 'hint' => 'POST JSON to /meta-capi.php']);
}

$ACCESS_TOKEN    = getenv('META_CAPI_TOKEN');
$PIXEL_ID        = getenv('META_PIXEL_ID');
$TEST_EVENT_CODE = getenv('META_TEST_EVENT_CODE');
$API_VERSION     = 'v21.0';

if (!$ACCESS_TOKEN || !$PIXEL_ID) {
  j(500, [
    'error' => 'Server misconfigured: missing token/pixel',
    'has_token' => (bool)$ACCESS_TOKEN,
    'has_pixel' => (bool)$PIXEL_ID
  ]);
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || trim($raw) === '') {
  j(400, ['error' => 'bad json', 'hint' => 'empty body']);
}

$p = json_decode($raw, true);
if (!is_array($p)) {
  j(400, [
    'error' => 'bad json',
    'json_error' => json_last_error_msg(),
    'raw_preview' => substr($raw, 0, 300)
  ]);
}

$lead = is_array($p['lead'] ?? null) ? $p['lead'] : null;

$event_name = $p['event_name'] ?? 'Purchase';
$event_time = !empty($p['event_time']) ? (int)$p['event_time'] : time();
$now = time();
if ($event_time < 1000000000 || $event_time > $now + 600 || $event_time < $now - 7*24*3600) {
  $event_time = $now;
}

$user_in = is_array($p['user'] ?? null) ? $p['user'] : [];
if (empty($user_in) && $lead) {
  $user_in = [
    'ph' => $lead['ph_sha256'] ?? null,
    'em' => $lead['em_sha256'] ?? null,
    'fbp' => $lead['fbp'] ?? null,
    'fbc' => $lead['fbc'] ?? null,
    'external_id' => $lead['token'] ?? ($p['token'] ?? null),
  ];
}

// Нормализуем поля под формат Meta CAPI
if (!empty($user_in['ph']) && is_string($user_in['ph'])) $user_in['ph'] = [ $user_in['ph'] ];
if (!empty($user_in['em']) && is_string($user_in['em'])) $user_in['em'] = [ $user_in['em'] ];

$user_data = array_filter([
  'ph' => isset($user_in['ph']) ? (is_array($user_in['ph']) ? $user_in['ph'] : [$user_in['ph']]) : null,
  'em' => isset($user_in['em']) ? (is_array($user_in['em']) ? $user_in['em'] : [$user_in['em']]) : null,
  'fbp' => $user_in['fbp'] ?? null,
  'fbc' => $user_in['fbc'] ?? null,
  'external_id' => $user_in['external_id'] ?? null,
], 'compact_filter');

if (empty($user_data)) {
  j(400, ['error' => 'missing user_data', 'need_one_of' => ['ph','em','fbp','fbc','external_id']]);
}

$event = array_filter([
  'event_name' => $event_name,
  'event_time' => $event_time,
  'action_source' => 'website',
  'event_source_url' => $p['event_source_url'] ?? ($p['page_url'] ?? null) ?? ($lead['page_url'] ?? null),
  'user_data' => $user_data,
  'custom_data' => $p['custom_data'] ?? null,
], 'compact_filter');

$bodyArr = ['data' => [$event]];
if (!empty($TEST_EVENT_CODE)) $bodyArr['test_event_code'] = $TEST_EVENT_CODE;

$url = "https://graph.facebook.com/{$API_VERSION}/{$PIXEL_ID}/events?access_token=" . urlencode($ACCESS_TOKEN);

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode($bodyArr),

  // ВАЖНО: чтобы не было зависаний -> 499/timeout
  CURLOPT_CONNECTTIMEOUT => 5,
  CURLOPT_TIMEOUT => 10,
]);

$res  = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

$meta_json = null;
if (is_string($res) && $res !== '') {
  $meta_json = json_decode($res, true);
  if (json_last_error() !== JSON_ERROR_NONE) $meta_json = null;
}

curl_close($ch);

if ($err) j(500, ['error' => 'curl_error', 'message' => $err]);

if ($err) {
  j(500, ['error' => 'curl_error', 'message' => $err]);
}

if ($code < 200 || $code >= 300 || isset($meta_json['error'])) {
  j($code ?: 500, [
    'error' => 'meta rejected',
    'meta_http' => $code,
    'meta_body' => $res,
    'meta_json' => $meta_json,

    // ВАЖНО: не логируем весь payload
    'sent_preview' => substr(json_encode($bodyArr, JSON_UNESCAPED_UNICODE), 0, 500)
  ]);
}

j(200, [
  'ok' => true,
  'meta_http' => $code,
  'meta_body' => $res,
  'meta_json' => $meta_json
]);