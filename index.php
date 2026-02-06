<?php
// index.php (или collect.php) — принимает GET (image beacon) и сохраняет lead в Upstash
header("Access-Control-Allow-Origin: *");

function out($code, $arr){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function upstash_request($path, $method = 'GET', $jsonBody = null){
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

function upstash_set_json($key, $valueArr, $ttlSeconds = 60*60*24*14){
  // ВАЖНО: кладём JSON строкой через POST, чтобы не упираться в длину URL
  $json = json_encode($valueArr, JSON_UNESCAPED_UNICODE);

  // SET key "json-string"
  list($code, $resp) = upstash_request(
    '/set/' . rawurlencode($key),
    'POST',
    [ $json ]
  );
  if (!($code >= 200 && $code < 300)) return [$code, $resp];

  // EXPIRE key ttl
  list($code2, $resp2) = upstash_request(
    '/expire/' . rawurlencode($key) . '/' . intval($ttlSeconds)
  );
  if (!($code2 >= 200 && $code2 < 300)) return [$code2, $resp2];

  return [$code, $resp];
}

function upstash_lpush_json($key, $valueArr){
  // LPUSH key "json-string"
  $json = json_encode($valueArr, JSON_UNESCAPED_UNICODE);
  return upstash_request(
    '/lpush/' . rawurlencode($key),
    'POST',
    [ $json ]
  );
}

// -------- input --------
$token     = trim($_GET['token'] ?? '');
$ph_sha256 = strtolower(trim($_GET['ph_sha256'] ?? ''));

// healthcheck
if ($token === '' && $ph_sha256 === '') {
  out(200, ['ok'=>true, 'service'=>'alive', 'hint'=>'pass ?token=...&ph_sha256=...']);
}
if ($token === '' || $ph_sha256 === '') {
  out(400, ['ok'=>false, 'error'=>'missing_token_or_ph_sha256']);
}

$record = [
  'token' => $token,
  'ph_sha256' => $ph_sha256,

  'ts' => (int)($_GET['ts'] ?? time()*1000),
  'ts_server' => time(),
  'reason' => (string)($_GET['r'] ?? 'collect'),

  'page_url' => (string)($_GET['u'] ?? ''),
  'referrer' => (string)($_GET['ref'] ?? ''),
  'name' => (string)($_GET['name'] ?? ''),

  'utm_source' => (string)($_GET['utm_source'] ?? ''),
  'utm_campaign' => (string)($_GET['utm_campaign'] ?? ''),
  'utm_term' => (string)($_GET['utm_term'] ?? ''),
  'utm_content' => (string)($_GET['utm_content'] ?? ''),
];

// 1) last-by-token (основа для /thanks)
list($code1, $resp1) = upstash_set_json('lead:' . $token, $record, 60*60*24*14);

// 2) events log (опционально, но полезно)
list($code2, $resp2) = upstash_lpush_json('leads_events', $record);

out(200, [
  'ok' => true,
  'saved' => ($code1 >= 200 && $code1 < 300),
  'where' => 'upstash',
  'token' => $token,
  'set' => [$code1, $resp1],
  'lpush' => [$code2, $resp2],
]);