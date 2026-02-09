<?php
header('Content-Type: text/plain; charset=utf-8');
$path = __DIR__ . '/sign.txt';
echo file_exists($path) ? file_get_contents($path) : "sign.txt not found\n";