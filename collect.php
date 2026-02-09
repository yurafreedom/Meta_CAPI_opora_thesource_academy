<?php

function gif1x1() {
  header('Content-Type: image/gif');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  echo base64_decode('R0lGODlhAQABAPAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
  exit;
}

function envv($k, $default = '') {
  $v = getenv($k);
  return ($v !== false && $v !== '') ? $v : $default;
}

/**
 * Upstash REST helper через /pipeline
 */
function upstash_cmd(array $cmd) {
  $url   = envv('UPSTASH_REDIS_REST_URL');
  $token = envv('UPSTASH_REDIS_REST_TOKEN');
  if (!$url || !$token) return;

  $ch = curl_init(rtrim($url, '/') . '/pipeline');

  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $token,
      'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode([ $cmd ], JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 3,
  ]);

  curl_exec($ch);
  curl_close($ch);
}


/* ================= INPUT ================= */

$token = trim($_GET['token'] ?? '');
if ($token === '' || strlen($token) > 80 || !preg_match('/^[a-z0-9\-\._]+$/i', $token)) {
  gif1x1();
}

$ph = preg_replace('/[^a-f0-9]/i', '', $_GET['ph_sha256'] ?? '');
if ($ph === '' || strlen($ph) !== 64) {
  gif1x1();
}


/* ================= RECORD ================= */

$record = [
  'token' => $token,
  'ph_sha256' => $ph,
  'ts' => (int)($_GET['ts'] ?? (int)(microtime(true)*1000)),
  'ts_server' => time(),
  'reason' => (string)($_GET['r'] ?? ''),
  'page_url' => (string)($_GET['u'] ?? ''),
  'referrer' => (string)($_GET['ref'] ?? ''),
  'name' => (string)($_GET['name'] ?? ''),
  'utm_source' => (string)($_GET['utm_source'] ?? ''),
  'utm_campaign' => (string)($_GET['utm_campaign'] ?? ''),
  'utm_term' => (string)($_GET['utm_term'] ?? ''),
  'utm_content' => (string)($_GET['utm_content'] ?? ''),
];


/* ================= SAVE TO UPSTASH ================= */

$key = "lead:$token";

/**
 * SET lead:<token> JSON EX 30d
 * overwrite допустим — collect может приходить несколько раз
 */
upstash_cmd([
  "SET",
  $key,
  json_encode($record, JSON_UNESCAPED_UNICODE),
  "EX",
  60 * 60 * 24 * 30
]);


/* ================= DONE ================= */

gif1x1();