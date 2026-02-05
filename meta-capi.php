<?php
// meta-capi.php
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

$ACCESS_TOKEN = getenv('META_CAPI_TOKEN');
$PIXEL_ID     = getenv('META_PIXEL_ID');
$TEST_EVENT_CODE = getenv('META_TEST_EVENT_CODE'); // TEST71361
$API_VERSION  = 'v21.0';

if (!$ACCESS_TOKEN || !$PIXEL_ID) j(500, ['error' => 'Server misconfigured: missing token/pixel']);

$raw = file_get_contents('php://input');
$p = json_decode($raw, true);
if (!is_array($p)) j(400, ['error' => 'bad json']);

// поддерживаем оба формата:
// A) прямой meta payload: {event_name, user:{ph,...}, event_source_url,...}
// B) our format: {event_name?, lead:{ph_sha256,...}, page_url?}
$event_name = $p['event_name'] ?? null;
$lead = is_array($p['lead'] ?? null) ? $p['lead'] : null;

if (!$event_name) $event_name = 'Purchase'; // для теста в Events Manager
$event_time = !empty($p['event_time']) ? (int)$p['event_time'] : time();
if ($event_time < 1000000000) $event_time = time();

$user_data = [];
if (is_array($p['user'] ?? null)) {
  $user_data = $p['user'];
} elseif ($lead) {
  $user_data = [
    'ph' => $lead['ph_sha256'] ?? null,
    'external_id' => $lead['token'] ?? ($p['token'] ?? null),
  ];
}

$user_data = array_filter([
  'ph' => $user_data['ph'] ?? null,
  'em' => $user_data['em'] ?? null,
  'fbp' => $user_data['fbp'] ?? null,
  'fbc' => $user_data['fbc'] ?? null,
  'external_id' => $user_data['external_id'] ?? null,
], 'compact_filter');

if (empty($user_data)) j(400, ['error' => 'missing user_data (need at least ph/em/fbp/fbc/external_id)']);

$event = array_filter([
  'event_name' => $event_name,
  'event_time' => $event_time,
  'action_source' => 'website',
  'event_source_url' => $p['event_source_url'] ?? ($p['page_url'] ?? null) ?? ($lead['page_url'] ?? null),
  'user_data' => $user_data,
  'custom_data' => $p['custom_data'] ?? array_filter([
    'currency' => $lead['currency'] ?? null,
    'value' => $lead['value'] ?? null,
  ], 'compact_filter'),
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
]);

$res = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) j(500, ['error' => $err]);

http_response_code($code);
echo $res;