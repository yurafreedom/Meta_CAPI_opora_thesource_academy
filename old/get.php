<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: " . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header("Vary: Origin");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 86400");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$token = trim($_GET['token'] ?? '');
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $token)) {
  http_response_code(400); echo json_encode(['error' => 'bad token']); exit;
}

$path = __DIR__ . '/data/' . $token . '.json';
if (!is_file($path)) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }

readfile($path);