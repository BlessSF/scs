<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_admin();

$rate = isset($_POST['rate']) ? (float)$_POST['rate'] : null;

if ($rate === null || $rate <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid rate value']);
    exit;
}

set_setting('overcost_rate', $rate);

echo json_encode([
    'success' => true,
    'rate'    => $rate,
    'message' => 'Overcost Rate updated to ' . $rate,
]);
