<?php
// wfp-webhook.php

// Generate a request ID for log correlation
$_rid = substr(bin2hex(random_bytes(4)), 0, 8);

function _log(string $msg) {
  global $_rid;
  error_log("[wfp-webhook rid={$_rid}] {$msg}");
}

_log("HIT method=" . ($_SERVER['REQUEST_METHOD'] ?? '') . " ct=" . ($_SERVER['CONTENT_TYPE'] ?? ''));

$raw = file_get_contents('php://input') ?: '';
$ct  = $_SERVER['CONTENT_TYPE'] ?? '';

function envv($k, $default = '') {
  $v = getenv($k);
  return ($v !== false && $v !== '') ? $v : $default;
}

function upstash_cmd(array $cmd) {
  $url = envv('UPSTASH_REDIS_REST_URL');
  $token = envv('UPSTASH_REDIS_REST_TOKEN');
  if (!$url || !$token) { throw new Exception("Upstash env missing"); }

  $fullUrl = rtrim($url, '/') . '/pipeline';
  $payload = json_encode([$cmd], JSON_UNESCAPED_UNICODE);
  $attempt = 0;
  $maxAttempts = 2;

  while (++$attempt <= $maxAttempts) {
    $ch = curl_init($fullUrl);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
      ],
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 2,
      CURLOPT_TIMEOUT => 5,
    ]);

    $res   = curl_exec($ch);
    $err   = curl_error($ch);
    $errno = curl_errno($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno === 28 && $attempt < $maxAttempts) {
      usleep(200000);
      continue;
    }
    break;
  }

  if ($res === false) throw new Exception("Upstash curl error: $err");
  if ($code < 200 || $code >= 300) throw new Exception("Upstash HTTP $code: $res");

  $j = json_decode($res, true);
  return $j[0]['result'] ?? null;
}

function ok_json($arr = []) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

// 1) Parse input
$data = null;

// A. JSON from raw body
if ($raw !== '') {
  $j = json_decode($raw, true);
  if (is_array($j)) {
    $data = $j;
  }
}

// B. WayForPay quirk: entire JSON as the key of a single POST field
if (!is_array($data) && !empty($_POST) && count($_POST) === 1) {
  $k = array_key_first($_POST);
  $v = $_POST[$k];

  if (
    is_string($k) &&
    $v === '' &&
    $k !== '' &&
    ($k[0] === '{' || $k[0] === '[')
  ) {
    $j2 = json_decode($k, true);
    if (is_array($j2)) {
      $data = $j2;
    }
  }
}

// C. Normal form POST
if (!is_array($data) && !empty($_POST)) {
  $data = $_POST;
}

// D. Fallback: parse_str from raw
if (!is_array($data) && $raw !== '') {
  $tmp = [];
  parse_str($raw, $tmp);
  if (!empty($tmp)) {
    $data = $tmp;
  }
}

if (!is_array($data)) {
  http_response_code(400);
  ok_json(['error' => 'bad payload']);
}

// Sanitized log (no card/signature/PII)
$log = $data;
unset($log['cardPan'], $log['card_pan'], $log['merchantSignature'], $log['signature'], $log['email'], $log['phone']);
_log("PARSED keys=" . implode(',', array_keys($data)) . " orderReference=" . ($data['orderReference'] ?? 'NULL'));

// 2) Extract basic fields
$order_id = $data['orderReference'] ?? $data['order_reference'] ?? '';
$status   = $data['transactionStatus'] ?? $data['status'] ?? '';
$signature= $data['merchantSignature'] ?? $data['signature'] ?? '';

if ($order_id === '') { http_response_code(400); ok_json(['error' => 'orderReference missing']); }

// 3) Verify WFP signature
$secret = envv('WFP_SECRET');
if (!$secret) { http_response_code(500); ok_json(['error'=>'WFP secret missing']); }
$merchantAccount = $data['merchantAccount'] ?? envv('WFP_MERCHANT', '');

$amount   = (string)($data['amount'] ?? '');
$currency = (string)($data['currency'] ?? '');
$authCode = (string)($data['authCode'] ?? '');
$cardPan  = (string)($data['cardPan'] ?? '');
$reasonCode = (string)($data['reasonCode'] ?? '');

$signStr = implode(';', [
  $merchantAccount,
  $order_id,
  $amount,
  $currency,
  $authCode,
  $cardPan,
  $status,
  $reasonCode,
]);

$calc = hash_hmac('md5', $signStr, $secret);

$dev = envv('DEV_BYPASS_WFP_SIGNATURE', '0');

if ($dev !== '1') {
  if (!hash_equals(strtolower($calc), strtolower($signature))) {
    http_response_code(403);
    ok_json(['error' => 'bad signature']);
  }
} else {
  _log("DEV_BYPASS_WFP_SIGNATURE=1");
}

// 4) Check for successful transaction
$success = in_array(strtoupper($status), ['APPROVED', 'SUCCESS', 'ACCEPTED'], true);
if (!$success) {
  _log("IGNORED non-success status={$status} order={$order_id}");
  ok_json(['ok' => true, 'ignored' => 'not success', 'status' => $status]);
}

// 5) C1 FIX: State-machine deduplication (no more DEL)
// States: "processing" -> "done" or "failed"
$dedupeKey = "wfp:purchase_sent:$order_id";
$setnx = upstash_cmd(["SET", $dedupeKey, "processing", "NX", "EX", 900]); // 15 min lock

if ($setnx !== "OK") {
  // Key already exists — check its state
  $currentState = upstash_cmd(["GET", $dedupeKey]);
  _log("DEDUP order={$order_id} state={$currentState}");

  if ($currentState === 'done' || $currentState === 'processing') {
    ok_json(['ok' => true, 'deduped' => true, 'state' => $currentState]);
  }
  // state=failed or unknown -> allow reprocessing by overwriting
  $overwrite = upstash_cmd(["SET", $dedupeKey, "processing", "EX", 900]);
  if ($overwrite !== "OK") {
    ok_json(['ok' => true, 'deduped' => true, 'state' => 'contention']);
  }
  _log("REPROCESS after state={$currentState} order={$order_id}");
}

// 6) Get token by order_id
$mapKey = "wfp:order:$order_id";
$mapJson = upstash_cmd(["GET", $mapKey]);
$map = $mapJson ? json_decode($mapJson, true) : null;
$token = is_array($map) ? ($map['token'] ?? '') : '';

if ($token === '') {
  $token = 'missing';
}

// 7) Fetch lead data by token
$lead = null;
$leadJson = '';
if ($token !== 'missing') {
  $leadJson = upstash_cmd(["GET", "lead:$token"]);
  $lead = $leadJson ? json_decode($leadJson, true) : null;
}

function unwrap_lead($lead) {
  if (is_string($lead)) {
    $x = json_decode($lead, true);
    return is_array($x) ? unwrap_lead($x) : null;
  }

  if (!is_array($lead)) return null;

  if (isset($lead['0']) && is_string($lead['0'])) {
    $x = json_decode($lead['0'], true);
    if (is_array($x)) return unwrap_lead($x);
  }

  return $lead;
}

$lead = unwrap_lead($lead);

_log("LEAD order={$order_id} token={$token}"
  . " lead_ok=" . (is_array($lead) ? '1' : '0')
  . " ph=" . (is_array($lead) ? substr($lead['ph_sha256'] ?? '', 0, 8) : '-')
);

// 7.1) M3 FIX: phone hash is OPTIONAL — send Purchase with whatever user_data exists
// ph_sha256 is kept if present, but its absence no longer blocks the event
$ph = (is_array($lead) ? ($lead['ph_sha256'] ?? '') : '');
$ph = is_string($ph) ? strtolower(trim($ph)) : '';
$is_ph_valid = (bool)preg_match('~^[0-9a-f]{64}$~', $ph);
$ph_sha = $is_ph_valid ? $ph : '';

if (!$is_ph_valid) {
  _log("WARN no_phone order={$order_id} token={$token} — sending with external_id/fbp/fbc only");
}

// 8) Send Purchase to Meta Graph API
$ACCESS_TOKEN    = envv('META_CAPI_TOKEN');
$PIXEL_ID        = envv('META_PIXEL_ID');
$TEST_EVENT_CODE = envv('META_TEST_EVENT_CODE');
$API_VERSION     = envv('META_API_VERSION', 'v21.0');

if (!$ACCESS_TOKEN || !$PIXEL_ID) {
  upstash_cmd(["SET", $dedupeKey, "failed", "EX", 300]);
  http_response_code(500);
  ok_json([
    'error' => 'meta env missing',
    'has_token' => (bool)$ACCESS_TOKEN,
    'has_pixel' => (bool)$PIXEL_ID,
  ]);
}

$external_id_sha = ($token && $token !== 'missing')
  ? hash('sha256', $token)
  : hash('sha256', $order_id);

function norm_name($s){
  $s = trim((string)$s);
  $s = mb_strtolower($s, 'UTF-8');
  $s = preg_replace('~[^\p{L}\s\-]+~u', '', $s);
  $s = preg_replace('~\s+~u', ' ', $s);
  return trim($s);
}

$name_raw = is_array($lead) ? ($lead['name'] ?? '') : '';
$name_raw = norm_name($name_raw);

$fn = '';
$ln = '';
if ($name_raw !== '') {
  $parts = preg_split('~\s+~u', $name_raw);
  $fn = $parts[0] ?? '';
  if (count($parts) > 1) $ln = end($parts);
}

$user_data = array_filter([
  'ph' => ($ph_sha !== '' ? [ $ph_sha ] : null),
  'external_id' => [ $external_id_sha ],

  'fn' => ($fn !== '' ? [ hash('sha256', $fn) ] : null),
  'ln' => ($ln !== '' ? [ hash('sha256', $ln) ] : null),

  'fbp' => (is_array($lead) ? ($lead['fbp'] ?? null) : null),
  'fbc' => (is_array($lead) ? ($lead['fbc'] ?? null) : null),
  'client_ip_address' => (is_array($lead) ? ($lead['ip'] ?? null) : null),
  'client_user_agent' => (is_array($lead) ? ($lead['ua'] ?? null) : null),
], function($v){
  if ($v === null) return false;
  if (is_string($v) && trim($v) === '') return false;
  if (is_array($v) && empty($v)) return false;
  return true;
});

_log("UD ph=".(isset($user_data['ph'])?'1':'0')
  ." fbp=".(!empty($user_data['fbp'])?'1':'0')
  ." fbc=".(!empty($user_data['fbc'])?'1':'0')
  ." fn=".(isset($user_data['fn'])?'1':'0')
  ." ext=".(isset($user_data['external_id'])?'1':'0')
);

$event = array_filter([
  'event_name' => 'Purchase',
  'event_time' => time(),
  'event_id'   => $order_id,
  'action_source' => 'website',
  'event_source_url' => (is_array($lead) ? ($lead['page_url'] ?? null) : null) ?? envv('DEFAULT_EVENT_SOURCE_URL', ''),
  'user_data' => $user_data,
  'custom_data' => [
    'currency' => $currency,
    'value' => (float)$amount,
  ],
], function($v){
  if ($v === null) return false;
  if (is_string($v) && trim($v) === '') return false;
  if (is_array($v) && empty($v)) return false;
  return true;
});

$bodyArr = ['data' => [$event]];
if (!empty($TEST_EVENT_CODE)) $bodyArr['test_event_code'] = $TEST_EVENT_CODE;

// C2 FIX: token via Authorization header, not query string
$metaUrl = "https://graph.facebook.com/{$API_VERSION}/{$PIXEL_ID}/events";
$metaPayload = json_encode($bodyArr, JSON_UNESCAPED_UNICODE);

_log("SEND event_id={$order_id} url={$metaUrl}");

$did_retry = 0;
$t0 = microtime(true);

$_meta_do_request = function(string $reqUrl, array $headers, string $payload) {
  $ch = curl_init($reqUrl);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 8,
  ]);
  $res   = curl_exec($ch);
  $err   = curl_error($ch);
  $errno = curl_errno($ch);
  $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$res, $err, $errno, $code];
};

$metaHeaders = [
  'Content-Type: application/json',
  'Authorization: Bearer ' . $ACCESS_TOKEN,
];

list($res, $err, $errno, $code) = $_meta_do_request($metaUrl, $metaHeaders, $metaPayload);

// TODO(remove after 2025-08-01): Fallback to query-string token if Bearer returns 401/403
if (in_array($code, [401, 403], true) && envv('META_TOKEN_QUERY_FALLBACK') === '1') {
  _log("FALLBACK query-string token after http={$code}");
  $fallbackUrl = $metaUrl . '?access_token=' . urlencode($ACCESS_TOKEN);
  $fallbackHeaders = ['Content-Type: application/json'];
  list($res, $err, $errno, $code) = $_meta_do_request($fallbackUrl, $fallbackHeaders, $metaPayload);
  $did_retry = 1;
}

// Retry on timeout
$timed_out = ($errno === 28) || (($code === 0) && $err && stripos($err, 'timed out') !== false);
if ($timed_out) {
  $did_retry = 1;
  _log("RETRY timeout errno={$errno}");
  usleep(200000);
  list($res, $err, $errno, $code) = $_meta_do_request($metaUrl, $metaHeaders, $metaPayload);
}

$tt = microtime(true) - $t0;

_log("RESP http={$code} errno={$errno} err=" . ($err ?: '-')
  . " t=" . sprintf('%.3f', $tt)
  . " retry={$did_retry}"
);

// C1 FIX: on Meta failure, set state to "failed" with short TTL (never DEL)
if ($err || $code < 200 || $code >= 300) {
  upstash_cmd(["SET", $dedupeKey, "failed", "EX", 300]); // 5 min, allows WFP retry
  _log("DEDUP->failed order={$order_id}");
  http_response_code(502);
  ok_json(['error' => 'meta failed', 'http' => $code, 'errno' => $errno]);
}

// Success: mark dedup as done with long TTL
upstash_cmd(["SET", $dedupeKey, "done", "EX", 60*60*24*90]); // 90 days
_log("DEDUP->done order={$order_id}");

function wfp_accept_response(string $order_id, string $secret) {
  $time = time();
  $status = 'accept';
  $signStr = $order_id . ';' . $status . ';' . $time;
  $sig = hash_hmac('md5', $signStr, $secret);

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'orderReference' => $order_id,
    'status' => $status,
    'time' => $time,
    'signature' => $sig,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

wfp_accept_response($order_id, $secret);
