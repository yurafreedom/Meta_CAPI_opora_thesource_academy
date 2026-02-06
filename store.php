<script>
(() => {
  const API = "https://metacapioporathesourceacademy-production.up.railway.app";
  const PING = API + "/ping.php";

  function fire(reason){
    const u = PING + "?r=" + encodeURIComponent(reason) + "&ts=" + Date.now();
    (new Image()).src = u;
  }

  document.addEventListener("pointerdown", (e) => {
    const btn = e.target.closest('input[type="submit"], button[type="submit"], a, .w-button');
    if (btn) fire("pointerdown_btn");
  }, true);

  document.addEventListener("submit", (e) => {
    fire("submit");
  }, true);
})();
</script>

<?php
// header('Content-Type: application/json');

// // --- CORS ---
// $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
// header("Access-Control-Allow-Origin: $origin");
// header("Vary: Origin");
// header("Access-Control-Allow-Methods: POST, OPTIONS");
// header("Access-Control-Allow-Headers: Content-Type");
// header("Access-Control-Max-Age: 86400");
// if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
// // --- /CORS ---

// function out($code, $arr) {
//   http_response_code($code);
//   echo json_encode($arr, JSON_UNESCAPED_UNICODE);
//   exit;
// }

// $raw = file_get_contents('php://input');
// $data = json_decode($raw, true);
// if (!is_array($data)) out(400, ['error' => 'invalid json']);

// // token
// $token = trim((string)($data['token'] ?? ''));
// if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $token)) {
//   out(400, ['error' => 'bad token']);
// }

// // phone
// $phone_raw    = trim((string)($data['phone_raw'] ?? ''));
// $phone_e164   = trim((string)($data['phone_e164'] ?? ''));
// $phone_digits = trim((string)($data['phone_digits'] ?? ''));

// if ($phone_raw === '' && $phone_e164 === '' && $phone_digits === '') {
//   out(400, ['error' => 'missing phone (phone_raw/phone_e164/phone_digits)']);
// }

// // ensure dir
// $dir = __DIR__ . '/data';
// if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
//   out(500, ['error' => 'cannot create data dir']);
// }

// $record = [
//   'token' => $token,
//   'ts' => (int)($data['ts'] ?? (int)(microtime(true) * 1000)),
//   'ts_server' => time(),
//   'reason' => (string)($data['reason'] ?? 'unknown'),
//   'page_url' => (string)($data['page_url'] ?? ''),
//   'referrer' => (string)($data['referrer'] ?? ''),
//   'name' => (string)($data['name'] ?? ''),
//   'phone_raw' => $phone_raw,
//   'phone_digits' => $phone_digits,
//   'phone_e164' => $phone_e164,
//   'phone_is_valid' => $data['phone_is_valid'] ?? null,
//   'utm_source' => (string)($data['utm_source'] ?? ''),
//   'utm_campaign' => (string)($data['utm_campaign'] ?? ''),
//   'utm_term' => (string)($data['utm_term'] ?? ''),
//   'utm_content' => (string)($data['utm_content'] ?? ''),
// ];

// // atomic write
// $tmp = $dir . '/' . $token . '.json.tmp';
// $dst = $dir . '/' . $token . '.json';

// if (file_put_contents($tmp, json_encode($record, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
//   out(500, ['error' => 'write failed']);
// }
// if (!rename($tmp, $dst)) {
//   @unlink($tmp);
//   out(500, ['error' => 'rename failed']);
// }

// out(200, ['ok' => true, 'token' => $token]);