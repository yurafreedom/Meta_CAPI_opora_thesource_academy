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

$path = __DIR__ . '/meta_graph.log';
if (!is_file($path)) {
  http_response_code(404);
  echo "meta_graph.log not found\n";
  exit;
}

readfile($path);
