<?php

// --- CORS ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

// если хочешь строго — раскомментируй allowlist:
// $allowed = ['https://opora.thesource.academy', 'https://www.opora.thesource.academy'];
// if (in_array($origin, $allowed, true)) {
//   header("Access-Control-Allow-Origin: $origin");
// } else {
//   header("Access-Control-Allow-Origin: https://opora.thesource.academy");
// }

header("Access-Control-Allow-Origin: $origin");
header("Vary: Origin");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 86400");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}
// --- /CORS ---

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

$token = $payload['token'] ?? '';
$ph    = $payload['ph'] ?? '';

if (!$token || !$ph) {
  http_response_code(400);
  echo json_encode(['error' => 'missing token/ph']);
  exit;
}

// простая валидация: token и sha256 hex
if (!preg_match('/^[a-f0-9\-]{16,64}$/i', $token)) {
  http_response_code(400);
  echo json_encode(['error' => 'bad token']);
  exit;
}
if (!preg_match('/^[a-f0-9]{64}$/i', $ph)) {
  http_response_code(400);
  echo json_encode(['error' => 'bad ph hash']);
  exit;
}

/**
 * Вариант А (быстро и просто): хранить в файле tmp (НЕ идеально для прод)
 * Вариант Б (правильно): Redis/Upstash (рекомендую)
 */

// ==== Вариант А: tmp-файлы ====
$dir = __DIR__ . '/tmp';
if (!is_dir($dir)) mkdir($dir, 0777, true);

// ttl 2 часа
$record = ['ph' => strtolower($ph), 'ts' => time()];
file_put_contents("$dir/$token.json", json_encode($record));

echo json_encode(['ok' => true]);