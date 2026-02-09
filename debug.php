<?php
header('Content-Type: text/plain; charset=utf-8');
echo "== sign.txt ==\n";
echo @file_get_contents(__DIR__.'/sign.txt') ?: "NO sign\n";
echo "\n== debug_amount.txt ==\n";
echo @file_get_contents(__DIR__.'/debug_amount.txt') ?: "NO debug\n";