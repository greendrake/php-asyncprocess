<?php
declare (strict_types = 1);
require_once __DIR__ . '/../vendor/autoload.php';
set_error_handler(function ($severity, $message, $file, $line) {
	throw new \ErrorException($message, 0, $severity, $file, $line);
});