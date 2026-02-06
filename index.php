<?php
header("Access-Control-Allow-Origin: *");

function out($code, $arr){
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function upstash_lpush($key, $valueArr){
  $url   = getenv('UPSTASH_REDIS_REST_URL');
  $token = getenv('UPSTASH_REDIS_REST_TOKEN');
  if (!$url || !$token) return [0, 'missing_upstash_env'];

  $ch = curl_init(rtrim($url, '/') . '/lpush/' . rawurlencode($key));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
  ]);
  curl_setopt($ch, CURLOPT_POST, true);
  // Upstash REST expects JSON array of args. For LPUSH: [key, value1, value2...]
  // But when using /lpush/<key>, body should be [value1, value2...]
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([$valueArr], JSON_UNESCAPED_UNICODE));

  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  return [$code, $resp];
}

$token     = trim($_GET['token'] ?? '');
$ph_sha256 = strtolower(trim($_GET['ph_sha256'] ?? ''));

// строгая валидация sha256 hex (64 символа)
if ($ph_sha256 !== '' && !preg_match('/^[a-f0-9]{64}$/', $ph_sha256)) {
  $ph_sha256 = ''; // считаем невалидным
}

if ($token === '' || $ph_sha256 === '') {
  out(200, [
    'ok' => true,
    'service' => 'alive',
    'hint' => 'pass ?token=...&ph_sha256=<64-hex>'
  ]);
}

$record = [
  'token' => $token,
  'ph_sha256' => $ph_sha256,
  'ts' => (int)($_GET['ts'] ?? time()*1000),
  'ts_server' => time(),
  'reason' => (string)($_GET['r'] ?? 'root_collect'),
  'page_url' => (string)($_GET['u'] ?? ''),
  'referrer' => (string)($_GET['ref'] ?? ''),
  'name' => (string)($_GET['name'] ?? ''),
  'utm_source' => (string)($_GET['utm_source'] ?? ''),
  'utm_campaign' => (string)($_GET['utm_campaign'] ?? ''),
  'utm_term' => (string)($_GET['utm_term'] ?? ''),
  'utm_content' => (string)($_GET['utm_content'] ?? ''),
];

list($code, $resp) = upstash_lpush('leads', $record);

if ($code >= 200 && $code < 300) {
  out(200, ['ok'=>true, 'saved'=>true, 'where'=>'upstash', 'token'=>$token]);
}

// fallback: локальный файл (только для теста)
$dir = __DIR__ . '/data';
if (!is_dir($dir)) @mkdir($dir, 0777, true);

$tmp = $dir . "/{$token}.json.tmp";
$dst = $dir . "/{$token}.json";
file_put_contents($tmp, json_encode($record, JSON_UNESCAPED_UNICODE), LOCK_EX);
rename($tmp, $dst);

out(200, ['ok'=>true, 'saved'=>true, 'where'=>'local_fs', 'token'=>$token, 'upstash_error'=>[$code,$resp]]);