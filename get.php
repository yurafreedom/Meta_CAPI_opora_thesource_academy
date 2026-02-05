<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === 'https://opora.thesource.academy') {
  header("Access-Control-Allow-Origin: $origin");
  header("Vary: Origin");
}
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 86400");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
if (!$token || !preg_match('/^[a-f0-9\-]{16,64}$/i', $token)) {
  http_response_code(400);
  echo json_encode(['error' => 'bad token']);
  exit;
}

$dir = __DIR__ . '/tmp';
$file = "$dir/$token.json";
if (!file_exists($file)) {
  http_response_code(404);
  echo json_encode(['error' => 'not found']);
  exit;
}

$data = json_decode(file_get_contents($file), true);
if (!$data || empty($data['ph']) || empty($data['ts'])) {
  http_response_code(500);
  echo json_encode(['error' => 'corrupt']);
  exit;
}

// TTL 2 часа
if (time() - (int)$data['ts'] > 2*60*60) {
  @unlink($file);
  http_response_code(404);
  echo json_encode(['error' => 'expired']);
  exit;
}

echo json_encode(['ph' => $data['ph']]);