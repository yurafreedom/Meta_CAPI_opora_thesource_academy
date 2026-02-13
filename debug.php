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
echo "== sign.txt ==\n";
echo @file_get_contents(__DIR__.'/sign.txt') ?: "NO sign\n";
echo "\n== debug_amount.txt ==\n";
echo @file_get_contents(__DIR__.'/debug_amount.txt') ?: "NO debug\n";
