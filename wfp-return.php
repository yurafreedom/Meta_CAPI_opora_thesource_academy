<?php
// wfp-return.php

@file_put_contents(
  __DIR__ . '/return.log',
  date('c') .
  " METHOD=" . ($_SERVER['REQUEST_METHOD'] ?? '') .
  " POST=" . json_encode($_POST, JSON_UNESCAPED_UNICODE) .
  " GET="  . json_encode($_GET,  JSON_UNESCAPED_UNICODE) .
  "\n",
  FILE_APPEND
);

function envv($k, $default = '') {
  $v = getenv($k);
  return ($v !== false && $v !== '') ? $v : $default;
}

$order_id = trim((string)($_GET['order_id'] ?? ''));
if ($order_id === '') {
  $order_id = trim((string)($_POST['orderReference'] ?? $_POST['order_reference'] ?? ''));
}

if ($order_id === '' || strlen($order_id) > 80) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo "missing order_id";
  exit;
}

$thanks = rtrim(envv('THANKS_URL', 'https://opora.thesource.academy/thanks'), '/');
header('Location: ' . $thanks . '?order_id=' . urlencode($order_id), true, 302);
exit;