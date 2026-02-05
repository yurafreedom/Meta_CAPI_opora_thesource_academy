<?php
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