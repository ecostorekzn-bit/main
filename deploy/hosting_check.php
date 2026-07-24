<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$directory = __DIR__ . DIRECTORY_SEPARATOR . '.write_test_' . bin2hex(random_bytes(4));
$writable = false;
try {
    $writable = @file_put_contents($directory, 'ok', LOCK_EX) !== false;
    if ($writable) {
        @unlink($directory);
    }
} catch (Throwable $e) {
    $writable = false;
}

echo json_encode([
    'ok' => true,
    'php_version' => PHP_VERSION,
    'https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'extensions' => [
        'curl' => extension_loaded('curl'),
        'json' => extension_loaded('json'),
        'openssl' => extension_loaded('openssl'),
        'mbstring' => extension_loaded('mbstring'),
        'pdo_sqlite' => extension_loaded('pdo_sqlite'),
    ],
    'directory_writable' => $writable,
    'minimum_php_ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
