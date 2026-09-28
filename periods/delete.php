<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/periods/list.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM periods WHERE id = ?');
$stmt->execute([$id]);
$period = $stmt->fetch();

if (!$period) {
    flash('error', 'Period not found.');
    redirect('/periods/list.php');
}

require_branch_access($period['branch_id']);

try {
    // Deductions tied to this period are removed automatically
    // (ON DELETE CASCADE on deductions.period_id). The underlying
    // duty day / attendance records are date-based and untouched —
    // deleting a period only removes the payout-cycle wrapper.
    $stmt = $pdo->prepare('DELETE FROM periods WHERE id = ?');
    $stmt->execute([$id]);
    flash('success', 'Period "' . $period['name'] . '" deleted.');
} catch (PDOException $e) {
    flash('error', 'Could not delete this period: ' . $e->getMessage());
}

redirect('/periods/list.php');