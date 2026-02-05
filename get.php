<?php
// get.php
header('Content-Type: application/json');

// --- CORS ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header("Vary: Origin");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 86400");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
// --- /CORS ---

function j($code, $arr) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') j(400, ['error' => 'bad token']);

try {
  $dbPath = __DIR__ . '/storage.sqlite';
  $pdo = new PDO('sqlite:' . $dbPath);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $pdo->exec("CREATE TABLE IF NOT EXISTS leads (token TEXT PRIMARY KEY, json TEXT NOT NULL, updated_at INTEGER NOT NULL);");

  $stmt = $pdo->prepare("SELECT json FROM leads WHERE token = :token LIMIT 1");
  $stmt->execute([':token' => $token]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) j(404, ['error' => 'not found']);
  echo $row['json'];
  exit;
} catch (Throwable $e) {
  j(500, ['error' => 'db error', 'details' => $e->getMessage()]);
}