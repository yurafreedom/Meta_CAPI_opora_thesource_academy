<?php
/**
 * meta_purchase.php — отправка события в Meta CAPI
 * Ожидает POST JSON:
 * {
 *   "event_name": "Purchase",
 *   "event_time": 1700000000,
 *   "event_id": "abc123",
 *   "event_source_url": "https://...",
 *   "token": "lead_token_optional",
 *   "name": "Full Name optional",
 *   "user": { "ph": "...", "em": "...", "fbp": "...", "fbc": "...", "external_id": "..." },
 *   "lead": { ... } // обычно то, что ты достал из Upstash: lead:$token
 *   "custom_data": { ... }
 * }
 */

file_put_contents(__DIR__ . '/BOOT.txt', date('c') . " HIT\n", FILE_APPEND);

header('Content-Type: application/json; charset=utf-8');

// --- CORS ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header("Vary: Origin");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 86400");
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
// --- /CORS ---

function j(int $code, array $arr): void {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function compact_filter($v): bool {
  if ($v === null) return false;
  if (is_string($v) && trim($v) === '') return false;
  if (is_array($v) && empty($v)) return false;
  return true;
}

if (!function_exists('log_meta_graph')) {
  function log_meta_graph(string $msg): void {
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
$API_VERSION     = getenv('META_API_VERSION') ?: 'v21.0';

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

$lead = is_array($p['lead'] ?? null) ? $p['lead'] : [];
$user_in = is_array($p['user'] ?? null) ? $p['user'] : [];

// --- event name/time ---
$event_name = is_string($p['event_name'] ?? null) && $p['event_name'] !== '' ? $p['event_name'] : 'Purchase';

$event_time = !empty($p['event_time']) ? (int)$p['event_time'] : time();
$now = time();
// допускаем 7 дней назад, и +10 минут вперёд
if ($event_time < 1000000000 || $event_time > $now + 600 || $event_time < $now - 7*24*3600) {
  $event_time = $now;
}

// --- если user не передали, поднимем из lead ---
if (empty($user_in) && !empty($lead)) {
  $user_in = [
    'ph' => $lead['ph_sha256'] ?? null,
    'em' => $lead['em_sha256'] ?? null,
    'fbp' => $lead['fbp'] ?? null,
    'fbc' => $lead['fbc'] ?? null,
    'external_id' => $lead['token'] ?? ($p['token'] ?? null),
  ];
}

// --- external_id: SHA256(token) ---
$token_raw =
  (is_array($lead) ? ($lead['token'] ?? null) : null)
  ?? ($p['token'] ?? null)
  ?? ($user_in['external_id'] ?? null);

$token_raw = is_string($token_raw) ? trim($token_raw) : null;
$external_id_sha = $token_raw ? hash('sha256', $token_raw) : null;

// --- нормализация имени -> fn SHA256 ---
function norm_name($s): string {
  $s = is_string($s) ? trim(mb_strtolower($s, 'UTF-8')) : '';
  if ($s === '') return '';
  $s = preg_replace('/\s+/u', ' ', $s);
  $s = preg_replace('/[^\p{L}\s-]/u', '', $s); // только буквы/пробел/дефис
  $s = preg_replace('/\s+/u', ' ', $s);
  return trim($s);
}

$full_name = $lead['name'] ?? ($p['name'] ?? '');
$full_name = norm_name($full_name);

$fn_sha = null;
if ($full_name !== '') {
  $parts = preg_split('/\s+/u', $full_name);
  $fn = $parts[0] ?? '';
  if (is_string($fn) && $fn !== '') $fn_sha = hash('sha256', $fn);
}

// --- нормализуем массивы полей, которые Meta ждёт как array<string> ---
if (!empty($user_in['ph']) && is_string($user_in['ph'])) $user_in['ph'] = [$user_in['ph']];
if (!empty($user_in['em']) && is_string($user_in['em'])) $user_in['em'] = [$user_in['em']];

// --- user_data для Meta ---
$user_data = array_filter([
  'fn' => $fn_sha ? [$fn_sha] : null,

  // hashed identifiers
  'ph' => isset($user_in['ph']) ? (is_array($user_in['ph']) ? $user_in['ph'] : [$user_in['ph']]) : null,
  'em' => isset($user_in['em']) ? (is_array($user_in['em']) ? $user_in['em'] : [$user_in['em']]) : null,

  // cookies
  'fbp' => $user_in['fbp'] ?? null,
  'fbc' => $user_in['fbc'] ?? null,

  // deterministic id
  'external_id' => $external_id_sha ? [$external_id_sha] : null,

  // context
  'client_user_agent' => $lead['ua'] ?? null,
  'client_ip_address' => $lead['ip'] ?? null,
], 'compact_filter');

if (empty($user_data)) {
  j(400, ['error' => 'missing user_data', 'need_one_of' => ['ph','em','fbp','fbc','external_id','fn']]);
}

// --- event payload ---
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

// --- request ---
$url = "https://graph.facebook.com/{$API_VERSION}/{$PIXEL_ID}/events?access_token=" . urlencode($ACCESS_TOKEN);
$safeUrl = "https://graph.facebook.com/{$API_VERSION}/{$PIXEL_ID}/events";

log_meta_graph("SEND event_id=" . ($event['event_id'] ?? 'NULL') .
  " event_name=" . ($event['event_name'] ?? 'NULL') .
  " test=" . (!empty($TEST_EVENT_CODE) ? '1' : '0') .
  " url={$safeUrl}");

$t0 = microtime(true);

// 🔍 DEBUG: что реально уходит в Meta
log_meta_graph("USER_DATA " . json_encode($user_data, JSON_UNESCAPED_UNICODE));
log_meta_graph("EVENT " . json_encode($event, JSON_UNESCAPED_UNICODE));

log_meta_graph("UD flags ph=".(isset($user_data['ph'])?'1':'0').
  " em=".(isset($user_data['em'])?'1':'0').
  " fbp=".(!empty($user_data['fbp'])?'1':'0').
  " fbc=".(!empty($user_data['fbc'])?'1':'0').
  " fn=".(isset($user_data['fn'])?'1':'0').
  " ext=".(isset($user_data['external_id'])?'1':'0')
);

$ch = curl_init($url);

curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode($bodyArr, JSON_UNESCAPED_UNICODE),
  CURLOPT_CONNECTTIMEOUT => 3,
  CURLOPT_TIMEOUT => 12,
]);

$res   = curl_exec($ch);
$err   = curl_error($ch);
$errno = curl_errno($ch);
$code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($errno === 28) { // timeout
  $did_retry = 1;
  log_meta_graph("RETRY after timeout errno=28 ...");

  curl_close($ch);
  usleep(200000);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($bodyArr, JSON_UNESCAPED_UNICODE),
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 8,
  ]);

  $res   = curl_exec($ch);
  $err   = curl_error($ch);
  $errno = curl_errno($ch);
  $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
}

$tt = microtime(true) - $t0;

log_meta_graph("RESP http={$code} errno={$errno} err=" . ($err ?: '-') .
  " t=" . sprintf('%.3f', $tt) .
  " retry={$did_retry} body=" . substr((string)$res, 0, 1500));

curl_close($ch);

// parse response
$meta_json = null;
if (is_string($res) && $res !== '') {
  $tmp = json_decode($res, true);
  if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $meta_json = $tmp;
}

if ($err) {
  j(500, ['error' => 'curl_error', 'errno' => $errno, 'message' => $err]);
}

if ($code < 200 || $code >= 300 || (is_array($meta_json) && isset($meta_json['error']))) {
  j($code ?: 500, [
    'error' => 'meta rejected',
    'meta_http' => $code,
    'meta_body' => $res,
    'meta_json' => $meta_json,
  ]);
}

j(200, [
  'ok' => true,
  'meta_http' => $code,
  'meta_json' => $meta_json,
]);