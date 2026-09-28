<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';

require_admin();

$periodId = (int)($_POST['period_id'] ?? 0);
$budget   = (float)($_POST['budget'] ?? 0);

if ($periodId > 0) {
    $stmt = $pdo->prepare('
        INSERT INTO period_budgets (period_id, budget)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE budget = VALUES(budget)
    ');
    $stmt->execute([$periodId, $budget]);
}

redirect('/periods/view.php?id=' . $periodId);
