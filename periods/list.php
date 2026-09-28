<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_admin();

$branches = visible_branches(false);
$branchId = scoped_branch_id($_GET['branch_id'] ?? 0);

$sql = 'SELECT p.*, b.name AS branch_name FROM periods p JOIN branches b ON b.id = p.branch_id';
$params = [];
if ($branchId) {
    $sql .= ' WHERE p.branch_id = ?';
    $params[] = $branchId;
}
$sql .= ' ORDER BY p.start_date DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$periods = $stmt->fetchAll();

$pageTitle = 'Periods';
require __DIR__ . '/../includes/header.php';
?>
<h1>Periods</h1>
<p class="subtitle">A period defines a payout cycle (e.g. a month or a quarter) for one branch. Create one to generate the service-charge summary and printable slips for that branch's staff over that date range.</p>

<div class="actions" style="justify-content:space-between;">
    <a class="btn" href="<?= BASE_URL ?>/periods/form.php">+ New Period</a>
    <?php if (!is_branch_locked()): ?>
    <form method="get" style="display:flex;gap:8px;align-items:center;">
        <label for="branch_id" style="margin:0;">Branch:</label>
        <select id="branch_id" name="branch_id" onchange="this.form.submit()" style="width:auto;">
            <option value="0">All Branches</option>
            <?php foreach ($branches as $b): ?>
                <option value="<?= $b['id'] ?>" <?= $branchId === (int)$b['id'] ? 'selected' : '' ?>><?= h(branch_option_label($b)) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <table>
        <thead>
            <tr><th>Name</th><th>Branch</th><th>Date Range</th><th>Mgmt Share %</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($periods as $p): ?>
            <tr>
                <td><?= h($p['name']) ?></td>
                <td><?= h($p['branch_name']) ?></td>
                <td><?= h(date('M j, Y', strtotime($p['start_date']))) ?> &ndash; <?= h(date('M j, Y', strtotime($p['end_date']))) ?></td>
                <td><?= h($p['management_share_percent']) ?>%</td>
                <td><span class="badge <?= $p['status']==='open' ? 'badge-regular' : 'badge-resigned' ?>"><?= h(ucfirst($p['status'])) ?></span></td>
                <td>
                    <a class="btn btn-sm" href="<?= BASE_URL ?>/periods/view.php?id=<?= $p['id'] ?>">View Summary</a>
                    <a class="btn btn-sm btn-secondary" href="<?= BASE_URL ?>/periods/form.php?id=<?= $p['id'] ?>">Edit</a>
                    <form method="post" action="<?= BASE_URL ?>/periods/delete.php" style="display:inline;" onsubmit="return confirm('Delete period &quot;<?= h(addslashes($p['name'])) ?>&quot;? This also removes any deductions recorded for it. This cannot be undone.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$periods): ?>
            <tr><td colspan="6" class="muted">No periods yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>