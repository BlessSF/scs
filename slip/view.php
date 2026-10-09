<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_login();

$periodId = (int)($_GET['period_id'] ?? 0);
$staffId  = (int)($_GET['staff_id'] ?? 0);
$shift = $_GET['shift'] ?? '';
$shift = in_array($shift, ['regular', 'bar_night'], true) ? $shift : null;
$user = current_user();

// Slips were removed from the staff-facing portal entirely -- block direct
// links too, not just the nav/dashboard entry points.
if ($user['role'] === 'staff') {
    redirect('/dashboard.php');
}

// Staff may only view their own slip
if ($user['role'] !== 'admin') {
    if ((int)$user['staff_id'] !== $staffId) {
        http_response_code(403);
        die('Access denied.');
    }
}

$slip = staff_period_slip($pdo, $periodId, $staffId, $shift);
if (!$slip) {
    flash('error', 'Slip not found for that staff/period combination.');
    redirect($user['role'] === 'admin' ? '/periods/list.php' : '/portal/my-slips.php');
}
$period = $slip['period'];
$row = $slip['row'];
$branch = get_branch($period['branch_id']);

// If this person is limited to certain months (Staff > Edit > "Only Count in
// These Months"), list those months so the slip shows what was counted.
$countedLabel = '';
try {
    $cmStmt = $pdo->prepare('SELECT counted_months FROM staff WHERE id = ?');
    $cmStmt->execute([$staffId]);
    $cmRaw = (string)$cmStmt->fetchColumn();
    $cmNames = [];
    foreach (array_filter(array_map('trim', explode(',', $cmRaw)), 'strlen') as $ym) {
        $ts = strtotime($ym . '-01');
        if (!$ts) continue;
        // Only months that fall inside this period's date range
        if (date('Y-m', $ts) < date('Y-m', strtotime($period['start_date']))) continue;
        if (date('Y-m', $ts) > date('Y-m', strtotime($period['end_date']))) continue;
        $cmNames[] = date('F Y', $ts);
    }
    $countedLabel = implode(', ', $cmNames);
} catch (Exception $e) {
    $countedLabel = '';
}

$pageTitle = 'Slip — ' . $row['full_name'];
require __DIR__ . '/../includes/header.php';
?>
<div class="actions no-print">
    <button class="btn" onclick="window.print()">Print This Slip</button>
    <a class="btn btn-secondary" href="<?= $user['role']==='admin' ? BASE_URL.'/periods/view.php?id='.$period['id'].($shift ? '&shift='.urlencode($shift) : '') : BASE_URL.'/portal/my-slips.php' ?>">Back</a>
</div>

<div class="slip">
    <h2><?= h(get_setting('company_name', '')) ?> Service Charge Slip</h2>

    <div class="slip-row"><span>Name</span><strong><?= h($row['full_name']) ?></strong></div>
    <div class="slip-row"><span>Branch</span><span><?= h($branch['name'] ?? '') ?></span></div>
    <div class="slip-row"><span>Covered Period</span><span><?= h(date('M j', strtotime($period['start_date']))) ?> &ndash; <?= h(date('M j, Y', strtotime($period['end_date']))) ?></span></div>
    <?php if ($countedLabel !== ''): ?>
    <div class="slip-row"><span>Months Counted</span><strong><?= h($countedLabel) ?></strong></div>
    <?php endif; ?>
    <div class="slip-row"><span>Days on Duty</span><span><?= (int)$row['regular_days'] + (int)$row['bar_night_days'] ?></span></div>
    <div class="slip-row"><span>Regular Day SC (<?= (int)$row['regular_days'] ?> day<?= $row['regular_days'] === 1 ? '' : 's' ?>)</span><span><?= money($row['regular_sc']) ?></span></div>
    <div class="slip-row"><span>Bar Night SC (<?= (int)$row['bar_night_days'] ?> day<?= $row['bar_night_days'] === 1 ? '' : 's' ?>)</span><span><?= money($row['bar_night_sc']) ?></span></div>
    <div class="slip-row total"><span>GROSS SERVICE CHARGE</span><span><?= money($row['gross']) ?></span></div>

    <?php if ((float)$row['damages_charges'] > 0): ?>
    <div class="slip-row"><span>Damages &amp; Charges</span><span>&minus; <?= money($row['damages_charges']) ?></span></div>
    <?php endif; ?>
    <?php if ((float)$row['cash_advance'] > 0): ?>
    <div class="slip-row"><span>Cash Advance</span><span>&minus; <?= money($row['cash_advance']) ?></span></div>
    <?php endif; ?>
    <div class="slip-row"><span>Overcost / COGS</span><span>&minus; <?= money($row['overcost_cogs']) ?></span></div>

    <div class="slip-row total"><span>NET</span><span><?= money($row['net']) ?></span></div>

    <div class="slip-sig">
        <div>
            <span class="slip-sig-name"><?= h($row['full_name']) ?></span>
            Received By
        </div>
        <div>
            <span class="slip-sig-name"><?= h(get_setting('approved_by_name', '')) ?></span>
            Approved By
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>