<?php
// Protected debug endpoint — requires DEBUG_SECRET env + ?key= query param
$secret = getenv('DEBUG_SECRET') ?: '';
if ($secret === '' || ($_GET['key'] ?? '') !== $secret) {
  http_response_code(403);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'forbidden']);
  exit;
}

header('Content-Type: text/plain; charset=utf-8');
$path = __DIR__ . '/sign.txt';
echo file_exists($path) ? file_get_contents($path) : "sign.txt not found\n";
