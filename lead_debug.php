<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

function out($code, $arr){
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function upstash_request($path){
  $url   = getenv('UPSTASH_REDIS_REST_URL');
  $token = getenv('UPSTASH_REDIS_REST_TOKEN');
  if (!$url || !$token) out(500, ['error'=>'missing_upstash_env']);

  $ch = curl_init(rtrim($url, '/') . $path);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $resp];
}

function is_sha256($s){
  return is_string($s) && preg_match('~^[0-9a-f]{64}$~', strtolower(trim($s)));
}

$token = trim($_GET['token'] ?? '');
if ($token === '') out(400, ['error'=>'token required']);

$key = 'lead:' . $token;
list($code, $resp) = upstash_request('/get/' . rawurlencode($key));
if (!($code >= 200 && $code < 300)) out(502, ['error'=>'upstash_http', 'http'=>$code, 'resp'=>$resp]);

$j = json_decode($resp, true);
$raw = (is_array($j) && array_key_exists('result', $j)) ? $j['result'] : null;
$lead = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
if (!is_array($lead)) $lead = [];

out(200, [
  'ok' => true,
  'token' => $token,
  'has_ph' => is_sha256($lead['ph_sha256'] ?? ''),
  'has_fbp' => !empty($lead['fbp'] ?? ''),
  'has_fbc' => !empty($lead['fbc'] ?? ''),
  'has_ip' => !empty($lead['ip'] ?? ''),
  'has_ua' => !empty($lead['ua'] ?? ''),
  // полезно видеть кусок (безопасно):
  'lead_preview' => [
    'ph_sha256' => isset($lead['ph_sha256']) ? substr($lead['ph_sha256'], 0, 8) . '...' : null,
    'fbp' => $lead['fbp'] ?? null,
    'fbc' => $lead['fbc'] ?? null,
    'ip' => $lead['ip'] ?? null,
    'ua' => isset($lead['ua']) ? substr($lead['ua'], 0, 80) : null,
    'page_url' => $lead['page_url'] ?? null,
    'reason' => $lead['reason'] ?? null,
  ],
]);