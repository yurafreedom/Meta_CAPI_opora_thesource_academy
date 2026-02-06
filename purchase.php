<?php
header('Content-Type: application/json');

function out($c,$a){ http_response_code($c); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$token = trim($_GET['token'] ?? '');
if ($token === '') out(400, ['error'=>'bad token']);

$dir = __DIR__ . '/data';
$leadPath = $dir . "/{$token}.json";
if (!is_file($leadPath)) out(404, ['error'=>'lead not found']);

$doneDir = __DIR__ . '/done';
if (!is_dir($doneDir)) @mkdir($doneDir, 0777, true);
$donePath = $doneDir . "/purchase_{$token}.done";
if (is_file($donePath)) out(200, ['ok'=>true, 'dedup'=>true]);

$lead = json_decode(file_get_contents($leadPath), true);
if (!is_array($lead)) out(500, ['error'=>'corrupt lead']);

$ph = preg_replace('/\D+/', '', $lead['ph'] ?? '');
if ($ph === '') out(400, ['error'=>'no phone in lead']);

// sha256 для Meta (нормализация: только цифры)
$ph_sha256 = hash('sha256', $ph);

// собираем payload для meta-capi.php
$payload = [
  'event_name' => 'Purchase',
  'event_time' => time(),
  'event_source_url' => $lead['page_url'] ?? ($_GET['u'] ?? ''),
  'lead' => [
    'ph_sha256' => $ph_sha256,
    'token' => $token,
    'value' => 1,
    'currency' => 'UAH'
  ],
  // важно для дедупа в Meta — тот же ID
  'custom_data' => ['value'=>1, 'currency'=>'UAH'],
];

// вызываем локально meta-capi.php (как HTTP)
$metaUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}/meta-capi.php";

$ch = curl_init($metaUrl);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT => 8,
]);
$res = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) out(500, ['error'=>$err]);
if ($code < 200 || $code >= 300) out($code ?: 500, ['error'=>'meta failed', 'resp'=>$res]);

file_put_contents($donePath, "ok " . time());

http_response_code(200);
echo $res;