<?php
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function out($c, $a){ http_response_code($c); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

function upstash_request($path, $method='GET', $jsonBody=null){
  $url   = getenv('UPSTASH_REDIS_REST_URL');
  $token = getenv('UPSTASH_REDIS_REST_TOKEN');
  if (!$url || !$token) return [0, 'missing_upstash_env'];

  $ch = curl_init(rtrim($url, '/') . $path);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
  ]);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
  curl_setopt($ch, CURLOPT_TIMEOUT, 4);

  if ($method === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    if ($jsonBody !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_UNESCAPED_UNICODE));
  }

  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $resp];
}

function upstash_set_json($key, $valueArr, $ttlSeconds = 60*60*24*14){
  $json = json_encode($valueArr, JSON_UNESCAPED_UNICODE);

  list($code, $resp) = upstash_request('/set/' . rawurlencode($key), 'POST', [ $json ]);
  if (!($code >= 200 && $code < 300)) return [$code, $resp];

  list($code2, $resp2) = upstash_request('/expire/' . rawurlencode($key) . '/' . intval($ttlSeconds));
  if (!($code2 >= 200 && $code2 < 300)) return [$code2, $resp2];

  return [$code, $resp];
}

function uuidv4(){
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['error'=>'POST only']);

$in = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($in)) $in = [];

$token = uuidv4();

$record = [
  'token' => $token,
  'ts_server' => time(),
  'reason' => 'preclick',
  'page_url' => (string)($in['page_url'] ?? ''),
  'referrer' => (string)($in['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? '')),
  'utm_source' => (string)($in['utm_source'] ?? ''),
  'utm_campaign' => (string)($in['utm_campaign'] ?? ''),
  'utm_term' => (string)($in['utm_term'] ?? ''),
  'utm_content' => (string)($in['utm_content'] ?? ''),
  'client_user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
  'ip' => (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
];

list($c, $r) = upstash_set_json('lead:' . $token, $record, 60*60*24*14);
if (!($c >= 200 && $c < 300)) out(500, ['error'=>'upstash_set_failed','http'=>$c,'resp'=>$r]);

out(200, ['ok'=>true, 'token'=>$token]);