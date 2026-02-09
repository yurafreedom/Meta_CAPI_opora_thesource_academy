<?php
// wfp-return.php — принимает возврат от WFP (часто POST) и редиректит на сайт

$order_id = $_GET['order_id'] ?? '';
// WFP иногда шлёт orderReference / order_reference в POST
if ($order_id === '' && !empty($_POST)) {
  $order_id = $_POST['orderReference'] ?? ($_POST['order_reference'] ?? '');
}

if ($order_id === '') {
  // на всякий — пусть ведёт на thanks без id, но без 405
  header("Location: https://opora.thesource.academy/thanks", true, 302);
  exit;
}

header("Location: https://opora.thesource.academy/thanks?order_id=" . urlencode($order_id), true, 302);
exit;