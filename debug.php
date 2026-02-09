<?php
header('Content-Type: text/plain; charset=utf-8');
echo @file_get_contents(__DIR__.'/debug_amount.txt') ?: "no debug\n";