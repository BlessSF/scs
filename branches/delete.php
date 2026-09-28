<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/branches/list.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM branches WHERE id = ?');
$stmt->execute([$id]);
$branch = $stmt->fetch();

if (!$branch) {
    flash('error', 'Branch not found.');
    redirect('/branches/list.php');
}

// Check for anything still attached to this branch
$stmt = $pdo->prepare('SELECT COUNT(*) FROM staff WHERE branch_id = ?');
$stmt->execute([$id]);
$staffCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM duty_days WHERE branch_id = ?');
$stmt->execute([$id]);
$dutyCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM periods WHERE branch_id = ?');
$stmt->execute([$id]);
$periodCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE parent_branch_id = ?');
$stmt->execute([$id]);
$subBranchCount = (int)$stmt->fetchColumn();

if ($staffCount > 0 || $dutyCount > 0 || $periodCount > 0 || $subBranchCount > 0) {
    $parts = [];
    if ($staffCount) $parts[] = "$staffCount staff member(s)";
    if ($dutyCount) $parts[] = "$dutyCount duty day(s)";
    if ($periodCount) $parts[] = "$periodCount period(s)";
    if ($subBranchCount) $parts[] = "$subBranchCount sub-branch(es)";
    flash('error', 'Cannot delete "' . $branch['name'] . '" — it still has ' . implode(', ', $parts) .
        ' attached. Move or remove those first, or just mark the branch Inactive instead.');
    redirect('/branches/list.php');
}

try {
    // This branch's login account has no history of its own (duty/period/staff
    // records are already confirmed empty above), so it's safe to drop too.
    $pdo->prepare("DELETE FROM users WHERE branch_id = ? AND role = 'cashier'")->execute([$id]);

    $stmt = $pdo->prepare('DELETE FROM branches WHERE id = ?');
    $stmt->execute([$id]);
    flash('success', 'Branch "' . $branch['name'] . '" and its login account were deleted.');
} catch (PDOException $e) {
    flash('error', 'Could not delete this branch: ' . $e->getMessage());
}

redirect('/branches/list.php');
