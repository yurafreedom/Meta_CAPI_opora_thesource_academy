<?php
// store.php
header('Content-Type: application/json');

// --- CORS ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header("Vary: Origin");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 86400");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
// --- /CORS ---

function j($code, $arr) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function compact_filter($v) {
  if ($v === null) return false;
  if (is_string($v) && trim($v) === '') return false;
  return true;
}
function digits_only($s) { return preg_replace('/\D+/', '', (string)$s); }

$raw = file_get_contents('php://input');
$p = json_decode($raw, true);
if (!is_array($p)) j(400, ['error' => 'bad json']);

$token = trim((string)($p['token'] ?? ''));
if ($token === '') j(400, ['error' => 'missing token']);

// берем телефон из любых полей, которые у тебя реально приходят
$phone_raw   = trim((string)($p['phone_raw'] ?? ''));
$phone_e164  = trim((string)($p['phone_e164'] ?? ''));
$phone_digits = trim((string)($p['phone_digits'] ?? ''));

// нормализация
if ($phone_digits === '') $phone_digits = digits_only($phone_raw);
$norm = $phone_e164 !== '' ? $phone_e164 : $phone_digits;
if ($norm === '') j(400, ['error' => 'missing phone (need phone_raw/phone_digits/phone_e164)']);

// Meta ожидает sha256 HEX от нормализованного телефона (digits или +E164)
$ph_sha256 = hash('sha256', $norm);

$lead = array_filter([
  'token' => $token,
  'ts' => (int)($p['ts'] ?? time()*1000),
  'reason' => $p['reason'] ?? null,
  'page_url' => $p['page_url'] ?? null,
  'referrer' => $p['referrer'] ?? null,
  'name' => $p['name'] ?? null,

  'phone_raw' => $phone_raw ?: null,
  'phone_digits' => $phone_digits ?: null,
  'phone_e164' => $phone_e164 ?: null,

  'ph_sha256' => $ph_sha256,

  'utm_source' => $p['utm_source'] ?? null,
  'utm_campaign' => $p['utm_campaign'] ?? null,
  'utm_term' => $p['utm_term'] ?? null,
  'utm_content' => $p['utm_content'] ?? null,
], 'compact_filter');

try {
  $dbPath = __DIR__ . '/storage.sqlite';
  $pdo = new PDO('sqlite:' . $dbPath);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS leads (
      token TEXT PRIMARY KEY,
      json  TEXT NOT NULL,
      updated_at INTEGER NOT NULL
    );
  ");

  $stmt = $pdo->prepare("
    INSERT INTO leads(token, json, updated_at)
    VALUES(:token, :json, :t)
    ON CONFLICT(token) DO UPDATE SET
      json = excluded.json,
      updated_at = excluded.updated_at
  ");

  $now = time();
  $stmt->execute([
    ':token' => $token,
    ':json' => json_encode($lead, JSON_UNESCAPED_UNICODE),
    ':t' => $now
  ]);

  j(200, ['ok' => true, 'token' => $token, 'ph_sha256' => $ph_sha256]);
} catch (Throwable $e) {
  j(500, ['error' => 'db error', 'details' => $e->getMessage()]);
}