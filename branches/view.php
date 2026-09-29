<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role(['admin', 'cashier']);

$requestedId = (int)($_GET['id'] ?? 0);
$branchId = scoped_branch_id($requestedId);
$branch = $branchId ? get_branch($branchId) : null;
if (!$branch) {
    flash('error', 'Branch not found.');
    redirect('/dashboard.php');
}
require_branch_access($branchId);

$isCashier = is_cashier();
$key = branch_theme_key($branch['name']);

$today = date('Y-m-d');
$thisMonthStart = date('Y-m-01');
$thisMonthEnd   = date('Y-m-t');

$stmt = $pdo->prepare('SELECT COALESCE(SUM(total_amount),0) FROM duty_days WHERE branch_id = ? AND duty_date = ?');
$stmt->execute([$branchId, $today]);
$todayTotal = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM duty_days WHERE branch_id = ? AND duty_date = ? AND shift = 'bar_night'");
$stmt->execute([$branchId, $today]);
$todayBarNight = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COALESCE(SUM(total_amount),0) FROM duty_days WHERE branch_id = ? AND duty_date BETWEEN ? AND ?');
$stmt->execute([$branchId, $thisMonthStart, $thisMonthEnd]);
$monthTotal = (float)$stmt->fetchColumn();

$staffList = staff_for_branch($branchId, false);
$activeStaffCount = 0;
foreach ($staffList as $s) { if ($s['is_active']) $activeStaffCount++; }

$openPeriodsCount = 0;
if (!$isCashier) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM periods WHERE branch_id = ? AND status = 'open'");
    $stmt->execute([$branchId]);
    $openPeriodsCount = (int)$stmt->fetchColumn();
}

$subBranches = get_sub_branches($branchId, false);

$pageTitle = $branch['name'];
require __DIR__ . '/../includes/header.php';
?>

<p style="margin-bottom:10px;"><a href="<?= BASE_URL ?>/dashboard.php" class="muted">← Back to Dashboard</a></p>

<div class="hero-banner branch-<?= h($key) ?>">
    <h1><?= h($branch['name']) ?></h1>
    <p class="subtitle">
        <?= h($branch['address'] ?: 'No address on file') ?>
        <?php if (!empty($branch['parent_branch_id'])):
            $parent = get_branch($branch['parent_branch_id']);
        ?>
            &middot; Sub-branch of <?= h($parent['name'] ?? '') ?>
        <?php endif; ?>
    </p>
    <div class="hero-meta">
        <div class="hero-meta-item"><span class="live-dot"></span> <?= h(date('l, F j, Y')) ?></div>
        <?php if (!$branch['is_active']): ?>
            <div class="hero-meta-item"><span class="badge badge-resigned">Inactive</span></div>
        <?php endif; ?>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon">👥</div>
        <div class="stat-value"><?= $activeStaffCount ?></div>
        <div class="stat-label">Active Staff</div>
    </div>
    <?php if (!$isCashier): ?>
    <div class="stat-card">
        <div class="stat-icon">🗓️</div>
        <div class="stat-value"><?= money($todayTotal) ?></div>
        <div class="stat-label">Gross SC — Today</div>
        <?php if ($todayBarNight > 0): ?>
            <div class="small muted" style="margin-top:4px;">🌙 Bar Night: <?= money($todayBarNight) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (!$isCashier): ?>
    <div class="stat-card">
        <div class="stat-icon">💰</div>
        <div class="stat-value"><?= money($monthTotal) ?></div>
        <div class="stat-label">Gross SC — This Month</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📆</div>
        <div class="stat-value"><?= $openPeriodsCount ?></div>
        <div class="stat-label">Open Periods</div>
    </div>
    <?php endif; ?>
</div>

<div class="section-heading">
    <h2>Quick Actions</h2>
</div>
<div class="quick-grid">
    <a class="quick-tile" href="<?= BASE_URL ?>/duty/calendar.php?branch_id=<?= $branchId ?>">
        <span class="qt-icon">📝</span>
        <span class="qt-title">Record Today's Service Charge</span>
        <span class="qt-desc">Log the day's total and mark attendance</span>
    </a>
    <a class="quick-tile" href="<?= BASE_URL ?>/staff/list.php?branch_id=<?= $branchId ?>">
        <span class="qt-icon">👥</span>
        <span class="qt-title">Manage Staff</span>
        <span class="qt-desc">Staff records for this branch</span>
    </a>
    <?php if (!$isCashier): ?>
    <a class="quick-tile" href="<?= BASE_URL ?>/periods/list.php?branch_id=<?= $branchId ?>">
        <span class="qt-icon">📊</span>
        <span class="qt-title">Periods</span>
        <span class="qt-desc">Close cycles and view payouts</span>
    </a>
    <?php endif; ?>
</div>

<?php if ($subBranches): ?>
<div class="section-heading">
    <h2>Sub-Branches</h2>
</div>
<div class="branch-grid">
    <?php foreach ($subBranches as $sb): $sbKey = branch_theme_key($sb['name']); ?>
        <a href="<?= BASE_URL ?>/branches/view.php?id=<?= (int)$sb['id'] ?>" class="branch-card branch-<?= h($sbKey) ?>">
            <div class="bc-top">
                <span class="bc-dot"></span>
                <span class="bc-name"><?= h($sb['name']) ?></span>
                <span class="bc-check">→</span>
            </div>
            <div class="bc-row"><span class="k">Active staff</span><span class="v"><?= staff_active_count_for_branch($sb['id']) ?></span></div>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="section-heading">
    <h2>Staff</h2>
    <span class="hint"><?= count($staffList) ?> staff member<?= count($staffList) === 1 ? '' : 's' ?></span>
</div>
<div class="card">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Also assigned to</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($staffList as $s):
            $names = staff_branch_names($s['id'], $s['branch_id']);
            $others = array_slice($names, (int)$s['branch_id'] === $branchId ? 1 : 0);
            // Don't repeat this branch's own name in the "also assigned to" list.
            $others = array_values(array_filter($others, function ($n) use ($branch) { return $n !== $branch['name']; }));
        ?>
            <tr>
                <td>
                    <?= h($s['full_name']) ?>
                    <?php if (!$s['is_active']): ?><span class="muted small">Inactive</span><?php endif; ?>
                </td>
                <td class="small">
                    <?php if ($others): ?>
                        <?php foreach ($others as $n): ?><span class="badge badge-regular" style="margin:1px 2px 1px 0;"><?= h($n) ?></span><?php endforeach; ?>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge badge-<?= h($s['status']) ?>"><?= h(ucfirst(str_replace('_',' ',$s['status']))) ?></span></td>
                <td><a class="btn btn-sm btn-secondary" href="<?= BASE_URL ?>/staff/form.php?id=<?= $s['id'] ?>">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$staffList): ?>
            <tr><td colspan="4" class="muted" style="text-align:center; padding:30px;">No staff assigned to this branch yet. <a href="<?= BASE_URL ?>/staff/form.php?branch_id=<?= $branchId ?>">Add staff</a>.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>