<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$shift = $_GET['shift'] ?? '';
$shift = in_array($shift, ['regular', 'bar_night'], true) ? $shift : null;

$summary = period_summary($pdo, $id, $shift);
if (!$summary) {
    flash('error', 'Period not found.');
    redirect('/periods/list.php');
}
$period = $summary['period'];
require_branch_access($period['branch_id']);
$branch = get_branch($period['branch_id']);
$rows = $summary['rows'];
$totals = $summary['totals'];
$overcost = $summary['overcost'];

$qs = $shift ? '&shift=' . urlencode($shift) : '';

$pageTitle = $period['name'];
require __DIR__ . '/../includes/header.php';
?>
<h1><?= h($period['name']) ?></h1>
<p class="subtitle">
    <strong><?= h($branch['name'] ?? '') ?></strong>
    &middot; <?= h(date('M j, Y', strtotime($period['start_date']))) ?> &ndash; <?= h(date('M j, Y', strtotime($period['end_date']))) ?>
    &middot; Management share: <?= h($period['management_share_percent']) ?>%
    &middot; <span class="badge <?= $period['status']==='open' ? 'badge-regular' : 'badge-resigned' ?>"><?= h(ucfirst($period['status'])) ?></span>
    <?php if ($shift): ?>
        &middot; <span class="badge badge-regular">Filtered: <?= h($shift === 'bar_night' ? 'Bar Night only' : 'Regular day only') ?></span>
    <?php endif; ?>
</p>

<div class="day-mode-toggle" style="margin: 0 0 18px;">
    <a class="btn-mode<?= $shift === null ? ' is-active' : '' ?>" href="<?= BASE_URL ?>/periods/view.php?id=<?= $period['id'] ?>">All Sales</a>
    <a class="btn-mode<?= $shift === 'regular' ? ' is-active' : '' ?>" data-mode="regular" href="<?= BASE_URL ?>/periods/view.php?id=<?= $period['id'] ?>&shift=regular">☀️ Regular Day Only</a>
    <a class="btn-mode<?= $shift === 'bar_night' ? ' is-active' : '' ?>" data-mode="bar_night" href="<?= BASE_URL ?>/periods/view.php?id=<?= $period['id'] ?>&shift=bar_night">🌙 Bar Night Only</a>
</div>

<div class="actions">
    <a class="btn btn-secondary" href="<?= BASE_URL ?>/periods/form.php?id=<?= $period['id'] ?>">Edit Period</a>
    <a class="btn btn-secondary" href="<?= BASE_URL ?>/periods/list.php">Back to List</a>
    <a class="btn" href="<?= BASE_URL ?>/periods/export_csv.php?id=<?= $period['id'] ?><?= $qs ?>">⬇ Export CSV</a>
    <a class="btn" href="<?= BASE_URL ?>/periods/export_excel.php?id=<?= $period['id'] ?><?= $qs ?>">⬇ Export Excel</a>
    <a class="btn" href="<?= BASE_URL ?>/periods/export_print.php?id=<?= $period['id'] ?><?= $qs ?>" target="_blank" rel="noopener">🖨 Print / PDF</a>
    <a class="btn" href="<?= BASE_URL ?>/periods/print_all_slips.php?id=<?= $period['id'] ?><?= $qs ?>" target="_blank" rel="noopener">🧾 Print All Slips</a>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-value"><?= money($totals['total_sc']) ?></div><div class="stat-label">Total SC (before mgmt share)</div></div>
    <div class="stat-card"><div class="stat-value"><?= money($totals['management_share']) ?></div><div class="stat-label">Management Share</div></div>
    <div class="stat-card"><div class="stat-value"><?= money($totals['gross']) ?></div><div class="stat-label">Gross Service Charge</div></div>
    <div class="stat-card"><div class="stat-value"><?= money($totals['net']) ?></div><div class="stat-label">Total Net Contribution</div></div>
</div>

<div class="card">
    <h2>Overcost (auto-calculated)</h2>
    <p class="muted" style="margin-top:-6px;">Set the <strong>Budget</strong> for this period. Overcost Rate = Total Gross &divide; Budget. Each employee's Overcost = their Gross &times; Rate.</p>
    <div class="stat-grid" style="margin-bottom:1rem;">
        <div class="stat-card">
            <div class="stat-label" style="margin-bottom:4px;">Budget (editable)</div>
            <form method="POST" action="<?= BASE_URL ?>/periods/budget_save.php" style="display:flex;align-items:center;gap:8px;">
                <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                <input type="number" name="budget" step="0.01" min="0"
                    value="<?= h($overcost['budget']) ?>"
                    style="font-size:1.25rem;font-weight:700;color:var(--primary);border:1px solid var(--border);border-radius:6px;padding:4px 8px;width:160px;">
                <button type="submit" class="btn">Save</button>
            </form>
        </div>
        <div class="stat-card"><div class="stat-value"><?= number_format($overcost['rate'], 8) ?></div><div class="stat-label">Overcost Rate (Gross ÷ Budget)</div></div>
        <div class="stat-card"><div class="stat-value"><?= money($overcost['actual']) ?></div><div class="stat-label">Total Gross (all staff)</div></div>
        <div class="stat-card"><div class="stat-value"><?= money($overcost['overcost']) ?></div><div class="stat-label">Total Overcost (all staff)</div></div>
    </div>
</div>




<div class="card">
    <h2>Staff Breakdown</h2>
    <div>
    <table style="width:100%; table-layout:fixed; font-size:0.75rem; border-collapse:collapse;">
        <colgroup>
            <col style="width:13%"><!-- Name -->
            <col style="width:8%"> <!-- Status -->
            <col style="width:5%"> <!-- Reg Days -->
            <col style="width:8%"> <!-- Reg SC -->
            <col style="width:5%"> <!-- Bar Days -->
            <col style="width:8%"> <!-- Bar SC -->
            <col style="width:8%"> <!-- Total SC -->
            <col style="width:7%"> <!-- Mgmt% -->
            <col style="width:8%"> <!-- Gross -->
            <col style="width:6%"> <!-- Dmg -->
            <col style="width:7%"> <!-- Cash Adv -->
            <col style="width:7%"> <!-- Overcost -->
            <col style="width:8%"> <!-- Net -->
            <col style="width:6%"> <!-- Actions -->
        </colgroup>
        <thead>
            <tr>
                <th>Name</th>
                <th>Status</th>
                <th class="text-right">Days</th>
                <th class="text-right">Reg. SC</th>
                <th class="text-right">Bar</th>
                <th class="text-right">Bar SC</th>
                <th class="text-right">Total SC</th>
                <th class="text-right">Mgmt</th>
                <th class="text-right">Gross</th>
                <th class="text-right">Dmg</th>
                <th class="text-right">Cash</th>
                <th class="text-right">Overcost</th>
                <th class="text-right">Net</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= h($r['full_name']) ?>"><?= h($r['full_name']) ?><?php if (!empty($r['is_hidden'])): ?> <span class="badge badge-regular" title="Owner / Admin, from the Shared branch">Shared</span><?php endif; ?></td>
                <td><span class="badge badge-<?= h($r['status']) ?>"><?= h(ucfirst(str_replace('_',' ',$r['status']))) ?></span></td>
                <td class="text-right" style="white-space:nowrap;"><?= (int)$r['regular_days'] ?></td>
                <td class="text-right" style="white-space:nowrap;"><?= money($r['regular_sc']) ?></td>
                <td class="text-right" style="white-space:nowrap;"><?= (int)$r['bar_night_days'] ?></td>
                <td class="text-right" style="white-space:nowrap;"><?= money($r['bar_night_sc']) ?></td>
                <td class="text-right" style="white-space:nowrap;"><?= money($r['total_sc']) ?></td>
                <td class="text-right" style="white-space:nowrap;"><?= money($r['management_share']) ?></td>
                <td class="text-right" style="white-space:nowrap;"><?= money($r['gross']) ?></td>
                <td class="text-right" style="white-space:nowrap;"><?= money($r['damages_charges']) ?></td>
                <td class="text-right" style="white-space:nowrap;"><?= money($r['cash_advance']) ?></td>
                <td class="text-right" style="white-space:nowrap;"><?= money($r['overcost_cogs']) ?></td>
                <td class="text-right" style="white-space:nowrap;"><strong><?= money($r['net']) ?></strong></td>
                <td>
                    <div style="display:flex; gap:6px; align-items:center;">
                    <?php if (is_admin()): ?>
                    <a href="<?= BASE_URL ?>/deductions/edit.php?period_id=<?= $period['id'] ?>&staff_id=<?= $r['staff_id'] ?>"
                       class="btn btn-sm" title="Deductions"
                       style="width:32px; height:32px; padding:0; display:inline-flex; align-items:center; justify-content:center; font-size:16px;">💸</a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/slip/view.php?period_id=<?= $period['id'] ?>&staff_id=<?= $r['staff_id'] ?><?= $qs ?>"
                       class="btn btn-sm" title="Slip"
                       style="width:32px; height:32px; padding:0; display:inline-flex; align-items:center; justify-content:center; font-size:16px;">🧾</a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="14" class="muted">No duty records fall within this period's date range yet for this branch.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot>
            <tr class="total-row">
                <td colspan="2">TOTAL</td>
                <td class="text-right"><?= array_sum(array_column($rows, 'regular_days')) ?></td>
                <td class="text-right"><?= money(array_sum(array_column($rows, 'regular_sc'))) ?></td>
                <td class="text-right"><?= array_sum(array_column($rows, 'bar_night_days')) ?></td>
                <td class="text-right"><?= money(array_sum(array_column($rows, 'bar_night_sc'))) ?></td>
                <td class="text-right"><?= money($totals['total_sc']) ?></td>
                <td class="text-right"><?= money($totals['management_share']) ?></td>
                <td class="text-right"><?= money($totals['gross']) ?></td>
                <td class="text-right"><?= money($totals['damages_charges']) ?></td>
                <td class="text-right"><?= money($totals['cash_advance']) ?></td>
                <td class="text-right"><?= money($totals['overcost_cogs']) ?></td>
                <td class="text-right"><?= money($totals['net']) ?></td>
                <td></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>