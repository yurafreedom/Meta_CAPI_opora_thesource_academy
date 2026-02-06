<?php
// index.php — страховка: если прилетели параметры на / (без /collect.php), мы всё равно сохраним лид.
header("Access-Control-Allow-Origin: *");

function out($code, $arr){
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

$token = trim($_GET['token'] ?? '');
$ph    = preg_replace('/\D+/', '', $_GET['ph'] ?? '');

// Если это просто заход на корень без параметров — покажем что сервис жив
if ($token === '' || $ph === '') {
  out(200, ['ok'=>true, 'service'=>'alive', 'hint'=>'pass ?token=...&ph=...']);
}

$dir = __DIR__ . '/data';
if (!is_dir($dir)) @mkdir($dir, 0777, true);

$record = [
  'token' => $token,
  'ph' => $ph,
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

$tmp = $dir . "/{$token}.json.tmp";
$dst = $dir . "/{$token}.json";

file_put_contents($tmp, json_encode($record, JSON_UNESCAPED_UNICODE), LOCK_EX);
rename($tmp, $dst);

out(200, ['ok'=>true, 'saved'=>true, 'where'=>'/', 'token'=>$token]);