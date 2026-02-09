<?php
// wfp-return.php

// 0) логируем ВНАЧАЛЕ (до exit)
@file_put_contents(
  __DIR__ . '/return.log',
  date('c')
  . " METHOD=" . ($_SERVER['REQUEST_METHOD'] ?? '')
  . " POST=" . json_encode($_POST, JSON_UNESCAPED_UNICODE)
  . " GET="  . json_encode($_GET,  JSON_UNESCAPED_UNICODE)
  . "\n",
  FILE_APPEND
);

// 1) достаем order_id / orderReference
$order_id = trim((string)(
  ($_GET['order_id'] ?? '')
  ?: ($_GET['orderReference'] ?? '')
  ?: ($_GET['order_reference'] ?? '')
));

if ($order_id === '' && !empty($_POST)) {
  $order_id = trim((string)(
    $_POST['orderReference'] ?? $_POST['order_reference'] ?? $_POST['order_id'] ?? ''
  ));
}

$base = 'https://opora.thesource.academy';

// 2) если нет order_id -> на pending (НЕ thanks)
if ($order_id === '' || strlen($order_id) > 80) {
  header("Location: {$base}/pending", true, 302);
  exit;
}

// 3) успех -> thanks
header("Location: {$base}/thanks?order_id=" . urlencode($order_id), true, 302);
exit;