<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role(['admin', 'owner']);
$viewerIsOwner = is_owner(); // owners see the numbers only -- no slip / period drill-downs yet

// All hidden staff (owners / other admins) -- usually homed in the
// "Shared" branch, but this also picks up anyone marked "Hidden" from
// any other branch, since that's the actual flag that drives the
// auto-add-to-every-duty-day behavior.
$staffFilter = (int)($_GET['staff_id'] ?? 0);

$hiddenStaff = $pdo->query("SELECT s.*, b.name AS branch_name
    FROM staff s JOIN branches b ON b.id = s.branch_id
    WHERE s.is_hidden = 1
    ORDER BY s.is_active DESC, s.full_name")->fetchAll();

// An owner login linked to a staff record sees ONLY that person's earnings.
// (An owner login with no linked staff, and admins, see everyone.)
$ownLinkedStaffId = $viewerIsOwner ? (int)(current_user()['staff_id'] ?? 0) : 0;
if ($ownLinkedStaffId) {
    $hiddenStaff = array_values(array_filter($hiddenStaff, fn($s) => (int)$s['id'] === $ownLinkedStaffId));
}

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
        }
    }
}


// ---- Filters --------------------------------------------------------------
// Periods are per-branch, so the same date range usually exists once per
// branch (e.g. Jul 1 - Sep 30 for DOIS, HERO and STELLA). A "range" groups
// those together so you can look at one quarter at a time.
$ranges = [];
foreach ($periods as $p) {
    $key = $p['start_date'] . '|' . $p['end_date'];
    if (!isset($ranges[$key])) $ranges[$key] = ['start' => $p['start_date'], 'end' => $p['end_date']];
}
$rangeSel  = $_GET['range'] ?? '';
if ($rangeSel !== '' && !isset($ranges[$rangeSel])) $rangeSel = '';
$branchSel = (int)($_GET['branch'] ?? 0);

$branchesInUse = [];
foreach ($periods as $p) $branchesInUse[(int)$p['branch_id']] = $p['branch_name'];
asort($branchesInUse);

function oe_url(array $over) {
    $q = array_merge($_GET, $over);
    foreach ($q as $k => $v) if ($v === '' || $v === 0 || $v === null) unset($q[$k]);
    return '?' . http_build_query($q);
}
function oe_range_label($r) {
    $sy = date('Y', strtotime($r['start'])); $ey = date('Y', strtotime($r['end']));
    return date('M j', strtotime($r['start'])) . ($sy !== $ey ? ', ' . $sy : '') . ' – ' . date('M j, Y', strtotime($r['end']));
}

// Apply filters and (re)compute totals from what is left.
$grandNet = 0.0; $grandSc = 0.0; $shown = 0;
foreach ($earnings as $sid => &$e) {
    $e['rows'] = array_values(array_filter($e['rows'], function ($r) use ($rangeSel, $branchSel, $periods) {
        if ($rangeSel !== '' && ($r['start_date'] . '|' . $r['end_date']) !== $rangeSel) return false;
        if ($branchSel) {
            foreach ($periods as $p) if ((int)$p['id'] === (int)$r['period_id'] && (int)$p['branch_id'] !== $branchSel) return false;
        }
        return true;
    }));
    foreach ($e['rows'] as $r) {
        $t = &$e['totals'];
        $t['days']            += (int)$r['days_worked'];
        $t['total_sc']        += (float)$r['total_sc'];
        $t['gross']           += (float)$r['gross'];
        $t['damages_charges'] += (float)$r['damages_charges'];
        $t['cash_advance']    += (float)$r['cash_advance'];
        $t['overcost_cogs']   += (float)$r['overcost_cogs'];
        $t['net']             += (float)$r['net'];
        unset($t);
    }
    if (!$staffFilter || $staffFilter === (int)$sid) { $grandNet += $e['totals']['net']; $grandSc += $e['totals']['total_sc']; $shown++; }
}
unset($e);

$scopeLabel = ($rangeSel !== '' ? oe_range_label($ranges[$rangeSel]) : 'All periods combined')
            . ($branchSel ? ' · ' . ($branchesInUse[$branchSel] ?? '') : '');

$pageTitle = 'Owner Earnings';
require __DIR__ . '/../includes/header.php';
?>
<style>
.oe-head h1 { margin:0 0 4px; }
.oe-head p  { margin:0 0 16px; color:var(--text-muted); }

.oe-filter { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:14px 16px; margin-bottom:16px; display:grid; gap:12px; }
.oe-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.oe-lab { font-size:12px; font-weight:700; color:var(--text-muted); width:64px; flex:none; }
.oe-chips { display:flex; gap:6px; flex-wrap:wrap; }
.oe-chip { display:inline-flex; align-items:center; height:34px; padding:0 13px; border:1px solid var(--border-strong); border-radius:999px; font-size:13.5px; font-weight:600; color:var(--text); background:var(--surface); text-decoration:none; white-space:nowrap; }
.oe-chip:hover { background:var(--bg-alt); }
.oe-chip.on { background:var(--primary); border-color:var(--primary); color:#fff; }
.oe-row select { height:34px; padding:0 10px; margin:0; width:auto; min-width:150px; font-size:14px; }

.oe-scope { display:flex; justify-content:space-between; align-items:baseline; gap:12px; flex-wrap:wrap; margin:0 0 12px; }
.oe-scope b { font-size:15px; }
.oe-scope span { color:var(--text-muted); font-size:13.5px; }
.oe-scope .net { font-weight:700; color:var(--primary-dark); }

.oe-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); margin-bottom:16px; overflow:hidden; }
.oe-top { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:14px 18px; border-bottom:1px solid var(--border); }
.oe-name { display:flex; align-items:center; gap:10px; min-width:0; }
.oe-av { width:36px; height:36px; border-radius:50%; background:var(--bg-alt); color:var(--primary-dark); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; flex:none; }
.oe-name h2 { margin:0; font-size:17px; line-height:1.2; }
.oe-name small { color:var(--text-muted); font-size:12.5px; }
.oe-net { text-align:right; }
.oe-net .v { font-size:24px; font-weight:800; color:var(--primary-dark); font-variant-numeric:tabular-nums; line-height:1.1; }
.oe-net .k { font-size:11.5px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:var(--text-muted); }

.oe-eq { display:flex; flex-wrap:wrap; align-items:stretch; padding:0 18px; border-bottom:1px solid var(--border); background:var(--surface-soft); }
.oe-eq > div { padding:11px 18px 11px 0; margin-right:18px; }
.oe-eq .k { font-size:11.5px; font-weight:600; color:var(--text-muted); }
.oe-eq .v { font-size:15.5px; font-weight:700; font-variant-numeric:tabular-nums; }
.oe-eq .op { align-self:center; color:var(--text-faint); font-weight:700; margin:0 18px 0 -6px; }
.oe-eq .ded .v { color:var(--danger); }

.oe-scroll { overflow-x:auto; }
table.oe { width:100%; border-collapse:collapse; font-size:14.5px; font-variant-numeric:tabular-nums; }
table.oe th { text-align:left; font-size:11.5px; letter-spacing:.04em; text-transform:uppercase; color:var(--text-muted); padding:10px 14px; border-bottom:1px solid var(--border); white-space:nowrap; }
table.oe td { padding:11px 14px; border-bottom:1px solid var(--border); white-space:nowrap; vertical-align:middle; }
table.oe tr:last-child td { border-bottom:0; }
table.oe tbody tr:hover td { background:#f3f8f6; }
table.oe th:first-child, table.oe td:first-child { padding-left:18px; }
table.oe th:last-child,  table.oe td:last-child  { padding-right:18px; }
table.oe .num { text-align:right; }
table.oe .pn { font-weight:600; white-space:normal; min-width:180px; }
table.oe .br { display:inline-block; padding:2px 8px; border-radius:6px; background:var(--bg-alt); font-size:12px; font-weight:700; color:var(--primary-dark); }
table.oe .dt { color:var(--text-muted); font-size:13px; }
table.oe .netc { font-weight:700; color:var(--primary-dark); }
table.oe .ded { color:var(--danger); }
table.oe .zero { color:var(--text-faint); }
table.oe tfoot td { background:#eef4f2; font-weight:700; border-top:2px solid var(--primary); border-bottom:0; }
.oe-act { display:flex; gap:6px; justify-content:flex-end; }
.oe-act a { display:inline-flex; align-items:center; gap:5px; height:30px; padding:0 10px; border:1px solid var(--border-strong); border-radius:8px; font-size:12.5px; font-weight:600; color:var(--text); text-decoration:none; background:var(--surface); }
.oe-act a:hover { background:var(--bg-alt); border-color:var(--primary); color:var(--primary-dark); }
.oe-act svg { width:14px; height:14px; }
.oe-empty { padding:22px 18px; color:var(--text-muted); font-size:14px; }

@media (max-width:760px) {
  .oe-lab { width:100%; }
  .oe-net { text-align:left; }
  .oe-eq > div { padding-right:0; margin-right:14px; }
  .oe-eq .op { display:none; }
}
</style>

<div class="oe-head">
    <h1>Owner Earnings</h1>
    <p>Share earned by each hidden owner / admin, by period. Pick a period to see it on its own, or leave it on all periods to see the combined total.</p>
</div>

<?php if (!$hiddenStaff): ?>
    <div class="card" style="text-align:center; padding:40px 20px;">
        <p class="muted" style="margin:0 0 12px;">No hidden owner/admin staff yet.</p>
        <?php if (!$viewerIsOwner): ?><a class="btn" href="<?= BASE_URL ?>/staff/form.php">+ Add one from the Shared branch</a><?php endif; ?>
    </div>
<?php else: ?>

<div class="oe-filter">
    <div class="oe-row">
        <div class="oe-lab">Period</div>
        <div class="oe-chips">
            <a class="oe-chip <?= $rangeSel === '' ? 'on' : '' ?>" href="<?= h(oe_url(['range' => ''])) ?>">All periods</a>
            <?php foreach ($ranges as $key => $r): ?>
                <a class="oe-chip <?= $rangeSel === $key ? 'on' : '' ?>" href="<?= h(oe_url(['range' => $key])) ?>"><?= h(oe_range_label($r)) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="oe-row">
        <div class="oe-lab">Branch</div>
        <div class="oe-chips">
            <a class="oe-chip <?= !$branchSel ? 'on' : '' ?>" href="<?= h(oe_url(['branch' => ''])) ?>">All</a>
            <?php foreach ($branchesInUse as $bid => $bn): ?>
                <a class="oe-chip <?= $branchSel === $bid ? 'on' : '' ?>" href="<?= h(oe_url(['branch' => $bid])) ?>"><?= h($bn) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if (count($hiddenStaff) > 1): ?>
    <div class="oe-row">
        <div class="oe-lab">Owner</div>
        <select onchange="location.href=this.value">
            <option value="<?= h(oe_url(['staff_id' => ''])) ?>">Everyone</option>
            <?php foreach ($hiddenStaff as $hs): ?>
                <option value="<?= h(oe_url(['staff_id' => (int)$hs['id']])) ?>"<?= $staffFilter === (int)$hs['id'] ? ' selected' : '' ?>><?= h($hs['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
</div>

<div class="oe-scope">
    <b><?= h($scopeLabel) ?></b>
    <span><?= $shown ?> owner<?= $shown === 1 ? '' : 's' ?> · total net <span class="net"><?= money($grandNet) ?></span></span>
</div>

<?php foreach ($hiddenStaff as $s):
    $e = $earnings[$s['id']];
    $t = $e['totals'];
    $ded = $t['overcost_cogs'] + $t['damages_charges'] + $t['cash_advance'];
    $periodsContributed = count(array_filter($e['rows'], fn($r) => $r['days_worked'] > 0 || $r['total_sc'] > 0));
    if ($staffFilter && $staffFilter !== (int)$s['id']) continue;
    $words = preg_split('/\s+/', trim($s['full_name']));
    $initials = strtoupper(substr($words[0], 0, 1) . (count($words) > 1 ? substr(end($words), 0, 1) : ''));
?>
<div class="oe-card">
    <div class="oe-top">
        <div class="oe-name">
            <div class="oe-av"><?= h($initials) ?></div>
            <div>
                <h2><?= h($s['full_name']) ?><?php if (!$s['is_active']): ?> <span class="badge badge-resigned">Inactive</span><?php endif; ?></h2>
                <small><?= h($s['branch_name']) ?> · hidden owner · <?= $periodsContributed ?> period<?= $periodsContributed === 1 ? '' : 's' ?> with earnings</small>
            </div>
        </div>
        <div class="oe-net"><div class="v"><?= money($t['net']) ?></div><div class="k">Net earnings</div></div>
    </div>

    <div class="oe-eq">
        <div><div class="k">Total SC (before mgmt share)</div><div class="v"><?= money($t['total_sc']) ?></div></div>
        <div><div class="k">Gross</div><div class="v"><?= money($t['gross']) ?></div></div>
        <div class="op">−</div>
        <div class="ded"><div class="k">Deductions</div><div class="v"><?= money($ded) ?></div></div>
        <div class="op">=</div>
        <div><div class="k">Net</div><div class="v"><?= money($t['net']) ?></div></div>
    </div>

    <?php if ($e['rows']): ?>
    <div class="oe-scroll">
        <table class="oe">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Branch</th>
                    <th>Dates</th>
                    <th class="num">Days</th>
                    <th class="num">Total SC</th>
                    <th class="num">Gross</th>
                    <th class="num">Deductions</th>
                    <th class="num">Net</th>
                    <?php if (!$viewerIsOwner): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($e['rows'] as $r):
                $rd = $r['damages_charges'] + $r['cash_advance'] + $r['overcost_cogs']; ?>
                <tr>
                    <td class="pn"><?= h($r['period_name']) ?></td>
                    <td><span class="br"><?= h($r['branch_name']) ?></span></td>
                    <td class="dt"><?= h(date('M j', strtotime($r['start_date']))) ?> – <?= h(date('M j, Y', strtotime($r['end_date']))) ?></td>
                    <td class="num"><?= (int)$r['days_worked'] ?></td>
                    <td class="num"><?= money($r['total_sc']) ?></td>
                    <td class="num"><?= money($r['gross']) ?></td>
                    <td class="num <?= $rd > 0 ? 'ded' : 'zero' ?>"><?= money($rd) ?></td>
                    <td class="num netc"><?= money($r['net']) ?></td>
                    <?php if (!$viewerIsOwner): ?>
                    <td>
                        <div class="oe-act">
                            <a href="<?= BASE_URL ?>/slip/view.php?period_id=<?= $r['period_id'] ?>&staff_id=<?= $s['id'] ?>" title="Open slip">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12v20l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/></svg>Slip</a>
                            <a href="<?= BASE_URL ?>/periods/view.php?id=<?= $r['period_id'] ?>" title="Open period">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>Period</a>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if (count($e['rows']) > 1): ?>
            <tfoot>
                <tr>
                    <td colspan="3">Combined (<?= count($e['rows']) ?> periods)</td>
                    <td class="num"><?= (int)$t['days'] ?></td>
                    <td class="num"><?= money($t['total_sc']) ?></td>
                    <td class="num"><?= money($t['gross']) ?></td>
                    <td class="num"><?= money($ded) ?></td>
                    <td class="num"><?= money($t['net']) ?></td>
                    <?php if (!$viewerIsOwner): ?><td></td><?php endif; ?>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
    <?php else: ?>
        <div class="oe-empty">No period matches this filter for this owner.</div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>