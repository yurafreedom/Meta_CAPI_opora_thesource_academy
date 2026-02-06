<?php
// collect.php — принимает GET-параметры, пишет data/<token>.json и возвращает 1x1 GIF

function gif1x1() {
  header('Content-Type: image/gif');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  // 1x1 transparent GIF
  echo base64_decode('R0lGODlhAQABAPAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
  exit;
}

$token = trim($_GET['token'] ?? '');
$ph    = preg_replace('/\D+/', '', $_GET['ph'] ?? '');
if ($token === '' || $ph === '') gif1x1();

$dir = __DIR__ . '/data';
if (!is_dir($dir)) @mkdir($dir, 0777, true);

$record = [
  'token' => $token,
  'ph' => $ph,
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

$tmp = $dir . "/{$token}.json.tmp";
$dst = $dir . "/{$token}.json";
@file_put_contents($tmp, json_encode($record, JSON_UNESCAPED_UNICODE), LOCK_EX);
@rename($tmp, $dst);

gif1x1();