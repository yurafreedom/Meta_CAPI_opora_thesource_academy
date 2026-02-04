<?php
// meta-capi.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}
header('Content-Type: application/json');

$ACCESS_TOKEN = getenv('META_CAPI_TOKEN');   // задай на сервере
$PIXEL_ID     = getenv('META_PIXEL_ID');     // задай на сервере
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

$event = [
  'event_name' => $payload['event_name'],
  'event_time' => time(),
  'action_source' => 'website',
  'event_source_url' => $payload['event_source_url'] ?? null,
  'user_data' => array_filter([
    'em'  => $payload['user']['em']  ?? null, // hashed sha256 hex
    'ph'  => $payload['user']['ph']  ?? null, // <-- добавили phone
    'fn'  => $payload['user']['fn']  ?? null, // hashed sha256 hex, если используешь
    'fbp' => $payload['user']['fbp'] ?? null,
    'fbc' => $payload['user']['fbc'] ?? null,
    'external_id' => $payload['user']['external_id'] ?? null,
  ]),
  'custom_data' => $payload['custom_data'] ?? null,
];

$body = json_encode(['data' => [$event]]);

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