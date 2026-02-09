<?php
header('Content-Type: text/plain; charset=utf-8');

$path = __DIR__ . '/meta_graph.log';
if (!is_file($path)) {
  http_response_code(404);
  echo "meta_graph.log not found\n";
  exit;
}

readfile($path);