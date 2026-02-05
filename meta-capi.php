<?php
// meta-capi.php

// --- CORS ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

// если хочешь строго — раскомментируй allowlist:
// $allowed = ['https://opora.thesource.academy', 'https://www.opora.thesource.academy'];
// if (in_array($origin, $allowed, true)) {
//   header("Access-Control-Allow-Origin: $origin");
// } else {
//   header("Access-Control-Allow-Origin: https://opora.thesource.academy");
// }

header("Access-Control-Allow-Origin: $origin");
header("Vary: Origin");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 86400");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}
// --- /CORS ---

header('Content-Type: application/json');

$ACCESS_TOKEN = getenv('META_CAPI_TOKEN');   // set on server
$PIXEL_ID     = getenv('META_PIXEL_ID');     // set on server
$API_VERSION  = 'v21.0';

if (!$ACCESS_TOKEN || !$PIXEL_ID) {
  http_response_code(500);
  echo json_encode(['error' => 'Server misconfigured: missing token/pixel']);
  exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!$payload || empty($payload['event_name'])) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid payload']);
  exit;
}

// helper: remove null/empty string but keep 0/"0"/false if ever needed
function compact_filter($v) {
  if ($v === null) return false;
  if (is_string($v) && trim($v) === '') return false;
  return true;
}

// event_time: accept from client (seconds), fallback to now
$event_time = !empty($payload['event_time']) ? intval($payload['event_time']) : time();
if ($event_time < 1000000000) { // safety: if someone sent ms or garbage
  $event_time = time();
}

$user = is_array($payload['user'] ?? null) ? $payload['user'] : [];

$user_data = array_filter([
  'em'  => $user['em']  ?? null, // sha256 hex
  'ph'  => $user['ph']  ?? null, // sha256 hex
  'fn'  => $user['fn']  ?? null, // sha256 hex
  'fbp' => $user['fbp'] ?? null,
  'fbc' => $user['fbc'] ?? null,
  'external_id' => $user['external_id'] ?? null,
], 'compact_filter');

// build event and drop nulls (incl. event_source_url/custom_data if not provided)
$event = array_filter([
  'event_name' => $payload['event_name'],
  'event_time' => $event_time,
  'action_source' => 'website',
  'event_source_url' => $payload['event_source_url'] ?? null,
  'user_data' => $user_data,
  'custom_data' => $payload['custom_data'] ?? null,
], 'compact_filter');

// require at least some user_data (otherwise Meta часто режет пользу)
if (empty($event['user_data'])) {
  http_response_code(400);
  echo json_encode(['error' => 'Missing user_data (need at least ph/em/fbp/fbc/external_id)']);
  exit;
}

$bodyArr = ['data' => [$event]];

// TEST MODE (optional)
$TEST_EVENT_CODE = getenv('META_TEST_EVENT_CODE'); // e.g. TEST71361
if (!empty($TEST_EVENT_CODE)) {
  $bodyArr['test_event_code'] = $TEST_EVENT_CODE;
}

$body = json_encode($bodyArr);

$url = "https://graph.facebook.com/{$API_VERSION}/{$PIXEL_ID}/events?access_token=" . urlencode($ACCESS_TOKEN);

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => $body,
]);

$res = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) {
  http_response_code(500);
  echo json_encode(['error' => $err]);
  exit;
}

http_response_code($code);
echo $res;