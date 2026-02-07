<?php
header('Content-Type: application/json');

// --- CORS ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header("Vary: Origin");
header("Access-Control-Allow-Methods: POST, OPTIONS, GET");
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

// (удобно) если открыл в браузере — покажем, что endpoint жив
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  j(200, ['ok' => true, 'hint' => 'POST JSON to this endpoint']);
}

$ACCESS_TOKEN    = getenv('META_CAPI_TOKEN');
$PIXEL_ID        = getenv('META_PIXEL_ID');
$TEST_EVENT_CODE = getenv('META_TEST_EVENT_CODE'); // optional
$API_VERSION     = 'v21.0';

if (!$ACCESS_TOKEN || !$PIXEL_ID) j(500, ['error' => 'Server misconfigured: missing token/pixel']);

$raw = file_get_contents('php://input');
if ($raw === false) $raw = '';
$p = json_decode($raw, true);
if (!is_array($p)) {
  j(400, [
    'error' => 'bad json',
    'json_error' => json_last_error_msg(),
    'raw_len' => strlen($raw),
    'raw_head' => substr($raw, 0, 200)
  ]);
}

// поддержка форматов:
// A) {event_name, event_time?, event_source_url?, user:{...}, custom_data?}
// B) {lead:{...}, page_url?, token?} -> Purchase по умолчанию
$lead = is_array($p['lead'] ?? null) ? $p['lead'] : null;

$event_name = $p['event_name'] ?? 'Purchase';
$event_time = !empty($p['event_time']) ? (int)$p['event_time'] : time();
if ($event_time < 1000000000) $event_time = time();

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

$user_data = array_filter([
  'ph' => $user_in['ph'] ?? null,
  'em' => $user_in['em'] ?? null,
  'fbp' => $user_in['fbp'] ?? null,
  'fbc' => $user_in['fbc'] ?? null,
  'external_id' => $user_in['external_id'] ?? null,
], 'compact_filter');

if (empty($user_data)) {
  j(400, [
    'error' => 'missing user_data (need at least ph/em/fbp/fbc/external_id)',
    'got' => $user_in
  ]);
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
  CURLOPT_POSTFIELDS => json_encode($bodyArr, JSON_UNESCAPED_UNICODE),

  // !!! КЛЮЧЕВОЕ: чтобы не висло и не убивалось Railway на 15с
  CURLOPT_CONNECTTIMEOUT => 4,
  CURLOPT_TIMEOUT => 10,
  CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
]);

$res  = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$info = curl_getinfo($ch);
curl_close($ch);

if ($err) {
  j(502, ['error' => 'curl_error', 'detail' => $err, 'curl_info' => $info, 'sent' => $bodyArr]);
}

if ($code < 200 || $code >= 300) {
  j($code ?: 502, ['error' => 'meta rejected', 'meta_http' => $code, 'meta_body' => $res, 'sent' => $bodyArr]);
}

http_response_code(200);
echo $res;