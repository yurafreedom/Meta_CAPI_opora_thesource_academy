<?php
header('Content-Type: application/json');

function out($c,$a){ http_response_code($c); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

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

$token = trim($_GET['token'] ?? '');
if ($token === '') out(400, ['error'=>'bad token']);

// 1) забираем лид из Upstash: GET lead:<token>
$key = 'lead:' . $token;
list($code, $resp) = upstash_request('/get/' . rawurlencode($key));
if ($code < 200 || $code >= 300) out(500, ['error'=>'upstash_get_failed', 'code'=>$code, 'resp'=>$resp]);

$raw = json_decode($resp, true);
// Upstash REST обычно возвращает {"result":"...json..."} или {"result":null}
$leadJson = $raw['result'] ?? null;
if (!$leadJson) out(404, ['error'=>'lead not found']);

$lead = json_decode($leadJson, true);
if (!is_array($lead)) out(500, ['error'=>'corrupt lead', 'leadJson'=>$leadJson]);

$ph_sha256 = strtolower(trim($lead['ph_sha256'] ?? ''));
if ($ph_sha256 === '') out(400, ['error'=>'no ph_sha256 in lead']);

// 2) дедуп (в Upstash, чтобы не стрелять Purchase много раз)
$doneKey = 'purchase_done:' . $token;
list($c2, $r2) = upstash_request('/setnx/' . rawurlencode($doneKey) . '/1');
$nx = json_decode($r2, true);
$wasSet = ($nx['result'] ?? 0) == 1;

if (!$wasSet) out(200, ['ok'=>true, 'dedup'=>true]);

// TTL для дедупа (например 30 дней)
upstash_request('/expire/' . rawurlencode($doneKey) . '/' . (60*60*24*30));

// 3) формируем payload и дергаем meta-capi.php
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
  'custom_data' => ['value'=>1, 'currency'=>'UAH'],
];

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
$hcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) out(500, ['error'=>$err]);
if ($hcode < 200 || $hcode >= 300) out($hcode ?: 500, ['error'=>'meta failed', 'resp'=>$res]);

out(200, ['ok'=>true, 'meta'=>$res]);