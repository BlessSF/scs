<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role(['admin', 'cashier']);

$branches = visible_branches(false);
if (!$branches) {
    flash('error', 'Create a branch first before recording duty entries.');
    redirect('/branches/form.php');
}
$branchId = scoped_branch_id($_GET['branch_id'] ?? $branches[0]['id']);
$branch = get_branch($branchId);
if (!$branch) { $branch = $branches[0]; $branchId = (int)$branch['id']; }
require_branch_access($branchId);

$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = max(1, min(12, $month));

$firstOfMonth = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth  = (int)date('t', strtotime($firstOfMonth));
$startWeekday = (int)date('w', strtotime($firstOfMonth)); // 0 = Sun

// Pull recorded duty days for this month, this branch.
$stmt = $pdo->prepare("SELECT dd.duty_date,
                               SUM(dd.total_amount) AS regular_amount,
                               SUM(COALESCE(ac.staff_count, 0)) AS regular_staff_count
                        FROM duty_days dd
                        LEFT JOIN (
                            SELECT da.duty_day_id, COUNT(DISTINCT da.staff_id) AS staff_count
                            FROM duty_attendance da
                            JOIN staff s ON s.id = da.staff_id
                            WHERE s.is_hidden = 0
                            GROUP BY da.duty_day_id
                        ) ac ON ac.duty_day_id = dd.id
                        WHERE dd.duty_date BETWEEN ? AND ? AND dd.branch_id = ?
                        GROUP BY dd.duty_date");
$stmt->execute([$firstOfMonth, date('Y-m-t', strtotime($firstOfMonth)), $branchId]);
$recorded = [];
foreach ($stmt->fetchAll() as $r) {
    $recorded[$r['duty_date']] = $r;
}

$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$pageTitle = 'Duty Entry';
require __DIR__ . '/../includes/header.php';
?>
<h1>Duty &amp; Service Charge Entry</h1>
<p class="subtitle">Click a day to record its total service charge and mark which staff were on duty for the selected branch.</p>

<div class="card">
    <?php if (is_branch_locked()): ?>
    <p style="margin:0 0 16px;"><strong><?= h($branch['name']) ?></strong></p>
    <?php else: ?>
    <form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:16px;">
        <input type="hidden" name="month" value="<?= $month ?>">
        <input type="hidden" name="year" value="<?= $year ?>">
        <label for="branch_id" style="margin:0;">Branch:</label>
        <select id="branch_id" name="branch_id" onchange="this.form.submit()" style="width:auto;">
            <?php foreach ($branches as $b): ?>
                <option value="<?= $b['id'] ?>" <?= $branchId === (int)$b['id'] ? 'selected' : '' ?>><?= h(branch_option_label($b)) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>

    <div class="actions" style="justify-content:space-between; align-items:center;">
        <a class="btn btn-sm btn-secondary" href="?branch_id=<?= $branchId ?>&month=<?= $prevMonth ?>&year=<?= $prevYear ?>">&larr; Prev</a>
        <h2 style="margin:0;"><?= date('F Y', strtotime($firstOfMonth)) ?></h2>
        <a class="btn btn-sm btn-secondary" href="?branch_id=<?= $branchId ?>&month=<?= $nextMonth ?>&year=<?= $nextYear ?>">Next &rarr;</a>
    </div>

    <div class="day-grid" id="dutyDayGrid" style="margin-top:18px;">
        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $wd): ?>
            <div class="small muted" style="text-align:center;font-weight:700;"><?= $wd ?></div>
        <?php endforeach; ?>

        <?php for ($i = 0; $i < $startWeekday; $i++): ?>
            <div class="day-cell empty"></div>
        <?php endfor; ?>

        <?php for ($d = 1; $d <= $daysInMonth; $d++):
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $rec = $recorded[$dateStr] ?? null;
            $dow = (int)date('w', strtotime($dateStr)); // 0=Sun .. 6=Sat
        ?>
            <a class="day-cell" data-dow="<?= $dow ?>" href="<?= BASE_URL ?>/duty/day.php?date=<?= $dateStr ?>&branch_id=<?= $branchId ?>">
                <span class="d-num"><?= $d ?></span>
                <?php if ($rec): ?>
                    <span class="d-amt"><?= money($rec['regular_amount']) ?></span>
                    <?php if (is_admin()): ?><span class="small muted"><?= (int)$rec['regular_staff_count'] ?> staff</span><?php endif; ?>
                <?php else: ?>
                    <span class="small muted">—</span>
                <?php endif; ?>
            </a>
        <?php endfor; ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>