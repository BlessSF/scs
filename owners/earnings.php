<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_admin();

// All hidden staff (owners / other admins) -- usually homed in the
// "Shared" branch, but this also picks up anyone marked "Hidden" from
// any other branch, since that's the actual flag that drives the
// auto-add-to-every-duty-day behavior.
$staffFilter = (int)($_GET['staff_id'] ?? 0);

$hiddenStaff = $pdo->query("SELECT s.*, b.name AS branch_name
    FROM staff s JOIN branches b ON b.id = s.branch_id
    WHERE s.is_hidden = 1
    ORDER BY s.is_active DESC, s.full_name")->fetchAll();

// Every period, across every branch -- we need to run period_summary()
// for each one and pull out just the hidden-staff rows, since the net
// figure depends on that period's own management share % and overcost
// budget/rate, which can't be computed with a single flat SQL query.
$periods = $pdo->query("SELECT p.*, b.name AS branch_name
    FROM periods p JOIN branches b ON b.id = p.branch_id
    ORDER BY p.start_date DESC")->fetchAll();

$earnings = [];
foreach ($hiddenStaff as $s) {
    $earnings[$s['id']] = [
        'staff'  => $s,
        'rows'   => [],
        'totals' => ['days' => 0, 'total_sc' => 0.0, 'gross' => 0.0, 'damages_charges' => 0.0, 'cash_advance' => 0.0, 'overcost_cogs' => 0.0, 'net' => 0.0],
    ];
}

if ($hiddenStaff) {
    foreach ($periods as $p) {
        $summary = period_summary($pdo, $p['id']);
        if (!$summary) continue;
        foreach ($summary['rows'] as $r) {
            if (empty($r['is_hidden']) || !isset($earnings[$r['staff_id']])) continue;
            // NOTE: no branch-assignment filter here. period_summary() already
            // includes every active hidden owner in EVERY branch's period (they
            // are auto-added to every duty day), so their earnings must be
            // summed across all branches too -- filtering by staff_branches
            // silently dropped every branch they weren't explicitly assigned to.
            $earnings[$r['staff_id']]['rows'][] = [
                'period_id'   => $p['id'],
                'period_name' => $p['name'],
                'branch_name' => $p['branch_name'],
                'start_date'  => $p['start_date'],
                'end_date'    => $p['end_date'],
                'status'      => $p['status'],
                'days_worked' => $r['days_worked'],
                'total_sc'    => $r['total_sc'],
                'gross'       => $r['gross'],
                'damages_charges' => $r['damages_charges'],
                'cash_advance'    => $r['cash_advance'],
                'overcost_cogs'   => $r['overcost_cogs'],
                'net'         => $r['net'],
            ];
            $t = &$earnings[$r['staff_id']]['totals'];
            $t['days']             += (int)$r['days_worked'];
            $t['total_sc']         += (float)$r['total_sc'];
            $t['gross']            += (float)$r['gross'];
            $t['damages_charges']  += (float)$r['damages_charges'];
            $t['cash_advance']     += (float)$r['cash_advance'];
            $t['overcost_cogs']    += (float)$r['overcost_cogs'];
            $t['net']              += (float)$r['net'];
            unset($t);
        }
    }
}

$pageTitle = 'Owner Earnings';
require __DIR__ . '/../includes/header.php';
?>
<h1>Owner Earnings</h1>
<p class="subtitle">Total share earned by each hidden owner/admin staff member (typically homed in the <strong>Shared</strong> branch), added up across every branch and every period.</p>

<?php if (!$hiddenStaff): ?>
    <div class="card" style="text-align:center; padding:40px 20px;">
        <p class="muted" style="margin:0 0 12px;">No hidden owner/admin staff yet.</p>
        <a class="btn" href="<?= BASE_URL ?>/staff/form.php">+ Add one from the Shared branch</a>
    </div>
<?php endif; ?>

<?php foreach ($hiddenStaff as $s):
    $e = $earnings[$s['id']];
    $t = $e['totals'];
    $periodsContributed = count(array_filter($e['rows'], fn($r) => $r['days_worked'] > 0 || $r['total_sc'] > 0));
    if ($staffFilter && $staffFilter !== (int)$s['id']) continue;
?>
<div class="card" style="margin-bottom:22px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:6px;">
        <h2 style="margin:0;">
            <?= h($s['full_name']) ?>
            <span class="badge badge-regular" style="margin-left:6px;">Shared / Hidden</span>
            <?php if (!$s['is_active']): ?><span class="badge badge-resigned" style="margin-left:4px;">Inactive</span><?php endif; ?>
        </h2>
        <span class="small muted"><?= h($s['branch_name']) ?> &middot; <?= $periodsContributed ?> period<?= $periodsContributed === 1 ? '' : 's' ?> with earnings</span>
    </div>

    <div class="stat-grid">
        <div class="stat-card"><div class="stat-value"><?= money($t['total_sc']) ?></div><div class="stat-label">Total SC (before mgmt share)</div></div>
        <div class="stat-card"><div class="stat-value"><?= money($t['gross']) ?></div><div class="stat-label">Total Gross</div></div>
        <div class="stat-card"><div class="stat-value"><?= money($t['overcost_cogs'] + $t['damages_charges'] + $t['cash_advance']) ?></div><div class="stat-label">Total Deductions</div></div>
        <div class="stat-card"><div class="stat-value"><strong><?= money($t['net']) ?></strong></div><div class="stat-label">Total Net Earnings</div></div>
    </div>

    <?php if ($e['rows']): ?>
    <div style="overflow-x:auto; margin-top:14px;">
        <table>
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Branch</th>
                    <th>Dates</th>
                    <th class="text-right">Days</th>
                    <th class="text-right">Total SC</th>
                    <th class="text-right">Gross</th>
                    <th class="text-right">Deductions</th>
                    <th class="text-right">Net</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($e['rows'] as $r): ?>
                <tr>
                    <td><?= h($r['period_name']) ?></td>
                    <td class="small"><?= h($r['branch_name']) ?></td>
                    <td class="small"><?= h(date('M j', strtotime($r['start_date']))) ?> &ndash; <?= h(date('M j, Y', strtotime($r['end_date']))) ?></td>
                    <td class="text-right"><?= (int)$r['days_worked'] ?></td>
                    <td class="text-right"><?= money($r['total_sc']) ?></td>
                    <td class="text-right"><?= money($r['gross']) ?></td>
                    <td class="text-right"><?= money($r['damages_charges'] + $r['cash_advance'] + $r['overcost_cogs']) ?></td>
                    <td class="text-right"><strong><?= money($r['net']) ?></strong></td>
                    <td>
                        <div style="display:flex; gap:6px; align-items:center;">
                        <a href="<?= BASE_URL ?>/slip/view.php?period_id=<?= $r['period_id'] ?>&staff_id=<?= $s['id'] ?>"
                           class="btn btn-sm" title="Slip"
                           style="width:32px; height:32px; padding:0; display:inline-flex; align-items:center; justify-content:center; font-size:16px;">🧾</a>
                        <a href="<?= BASE_URL ?>/periods/view.php?id=<?= $r['period_id'] ?>"
                           class="btn btn-sm" title="Period"
                           style="width:32px; height:32px; padding:0; display:inline-flex; align-items:center; justify-content:center; font-size:16px;">📅</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <p class="muted small" style="margin-top:10px;">No period has covered this staff member yet.</p>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
