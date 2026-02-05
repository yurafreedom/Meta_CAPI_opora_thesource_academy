<?php
// return.php — принимает POST/GET от WayForPay и редиректит на Webflow /thanks (GET)

$THANKS_URL = 'https://opora.thesource.academy/thanks';

// Собираем входные параметры
$params = [];

// GET-параметры
if (!empty($_GET)) {
  foreach ($_GET as $k => $v) $params[$k] = $v;
}

// POST-параметры (WFP часто шлет POST)
if (!empty($_POST)) {
  foreach ($_POST as $k => $v) $params[$k] = $v;
}

// На всякий — если пришел JSON
$raw = file_get_contents('php://input');
if ($raw) {
  $json = json_decode($raw, true);
  if (is_array($json)) {
    foreach ($json as $k => $v) {
      // кладем только скалярные значения
      if (is_scalar($v)) $params[$k] = (string)$v;
    }
  }
}

// (опционально) добавим маячок, что редирект с прокладки
$params['via'] = 'wfp_return';

// Сформируем URL
$query = http_build_query($params);
$redirectTo = $THANKS_URL . ($query ? ('?' . $query) : '');

// 302 редирект
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $redirectTo, true, 302);
exit;