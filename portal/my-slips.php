<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_login();

$user = current_user();
// My Slips is no longer available to staff accounts -- attendance is all
// they see now; slips stay admin-only territory.
if ($user['role'] === 'admin' || $user['role'] === 'staff') { redirect('/dashboard.php'); }
$staffId = $user['staff_id'];

$stmt = $pdo->prepare('SELECT branch_id FROM staff WHERE id = ?');
$stmt->execute([$staffId]);
$myBranchId = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT * FROM periods WHERE branch_id = ? ORDER BY start_date DESC');
$stmt->execute([$myBranchId]);
$periods = $stmt->fetchAll();

$pageTitle = 'My Slips';
require __DIR__ . '/../includes/header.php';
?>
<h1>My Slips</h1>
<p class="subtitle">Select a period to view or print your service charge slip.</p>

<div class="card">
    <table>
        <thead><tr><th>Period</th><th>Date Range</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($periods as $p): ?>
            <tr>
                <td><?= h($p['name']) ?></td>
                <td><?= h(date('M j, Y', strtotime($p['start_date']))) ?> &ndash; <?= h(date('M j, Y', strtotime($p['end_date']))) ?></td>
                <td><span class="badge <?= $p['status']==='open' ? 'badge-regular' : 'badge-resigned' ?>"><?= h(ucfirst($p['status'])) ?></span></td>
                <td><a class="btn btn-sm" href="<?= BASE_URL ?>/slip/view.php?period_id=<?= $p['id'] ?>&staff_id=<?= $staffId ?>">View Slip</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$periods): ?>
            <tr><td colspan="4" class="muted">No periods have been created yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
