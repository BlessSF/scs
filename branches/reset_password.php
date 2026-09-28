<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/branches/list.php');
}
csrf_verify();

$branchId = (int)($_POST['branch_id'] ?? 0);
$branch = get_branch($branchId);
if (!$branch) {
    flash('error', 'Branch not found.');
    redirect('/branches/list.php');
}

$account = get_branch_account($branchId);
if (!$account) {
    // No account yet (e.g. a branch created before this feature) — create one now.
    $created = create_branch_account($branchId, $branch['name']);
    $_SESSION['password_reveal'] = [
        'staff_name' => $branch['name'] . ' (branch account)',
        'username'   => $created['username'],
        'password'   => $created['password'],
    ];
    flash('success', 'Login account created for "' . $branch['name'] . '".');
    redirect('/branches/list.php');
}

// Generate a fresh random password. Only ever shown once, right now — after
// this it is hashed and the plaintext is discarded, same as staff accounts.
$newPassword = substr(bin2hex(random_bytes(5)), 0, 10);

$upd = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
$upd->execute([password_hash($newPassword, PASSWORD_DEFAULT), $account['id']]);

$_SESSION['password_reveal'] = [
    'staff_name' => $branch['name'] . ' (branch account)',
    'username'   => $account['username'],
    'password'   => $newPassword,
];

flash('success', 'Password reset for "' . $branch['name'] . '".');
redirect('/branches/list.php');
