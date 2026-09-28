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

$periodId = (int)($_POST['period_id'] ?? 0);
$budget   = isset($_POST['budget']) ? (float)$_POST['budget'] : null;

if (!$periodId || $budget === null || $budget < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$stmt = $pdo->prepare('UPDATE periods SET original_budget = ? WHERE id = ?');
$stmt->execute([$budget, $periodId]);

echo json_encode([
    'success' => true,
    'budget'  => $budget,
    'message' => 'Budget updated successfully',
]);
