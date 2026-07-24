<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config = require dirname(__DIR__, 2) . '/ai_seller_config.php';
$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
if (!hash_equals((string)$config['event_token'], $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}
$commands = ['chromium', 'chromium-browser', 'google-chrome', 'wkhtmltopdf', 'python3'];
$found = [];
foreach ($commands as $command) {
    $output = [];
    $code = 1;
    @exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $output, $code);
    if ($code === 0 && !empty($output[0])) $found[$command] = $output[0];
}
echo json_encode([
    'ok' => true,
    'exec_available' => function_exists('exec'),
    'found' => $found,
], JSON_UNESCAPED_UNICODE);
