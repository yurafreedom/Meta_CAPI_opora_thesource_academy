<?php
// collect.php — сохраняет лид-данные (phone, fbp, fbc, name, utm) в Upstash Redis
// FIX: больше не прерывается при отсутствии телефона — сохраняет всё что есть

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

function upstash_cmd(array $cmd) {
  $url   = envv('UPSTASH_REDIS_REST_URL');
  $token = envv('UPSTASH_REDIS_REST_TOKEN');
  if (!$url || !$token) return;

  $fullUrl = rtrim($url, '/') . '/pipeline';
  $payload = json_encode([ $cmd ], JSON_UNESCAPED_UNICODE);
  $attempt = 0;
  $maxAttempts = 2;

  while (++$attempt <= $maxAttempts) {
    $ch = curl_init($fullUrl);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
      ],
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 2,
      CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno === 28 && $attempt < $maxAttempts) {
      usleep(200000);
      continue;
    }
    break;
  }
}

function s($v, $max = 500) {
  $v = is_string($v) ? trim($v) : '';
  if ($v === '') return '';
  if (strlen($v) > $max) $v = substr($v, 0, $max);
  return $v;
}

function phone_digits_only($phone) {
  return preg_replace('~\D+~', '', (string)$phone);
}

function sha256_hex_lower($s) {
  return strtolower(hash('sha256', (string)$s));
}

/* ================= INPUT ================= */

// Поддержка и GET (Image pixel), и POST (sendBeacon)
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

$token = trim($input['token'] ?? '');
if ($token === '' || strlen($token) > 80 || !preg_match('/^[a-z0-9\-\._]+$/i', $token)) {
  gif1x1();
}

// 1) Телефон: пробуем phone_e164, затем Phone, затем phone
$phone_e164 = s($input['phone_e164'] ?? $input['Phone_E164'] ?? $input['Phone'] ?? $input['phone'] ?? '', 40);

// 2) fallback: готовый ph_sha256
$ph_sha256_in = strtolower(s($input['ph_sha256'] ?? '', 80));
$ph_sha256 = '';

// A) считаем по телефону
if ($phone_e164 !== '') {
  $digits = phone_digits_only($phone_e164);
  if ($digits !== '' && strlen($digits) >= 9 && strlen($digits) <= 15) {
    $ph_sha256 = sha256_hex_lower($digits);
  }
}

// B) fallback по готовому хешу
if ($ph_sha256 === '' && preg_match('~^[0-9a-f]{64}$~', $ph_sha256_in)) {
  $ph_sha256 = $ph_sha256_in;
}

// ⚠️ FIX: НЕ прерываемся если нет телефона!
// Раньше здесь был: if ($ph_sha256 === '') { gif1x1(); }
// Это убивало сохранение fbp, fbc, name, utm — критично для Event Match Quality

/* ================= RECORD ================= */

$record = [
  'token' => $token,
  'ts' => (int)($input['ts'] ?? (int)(microtime(true)*1000)),
  'ts_server' => time(),
  'reason' => (string)($input['r'] ?? ''),
  'page_url' => (string)($input['u'] ?? ''),
  'referrer' => (string)($input['ref'] ?? ''),
  'name' => (string)($input['name'] ?? ''),
  'utm_source' => (string)($input['utm_source'] ?? ''),
  'utm_campaign' => (string)($input['utm_campaign'] ?? ''),
  'utm_term' => (string)($input['utm_term'] ?? ''),
  'utm_content' => (string)($input['utm_content'] ?? ''),

  // 🔥 Meta matching данные
  'fbp' => (string)($input['fbp'] ?? ''),
  'fbc' => (string)($input['fbc'] ?? ''),
];

// Телефон: добавляем только если есть
if ($ph_sha256 !== '') {
  $record['ph_sha256'] = $ph_sha256;
}
if ($phone_e164 !== '') {
  $record['record_phone_e164'] = $phone_e164; // для дебага
}

// Чистим пустые строки
foreach ($record as $k => $v) {
  if ($v === '' || $v === null) unset($record[$k]);
}

/* ================= MERGE WITH EXISTING (не затираем старые данные) ================= */

// FIX: collect может вызываться несколько раз (pointerdown, submit, pagehide)
// Если первый вызов имел phone, а второй нет — не затираем phone
$key = "lead:$token";

// Пробуем прочитать существующую запись
$existingJson = null;
$url   = envv('UPSTASH_REDIS_REST_URL');
$utoken = envv('UPSTASH_REDIS_REST_TOKEN');
if ($url && $utoken) {
  $getUrl = rtrim($url, '/') . '/pipeline';
  $ch = curl_init($getUrl);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $utoken,
      'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode([["GET", $key]]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 3,
  ]);
  $res = curl_exec($ch);
  curl_close($ch);
  if ($res) {
    $j = json_decode($res, true);
    $existingJson = $j[0]['result'] ?? null;
  }
}

// Мержим: новые данные дополняют старые, но НЕ затирают непустые поля
if ($existingJson) {
  $existing = json_decode($existingJson, true);
  if (is_string($existing)) $existing = json_decode($existing, true); // double-encoded
  if (is_array($existing)) {
    // Старые непустые поля сохраняются, если новый вызов их не содержит
    foreach ($existing as $ek => $ev) {
      if ($ev !== '' && $ev !== null && !isset($record[$ek])) {
        $record[$ek] = $ev;
      }
    }
    // Критичные поля: если в старой записи есть ph_sha256, а в новой нет — сохраняем старый
    if (!empty($existing['ph_sha256']) && empty($record['ph_sha256'])) {
      $record['ph_sha256'] = $existing['ph_sha256'];
    }
    if (!empty($existing['record_phone_e164']) && empty($record['record_phone_e164'])) {
      $record['record_phone_e164'] = $existing['record_phone_e164'];
    }
  }
}

/* ================= SAVE TO UPSTASH ================= */

upstash_cmd([
  "SET",
  $key,
  json_encode($record, JSON_UNESCAPED_UNICODE),
  "EX",
  60 * 60 * 24 * 30
]);

// Лог
@file_put_contents(
  __DIR__.'/lead_store.log',
  date('c')
  ." token=".$token
  ." ph=".(isset($record['ph_sha256']) ? substr($record['ph_sha256'],0,10)."..." : "NONE")
  ." has_fbp=".(!empty($record['fbp'])?'1':'0')
  ." has_fbc=".(!empty($record['fbc'])?'1':'0')
  ." e164=".($phone_e164 !== '' ? '1' : '0')
  ." reason=".($record['reason'] ?? '-')
  ." merged=".($existingJson ? '1' : '0')
  ."\n",
  FILE_APPEND
);

/* ================= DONE ================= */

gif1x1();
