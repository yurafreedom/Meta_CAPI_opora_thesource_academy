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

if (!function_exists('log_meta_graph')) {
  function log_meta_graph($msg) {
    $line = date('c') . " " . $msg . "\n";
    @file_put_contents(__DIR__ . '/meta_graph.log', $line, FILE_APPEND);
  }
}

log_meta_graph("BOOT meta_purchase.php hit method=" . ($_SERVER['REQUEST_METHOD'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  j(405, ['error' => 'method not allowed', 'hint' => 'POST JSON to /meta_purchase.php']);
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

// вытащим токен (как источник external_id)
$token_raw =
  (is_array($lead) ? ($lead['token'] ?? null) : null)
  ?? ($p['token'] ?? null)
  ?? ($user_in['external_id'] ?? null);

$token_raw = is_string($token_raw) ? trim($token_raw) : null;

// external_id должен быть sha256
$external_id_sha = $token_raw ? hash('sha256', $token_raw) : null;

// Нормализуем поля под формат Meta CAPI
if (!empty($user_in['ph']) && is_string($user_in['ph'])) $user_in['ph'] = [ $user_in['ph'] ];
if (!empty($user_in['em']) && is_string($user_in['em'])) $user_in['em'] = [ $user_in['em'] ];

$user_data = array_filter([
  'ph' => isset($user_in['ph']) ? (is_array($user_in['ph']) ? $user_in['ph'] : [$user_in['ph']]) : null,
  'em' => isset($user_in['em']) ? (is_array($user_in['em']) ? $user_in['em'] : [$user_in['em']]) : null,
  'fbp' => $user_in['fbp'] ?? null,
  'fbc' => $user_in['fbc'] ?? null,

  // ✅ external_id: sha256, и как массив
  'external_id' => $external_id_sha ? [ $external_id_sha ] : null,

  // (опционально) юзер-агент, норм
  'client_user_agent' => $lead['ua'] ?? null,
  'client_ip_address' => $lead['ip'] ?? null,
], 'compact_filter');

if (empty($user_data)) {
  j(400, ['error' => 'missing user_data', 'need_one_of' => ['ph','em','fbp','fbc','external_id']]);
}

$event = array_filter([
  'event_name' => $event_name,
  'event_id' => $p['event_id'] ?? null,
  'event_time' => $event_time,
  'action_source' => 'website',
  'event_source_url' => $p['event_source_url'] ?? ($p['page_url'] ?? null) ?? ($lead['page_url'] ?? null),
  'user_data' => $user_data,
  'custom_data' => $p['custom_data'] ?? null,
], 'compact_filter');

$bodyArr = ['data' => [$event]];
if (!empty($TEST_EVENT_CODE)) $bodyArr['test_event_code'] = $TEST_EVENT_CODE;

$url = "https://graph.facebook.com/{$API_VERSION}/{$PIXEL_ID}/events?access_token=" . urlencode($ACCESS_TOKEN);

log_meta_graph("SEND event_id=" . ($event['event_id'] ?? 'NULL') .
  " event_name=" . ($event['event_name'] ?? 'NULL') .
  " test=" . (!empty($TEST_EVENT_CODE) ? '1' : '0') .
  " url=https://graph.facebook.com/{$API_VERSION}/{$PIXEL_ID}/events");

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode($bodyArr),
  CURLOPT_CONNECTTIMEOUT => 3,
  CURLOPT_TIMEOUT => 12,
]);

$res  = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$tt   = curl_getinfo($ch, CURLINFO_TOTAL_TIME);

$timed_out = ($code === 0) && $err && stripos($err, 'timed out') !== false;

if ($timed_out) {
  log_meta_graph("RETRY after timeout...");

  curl_close($ch);
  usleep(200000); // 200ms

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($bodyArr),
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 8,
  ]);

  $res  = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $tt   = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
}

log_meta_graph("RESP http={$code} err=" . ($err ?: '-') . " t={$tt} body=" . substr((string)$res, 0, 1500));

curl_close($ch);

// ✅ LOG what we sent + what Meta returned (safe preview)
file_put_contents(
  __DIR__.'/meta_graph.log',
  date('c')
  ." http=".$code
  ." err=".$err
  ." resp=".substr((string)$res, 0, 2000)
  ." sent=".substr(json_encode($bodyArr, JSON_UNESCAPED_UNICODE), 0, 1200)
  ."\n",
  FILE_APPEND
);

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