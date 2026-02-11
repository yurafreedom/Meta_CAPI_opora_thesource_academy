<?php
// index.php (collect.php) — принимает GET (image beacon) и сохраняет lead в Upstash
header("Access-Control-Allow-Origin: *");

// --- CORS + preflight (по желанию, но нормально) ---
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

// --- input: GET + POST + raw body ---
$IN = $_GET;

if (!empty($_POST)) {
  $IN = array_merge($IN, $_POST);
} else {
  // sendBeacon может прилететь как text/plain, тогда $_POST пустой
  $raw = file_get_contents('php://input');
  if (is_string($raw) && $raw !== '') {
    $tmp = [];
    parse_str($raw, $tmp); // ожидаем form-urlencoded
    if (is_array($tmp) && !empty($tmp)) $IN = array_merge($IN, $tmp);
  }
}

function out($code, $arr){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

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

function upstash_get_json($key){
  // GET key -> returns JSON string (we stored as JSON string)
  list($code, $resp) = upstash_request('/get/' . rawurlencode($key));
  if (!($code >= 200 && $code < 300)) return [null, $code, $resp];

  $j = json_decode($resp, true);
  // Upstash REST returns: {"result":"..."} OR array variants depending on endpoint
  $raw = null;
  if (is_array($j) && array_key_exists('result', $j)) $raw = $j['result'];
  if (!is_string($raw) || $raw === '') return [null, $code, $resp];

  $arr = json_decode($raw, true);
  if (!is_array($arr)) return [null, $code, $resp];

  return [$arr, $code, $resp];
}

function upstash_set_raw($key, $value, $ttlSeconds){
  // value кладём как raw string в Redis через REST: /set/<key>/<value>
  list($code, $resp) = upstash_request(
    '/set/' . rawurlencode($key) . '/' . rawurlencode($value)
  );
  if (!($code >= 200 && $code < 300)) return [$code, $resp];

  list($code2, $resp2) = upstash_request(
    '/expire/' . rawurlencode($key) . '/' . intval($ttlSeconds)
  );
  return [$code2, $resp2];
}

function upstash_set_json($key, $valueArr, $ttlSeconds = 60*60*24*14){
  // храним в Redis ОДНУ JSON-строку (без [ "..." ] и без двойного экранирования)
  $json = json_encode($valueArr, JSON_UNESCAPED_UNICODE);
  return upstash_set_raw($key, $json, $ttlSeconds);
}

function upstash_lpush_json($key, $valueArr){
  $json = json_encode($valueArr, JSON_UNESCAPED_UNICODE);
  return upstash_request(
    '/lpush/' . rawurlencode($key),
    'POST',
    [ $json ]
  );
}

function s($v, $max = 500){
  $v = is_string($v) ? trim($v) : '';
  if ($v === '') return '';
  if (strlen($v) > $max) $v = substr($v, 0, $max);
  return $v;
}

function ip_from_headers(){
  // Railway обычно прокидывает x-forwarded-for
  $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
  if (is_string($xff) && $xff !== '') {
    $parts = array_map('trim', explode(',', $xff));
    if (!empty($parts[0])) return $parts[0];
  }
  $rip = $_SERVER['REMOTE_ADDR'] ?? '';
  return is_string($rip) ? $rip : '';
}

function merge_keep_first_nonempty(array $old, array $new, array $keepFirstKeys){
  // Для keepFirstKeys: если в old уже есть непустое — не затираем
  foreach ($keepFirstKeys as $k) {
    $ov = $old[$k] ?? null;
    $nv = $new[$k] ?? null;

    $old_has = !(($ov === null) || (is_string($ov) && trim($ov) === ''));
    $new_has = !(($nv === null) || (is_string($nv) && trim($nv) === ''));

    if ($old_has) {
      // оставляем old
      $new[$k] = $ov;
    } else {
      // берём new если есть, иначе null
      if (!$new_has) unset($new[$k]);
    }
  }

  // Остальные поля — можно обновлять (например reason/page_url/ts)
  return array_merge($old, $new);
}

// -------- input --------
$token = s($IN['token'] ?? '', 120);

// healthcheck
if ($token === '') {
  out(200, ['ok'=>true, 'service'=>'alive', 'hint'=>'pass ?token=... (&ph_sha256=... optional)']);
}

$ph_sha256 = strtolower(s($IN['ph_sha256'] ?? '', 80));
$fbp = s($IN['fbp'] ?? '', 220);
$fbc = s($IN['fbc'] ?? '', 220);

$record = [
  'token' => $token,

  // optional
  'ph_sha256' => $ph_sha256,
  'fbp' => $fbp,
  'fbc' => $fbc,

  // server-side
  'ip' => ip_from_headers(),
  'ua' => s($_SERVER['HTTP_USER_AGENT'] ?? '', 400),

  'ts' => (int)($IN['ts'] ?? (time()*1000)),
  'ts_server' => time(),
  'reason' => s($IN['r'] ?? 'collect', 80),

  'page_url' => s($IN['u'] ?? '', 800),
  'referrer' => s($IN['ref'] ?? '', 800),
  'name' => s($IN['name'] ?? '', 120),

  'utm_source' => s($IN['utm_source'] ?? '', 120),
  'utm_campaign' => s($IN['utm_campaign'] ?? '', 200),
  'utm_term' => s($IN['utm_term'] ?? '', 200),
  'utm_content' => s($IN['utm_content'] ?? '', 200),
];

// убираем пустые строки, чтобы merge был чище
foreach ($record as $k => $v) {
  if ($v === '' || $v === null) unset($record[$k]);
}

// 0) get existing lead
$leadKey = 'lead:' . $token;
list($oldLead, $gCode, $gResp) = upstash_get_json($leadKey);
if (!is_array($oldLead)) $oldLead = [];

// 1) merge (важно: phone/fbp/fbc/ip/ua сохраняем как “первое непустое”)
$merged = merge_keep_first_nonempty($oldLead, $record, [
  'ph_sha256','fbp','fbc','ip','ua',
  'utm_source','utm_campaign','utm_term','utm_content',
  'referrer','page_url','name'
]);

// 2) save merged
list($code1, $resp1) = upstash_set_json($leadKey, $merged, 60*60*24*14);

// 3) events log (опционально)
list($code2, $resp2) = upstash_lpush_json('leads_events', $record);

out(200, [
  'ok' => true,
  'saved' => ($code1 >= 200 && $code1 < 300),
  'token' => $token,
  'has_ph' => isset($merged['ph_sha256']) ? true : false,
  'has_fbp' => isset($merged['fbp']) ? true : false,
  'has_fbc' => isset($merged['fbc']) ? true : false,
  'set' => [$code1, $resp1],
  'lpush' => [$code2, $resp2],
]);