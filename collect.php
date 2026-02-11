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

function s($v, $max = 500) {
  $v = is_string($v) ? trim($v) : '';
  if ($v === '') return '';
  if (strlen($v) > $max) $v = substr($v, 0, $max);
  return $v;
}

function phone_digits_only($phone) {
  // убираем +, пробелы, скобки — оставляем только цифры
  return preg_replace('~\D+~', '', (string)$phone);
}

function sha256_hex_lower($s) {
  return strtolower(hash('sha256', (string)$s));
}

/* ================= INPUT ================= */

$token = trim($_GET['token'] ?? '');
if ($token === '' || strlen($token) > 80 || !preg_match('/^[a-z0-9\-\._]+$/i', $token)) {
  gif1x1();
}

// 1) предпочтительно: берём E.164 и считаем sha256 на бэке
$phone_e164 = s($_GET['phone_e164'] ?? $_GET['Phone_E164'] ?? $_GET['phone'] ?? '', 40);

// 2) fallback: если всё же прислали ph_sha256 — возьмём его
$ph_sha256_in = strtolower(s($_GET['ph_sha256'] ?? '', 80));
$ph_sha256 = '';

// A) считаем по телефону
if ($phone_e164 !== '') {
  $digits = phone_digits_only($phone_e164);
  // Meta phone: 9..15 digits обычно; не трогаем ведущие цифры страны
  if ($digits !== '' && strlen($digits) >= 9 && strlen($digits) <= 15) {
    $ph_sha256 = sha256_hex_lower($digits);
  }
}

// B) fallback по готовому хешу (если телефона не было/невалиден)
if ($ph_sha256 === '' && preg_match('~^[0-9a-f]{64}$~', $ph_sha256_in)) {
  $ph_sha256 = $ph_sha256_in;
}

// Если нет ни телефона, ни валидного ph_sha256 — просто gif и всё (как и было)
if ($ph_sha256 === '') {
  gif1x1();
}

/* ================= RECORD ================= */

$record = [
  'token' => $token,

  // для дебага (можешь потом убрать)
  'record_phone_e164' => $phone_e164,

  // 🔥 главное поле для Meta (уже sha256(digits))
  'ph_sha256' => $ph_sha256,

  // 🔥 ВАЖНО ДЛЯ META MATCHING
  'fbp' => (string)($_GET['fbp'] ?? ''),
  'fbc' => (string)($_GET['fbc'] ?? ''),

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

// чистим пустые строки (и не сохраняем пустой ph/fbp/fbc и т.д.)
foreach ($record as $k => $v) {
  if ($v === '' || $v === null) unset($record[$k]);
}

/* ================= SAVE TO UPSTASH ================= */

$key = "lead:$token";

upstash_cmd([
  "SET",
  $key,
  json_encode($record, JSON_UNESCAPED_UNICODE),
  "EX",
  60 * 60 * 24 * 30
]);

// лог без секретов
@file_put_contents(
  __DIR__.'/lead_store.log',
  date('c')
  ." token=".$token
  ." ph=".substr($ph_sha256,0,10)."..."
  ." has_fbp=".(!empty($record['fbp'])?'1':'0')
  ." has_fbc=".(!empty($record['fbc'])?'1':'0')
  ." e164=".($phone_e164 !== '' ? '1' : '0')
  ."\n",
  FILE_APPEND
);

/* ================= DONE ================= */

gif1x1();