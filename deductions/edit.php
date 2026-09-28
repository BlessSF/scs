<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_admin();

$periodId = (int)($_GET['period_id'] ?? $_POST['period_id'] ?? 0);
$staffId  = (int)($_GET['staff_id']  ?? $_POST['staff_id']  ?? 0);

$stmt = $pdo->prepare('SELECT * FROM periods WHERE id = ?');
$stmt->execute([$periodId]);
$period = $stmt->fetch();

$stmt = $pdo->prepare('SELECT * FROM staff WHERE id = ?');
$stmt->execute([$staffId]);
$staff = $stmt->fetch();

if (!$period || !$staff) {
    flash('error', 'Period or staff not found.');
    redirect('/periods/list.php');
}

$stmt = $pdo->prepare('SELECT * FROM deductions WHERE period_id = ? AND staff_id = ?');
$stmt->execute([$periodId, $staffId]);
$ded = $stmt->fetch() ?: ['damages_charges' => 0, 'cash_advance' => 0, 'overcost_cogs' => 0, 'remarks' => '', 'received_by' => ''];

// Overcost is calculated automatically from the period's Original Budget
// and its own Gross Service Charge total -- it's not typed in as a manual
// field here -- it's the same auto-computed, evenly-split value shown on
// the period summary and this staff member's slip.
$slip = staff_period_slip($pdo, $periodId, $staffId);
$autoOvercost = $slip ? $slip['row']['overcost_cogs'] : 0.0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $damages = (float)($_POST['damages_charges'] ?? 0);
    $advance = (float)($_POST['cash_advance'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');
    $receivedBy = trim($_POST['received_by'] ?? '');

    // overcost_cogs is intentionally left out of the INSERT/UPDATE -- it's
    // no longer stored per-staff; it's computed on the fly (see period_overcost()
    // / period_summary() in includes/functions.php) wherever it's displayed.
    $stmt = $pdo->prepare(
        'INSERT INTO deductions (period_id, staff_id, damages_charges, cash_advance, remarks, received_by)
         VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE damages_charges=VALUES(damages_charges), cash_advance=VALUES(cash_advance),
             remarks=VALUES(remarks), received_by=VALUES(received_by)'
    );
    $stmt->execute([$periodId, $staffId, $damages, $advance, $remarks, $receivedBy]);

    flash('success', 'Deductions updated for ' . $staff['full_name'] . '.');
    redirect('/periods/view.php?id=' . $periodId);
}

$pageTitle = 'Deductions — ' . $staff['full_name'];
require __DIR__ . '/../includes/header.php';
?>
<h1>Deductions</h1>
<p class="subtitle"><?= h($staff['full_name']) ?> &middot; <?= h($period['name']) ?></p>

<div class="card">
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="period_id" value="<?= $periodId ?>">
        <input type="hidden" name="staff_id" value="<?= $staffId ?>">

        <div class="form-row">
            <div>
                <label for="damages_charges">Damages &amp; Charges (food, etc.)</label>
                <input type="number" step="0.01" min="0" id="damages_charges" name="damages_charges" value="<?= h($ded['damages_charges']) ?>">
            </div>
            <div>
                <label for="cash_advance">Cash Advance</label>
                <input type="number" step="0.01" min="0" id="cash_advance" name="cash_advance" value="<?= h($ded['cash_advance']) ?>">
            </div>
            <div>
                <label for="overcost_cogs">Overcost / COGS <span class="muted">(auto)</span></label>
                <input type="text" id="overcost_cogs" value="<?= h(money($autoOvercost)) ?>" disabled>
                <p class="muted" style="margin:4px 0 0; font-size:12px;">
                    Calculated automatically from the Original Budget (a default set once in
                    <a href="<?= BASE_URL ?>/settings.php">Settings</a>) and this period's Gross Service Charge total, split evenly across staff. No manual entry needed.
                </p>
            </div>
        </div>

        <label for="received_by">Received By (for the printed slip)</label>
        <input type="text" id="received_by" name="received_by" value="<?= h($ded['received_by']) ?>">

        <label for="remarks">Remarks</label>
        <textarea id="remarks" name="remarks" rows="2"><?= h($ded['remarks']) ?></textarea>

        <div class="actions" style="margin-top:20px;">
            <button type="submit" class="btn">Save Deductions</button>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/periods/view.php?id=<?= $periodId ?>">Back to Summary</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
