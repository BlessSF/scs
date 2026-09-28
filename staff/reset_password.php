<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role(['admin', 'cashier']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/staff/list.php');
}
csrf_verify();

$staffId = (int)($_POST['staff_id'] ?? 0);

$stmt = $pdo->prepare('SELECT u.*, s.full_name AS staff_name, s.branch_id AS staff_branch_id FROM users u JOIN staff s ON s.id = u.staff_id WHERE u.staff_id = ?');
$stmt->execute([$staffId]);
$account = $stmt->fetch();

if (!$account) {
    flash('error', 'That staff member does not have a login account.');
    redirect('/staff/list.php');
}

require_branch_access($account['staff_branch_id']);

// Generate a fresh random password. Only ever shown once, right now — after
// this it is hashed and the plaintext is discarded, same as any other account.
$newPassword = substr(bin2hex(random_bytes(5)), 0, 10);

$upd = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
$upd->execute([password_hash($newPassword, PASSWORD_DEFAULT), $account['id']]);

// One-time reveal via session only — never written to the database, a URL, or a log.
$_SESSION['password_reveal'] = [
    'staff_name' => $account['staff_name'],
    'username'   => $account['username'],
    'password'   => $newPassword,
];

flash('success', 'Password reset for ' . $account['staff_name'] . '.');
redirect('/staff/list.php');
