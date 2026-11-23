<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role(['admin']);

// Claribel's account only (username "admin"). Anyone else gets a 403.
if (($_SESSION['user']['username'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Access denied. You do not have permission to view this page.');
}

// Date range -- defaults to the current month.
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-t');
if ($to < $from) { $tmp = $from; $from = $to; $to = $tmp; }

// Total service charge per branch per day.
$stmt = $pdo->prepare(
    "SELECT dd.duty_date, dd.branch_id, SUM(dd.total_amount) AS total_amount
     FROM duty_days dd
     WHERE dd.duty_date BETWEEN ? AND ?
     GROUP BY dd.duty_date, dd.branch_id"
);
$stmt->execute([$from, $to]);

$byDay = [];          // [date][branch_id] => amount
foreach ($stmt->fetchAll() as $r) {
    $byDay[$r['duty_date']][(int)$r['branch_id']] = (float)$r['total_amount'];
}

// Branch columns: every branch that has EVER recorded a duty day (so a branch
// like STELLA still shows up, with "—", even if it has nothing in this range).
// Branches with no duty days at all (e.g. "Shared") are left out.
$everUsed = array_map('intval', $pdo->query('SELECT DISTINCT branch_id FROM duty_days')->fetchAll(PDO::FETCH_COLUMN));
$branches = array_values(array_filter(get_branches(false), function ($b) use ($everUsed) {
    return in_array((int)$b['id'], $everUsed, true);
}));

krsort($byDay); // newest day first

$colTotals = [];
$grand = 0.0;
foreach ($byDay as $date => $perBranch) {
    foreach ($perBranch as $bid => $amt) {
        $colTotals[$bid] = ($colTotals[$bid] ?? 0) + $amt;
        $grand += $amt;
    }
}

// Quick stats for the strip above the table.
$daysCount = count($byDay);
$avgDay    = $daysCount ? $grand / $daysCount : 0;
$bestDate  = null; $bestAmt = 0.0;
foreach ($byDay as $date => $perBranch) {
    $t = array_sum($perBranch);
    if ($t > $bestAmt) { $bestAmt = $t; $bestDate = $date; }
}
$today      = date('Y-m-d');
$thisFrom   = date('Y-m-01');          $thisTo = date('Y-m-t');
$lastFrom   = date('Y-m-01', strtotime('first day of last month'));
$lastTo     = date('Y-m-t',  strtotime('first day of last month'));
$isThis     = ($from === $thisFrom && $to === $thisTo);
$isLast     = ($from === $lastFrom && $to === $lastTo);
$rangeLabel = date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));

$pageTitle = 'Daily Summary';
require __DIR__ . '/../includes/header.php';
?>
<style>
.ds-head { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
.ds-head h1 { margin:0; }
.ds-head p  { margin:4px 0 0; color:var(--text-muted); }

.ds-filter { display:flex; align-items:flex-end; gap:14px; flex-wrap:wrap; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:14px 16px; margin-bottom:16px; }
.ds-field label { display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin:0 0 5px; }
.ds-field input[type=date] { width:auto; min-width:160px; height:40px; padding:0 10px; font-size:15px; margin:0; }
.ds-sep { align-self:flex-end; height:40px; display:flex; align-items:center; color:var(--text-faint); }
.ds-go  { height:40px; padding:0 20px; margin:0; }
.ds-presets { margin-left:auto; display:flex; gap:0; border:1px solid var(--border-strong); border-radius:10px; overflow:hidden; height:40px; }
.ds-presets a, .ds-presets button { display:flex; align-items:center; padding:0 14px; font:600 13px/1 inherit; font-family:inherit; color:var(--text); background:var(--surface); border:0; border-right:1px solid var(--border-strong); text-decoration:none; cursor:pointer; margin:0; border-radius:0; box-shadow:none; }
.ds-presets > :last-child { border-right:0; }
.ds-presets a:hover, .ds-presets button:hover { background:var(--bg-alt); }
.ds-presets a.on { background:var(--primary); color:#fff; }

.ds-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:16px; }
.ds-stat { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:12px 16px; }
.ds-stat .k { font-size:12px; font-weight:600; color:var(--text-muted); }
.ds-stat .v { font-size:22px; font-weight:700; color:var(--primary-dark); margin-top:2px; font-variant-numeric:tabular-nums; }
.ds-stat .s { font-size:12px; color:var(--text-faint); margin-top:1px; }

.ds-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }
.ds-card-top { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; border-bottom:1px solid var(--border); font-size:14px; }
.ds-card-top b { color:var(--text); }
.ds-card-top span { color:var(--text-muted); }
.ds-scroll { overflow:auto; max-height:70vh; }
table.ds { width:100%; border-collapse:separate; border-spacing:0; font-size:15px; font-variant-numeric:tabular-nums; }
table.ds th { position:sticky; top:0; z-index:2; background:var(--surface-soft); text-align:left; font-size:12px; letter-spacing:.04em; text-transform:uppercase; color:var(--text-muted); padding:11px 16px; border-bottom:1px solid var(--border-strong); white-space:nowrap; }
table.ds td { padding:11px 16px; border-bottom:1px solid var(--border); white-space:nowrap; }
table.ds .num { text-align:right; }
table.ds tbody tr:hover td { background:#f3f8f6; }
table.ds .dt { display:flex; align-items:baseline; gap:12px; }
table.ds .dt .d  { font-weight:700; font-size:16px; color:var(--text); min-width:78px; }
table.ds .dt .y  { color:var(--text-faint); font-size:13px; }
table.ds .dow { display:inline-block; width:44px; text-align:center; font-size:12px; font-weight:700; padding:3px 0; border-radius:6px; background:var(--bg-alt); color:var(--text-muted); }
table.ds tr.wk td { background:#fbf7ee; }
table.ds tr.wk .dow { background:#f6e6c4; color:#8a5a00; }
table.ds tr.today .dow { background:var(--primary); color:#fff; }
table.ds .nil { color:var(--text-faint); }
table.ds .tot { font-weight:700; color:var(--primary-dark); }
table.ds tr.best .tot::before { content:'Top day'; font-size:10px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:#8a5a00; background:#f6e6c4; padding:2px 6px; border-radius:4px; margin-right:8px; vertical-align:2px; }
table.ds tfoot th { position:sticky; bottom:0; top:auto; background:#e9f1ee; color:var(--primary-dark); font-size:14px; text-transform:none; letter-spacing:0; border-top:2px solid var(--primary); border-bottom:0; }
.ds-empty { padding:36px 16px; text-align:center; color:var(--text-muted); }

@media (max-width: 820px) {
  .ds-stats { grid-template-columns:repeat(2,1fr); }
  .ds-presets { margin-left:0; }
  .ds-sep { display:none; }
  .ds-field { flex:1 1 140px; }
  .ds-field input[type=date] { width:100%; }
  table.ds th, table.ds td { padding:10px 12px; }
}
@media print {
  .ds-filter, .topbar, header, nav { display:none !important; }
  .ds-scroll { max-height:none; overflow:visible; }
  .ds-card, .ds-stat { border-color:#999; }
  table.ds tr.wk td { background:none; }
  table.ds th, table.ds tfoot th { position:static; }
}
</style>

<div class="ds-head">
    <div>
        <h1>Daily Summary</h1>
        <p>Total service charge recorded each day, per branch.</p>
    </div>
</div>

<form method="get" class="ds-filter">
    <div class="ds-field"><label for="ds-from">From</label><input type="date" id="ds-from" name="from" value="<?= h($from) ?>"></div>
    <div class="ds-sep">→</div>
    <div class="ds-field"><label for="ds-to">To</label><input type="date" id="ds-to" name="to" value="<?= h($to) ?>"></div>
    <button type="submit" class="btn ds-go">Show</button>
    <div class="ds-presets">
        <a href="?from=<?= $thisFrom ?>&to=<?= $thisTo ?>" class="<?= $isThis ? 'on' : '' ?>">This month</a>
        <a href="?from=<?= $lastFrom ?>&to=<?= $lastTo ?>" class="<?= $isLast ? 'on' : '' ?>">Last month</a>
        <button type="button" onclick="window.print()">Print</button>
    </div>
</form>

<?php if ($byDay): ?>
<div class="ds-stats">
    <div class="ds-stat"><div class="k">Total service charge</div><div class="v"><?= money($grand) ?></div><div class="s"><?= h($rangeLabel) ?></div></div>
    <div class="ds-stat"><div class="k">Days recorded</div><div class="v"><?= $daysCount ?></div><div class="s">with at least one entry</div></div>
    <div class="ds-stat"><div class="k">Average per day</div><div class="v"><?= money($avgDay) ?></div><div class="s">all branches combined</div></div>
    <div class="ds-stat"><div class="k">Best day</div><div class="v"><?= money($bestAmt) ?></div><div class="s"><?= $bestDate ? h(date('D, M j', strtotime($bestDate))) : '' ?></div></div>
</div>
<?php endif; ?>

<div class="ds-card">
    <?php if (!$byDay): ?>
        <div class="ds-empty">No duty entries between <?= h(date('F j, Y', strtotime($from))) ?> and <?= h(date('F j, Y', strtotime($to))) ?>.</div>
    <?php else: ?>
    <div class="ds-card-top"><b><?= h($rangeLabel) ?></b><span>Newest first</span></div>
    <div class="ds-scroll">
        <table class="ds">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Day</th>
                    <?php foreach ($branches as $b): ?><th class="num"><?= h($b['name']) ?></th><?php endforeach; ?>
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($byDay as $date => $perBranch):
                    $ts = strtotime($date);
                    $dayTotal = array_sum($perBranch);
                    $cls = [];
                    if ((int)date('N', $ts) >= 6) $cls[] = 'wk';
                    if ($date === $today) $cls[] = 'today';
                    if ($bestDate === $date && $daysCount > 1) $cls[] = 'best';
                ?>
                <tr class="<?= implode(' ', $cls) ?>">
                    <td><div class="dt"><span class="d"><?= h(date('M j', $ts)) ?></span><span class="y"><?= h(date('Y', $ts)) ?></span></div></td>
                    <td><span class="dow"><?= h(date('D', $ts)) ?></span></td>
                    <?php foreach ($branches as $b): $amt = $perBranch[(int)$b['id']] ?? null; ?>
                        <td class="num<?= $amt === null ? ' nil' : '' ?>"><?= $amt === null ? '—' : money($amt) ?></td>
                    <?php endforeach; ?>
                    <td class="num tot"><?= money($dayTotal) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2">Total</th>
                    <?php foreach ($branches as $b): ?><th class="num"><?= money($colTotals[(int)$b['id']] ?? 0) ?></th><?php endforeach; ?>
                    <th class="num"><?= money($grand) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>