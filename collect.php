<?php
// collect.php — принимает GET и сохраняет в /data/<token>.json
header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

$token = trim($_GET['token'] ?? '');
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $token)) {
  http_response_code(400);
  echo json_encode(['error'=>'bad token']);
  exit;
}

$dir = __DIR__ . '/data';
if (!is_dir($dir)) @mkdir($dir, 0777, true);

$payload = [
  'token' => $token,
  'ts' => (int)($_GET['ts'] ?? round(microtime(true)*1000)),
  'ts_server' => time(),
  'reason' => (string)($_GET['r'] ?? 'submit'),
  'page_url' => (string)($_GET['u'] ?? ''),
  'referrer' => (string)($_GET['ref'] ?? ''),
  'name' => (string)($_GET['name'] ?? ''),
  'phone_digits' => (string)($_GET['ph'] ?? ''),
  'utm_source' => (string)($_GET['utm_source'] ?? ''),
  'utm_campaign' => (string)($_GET['utm_campaign'] ?? ''),
  'utm_term' => (string)($_GET['utm_term'] ?? ''),
  'utm_content' => (string)($_GET['utm_content'] ?? ''),
];

file_put_contents($dir . '/' . $token . '.json', json_encode($payload, JSON_UNESCAPED_UNICODE));
echo json_encode(['ok'=>true]);