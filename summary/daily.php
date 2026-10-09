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

// Build a Y-m-d date from the month / day / year dropdowns.
function summary_pick_date($prefix) {
    if (!isset($_GET[$prefix . '_m'], $_GET[$prefix . '_d'], $_GET[$prefix . '_y'])) return null;
    $m = (int)$_GET[$prefix . '_m']; $d = (int)$_GET[$prefix . '_d']; $y = (int)$_GET[$prefix . '_y'];
    if (!checkdate($m, $d, $y)) {
        // e.g. February 31 -> clamp to the last day of that month
        if ($m >= 1 && $m <= 12 && $y >= 2000) return sprintf('%04d-%02d-%02d', $y, $m, (int)date('t', strtotime(sprintf('%04d-%02d-01', $y, $m))));
        return null;
    }
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

// Date range -- defaults to the current month.
$from = summary_pick_date('from') ?? ($_GET['from'] ?? date('Y-m-01'));
$to   = summary_pick_date('to')   ?? ($_GET['to']   ?? date('Y-m-t'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-t');
if ($to < $from) { $tmp = $from; $from = $to; $to = $tmp; }

// Total service charge per branch per day (regular + bar night added together).
$stmt = $pdo->prepare(
    "SELECT dd.duty_date, dd.branch_id,
            SUM(dd.total_amount) AS total_amount,
            SUM(CASE WHEN dd.shift = 'regular'   THEN dd.total_amount ELSE 0 END) AS regular_amount,
            SUM(CASE WHEN dd.shift = 'bar_night' THEN dd.total_amount ELSE 0 END) AS bar_amount
     FROM duty_days dd
     WHERE dd.duty_date BETWEEN ? AND ?
     GROUP BY dd.duty_date, dd.branch_id"
);
$stmt->execute([$from, $to]);

$byDay = [];          // [date][branch_id] => regular amount
$barByDay = [];       // [date][branch_id] => bar night amount
$branchesUsed = [];   // branch ids that have at least one entry in range
foreach ($stmt->fetchAll() as $r) {
    $d = $r['duty_date']; $bid = (int)$r['branch_id'];
    $reg = (float)$r['regular_amount']; $bar = (float)$r['bar_amount'];
    if (!isset($byDay[$d])) $byDay[$d] = [];
    // a day that only has a bar-night entry still needs a row
    if ($reg != 0 || $bar == 0 || !isset($r['bar_amount'])) $byDay[$d][$bid] = $reg;
    if ($bar != 0) $barByDay[$d][$bid] = $bar;
    $branchesUsed[$bid] = true;
}

// Branch columns: every branch that has EVER recorded a duty day (so a branch
// like STELLA still shows up, with "—", even if it has nothing in this range).
// Branches with no duty days at all (e.g. "Shared") are left out.
$everUsed = array_map('intval', $pdo->query('SELECT DISTINCT branch_id FROM duty_days')->fetchAll(PDO::FETCH_COLUMN));
$branches = array_values(array_filter(get_branches(false), function ($b) use ($everUsed) {
    return in_array((int)$b['id'], $everUsed, true);
}));

// Branches that have EVER had a bar-night entry get their own extra column.
$barBranchIds = array_map('intval', $pdo->query("SELECT DISTINCT branch_id FROM duty_days WHERE shift = 'bar_night'")->fetchAll(PDO::FETCH_COLUMN));

// Make sure a day with only a bar-night entry still appears.
foreach ($barByDay as $d => $x) { if (!isset($byDay[$d])) $byDay[$d] = []; }
krsort($byDay); // newest day first

$colTotals = [];
$barTotals = [];
$grand = 0.0;
foreach ($byDay as $date => $perBranch) {
    foreach ($perBranch as $bid => $amt) {
        $colTotals[$bid] = ($colTotals[$bid] ?? 0) + $amt;
        $grand += $amt;
    }
    foreach (($barByDay[$date] ?? []) as $bid => $amt) {
        $barTotals[$bid] = ($barTotals[$bid] ?? 0) + $amt;
        $grand += $amt;
    }
}

$pageTitle = 'Daily Summary';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <h1>Daily Summary</h1>
</div>
<p class="muted">Total service charge recorded each day, per branch (bar night shown in its own column; the Total adds everything).</p>

<form method="get" class="card" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom:16px;">
    <?php
    $monthNames = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
    $yNow = (int)date('Y');
    function summary_date_picker($label, $prefix, $value, $monthNames, $yNow) {
        $cm = (int)date('n', strtotime($value)); $cd = (int)date('j', strtotime($value)); $cy = (int)date('Y', strtotime($value));
        echo '<div><label>' . h($label) . '</label><div style="display:flex; gap:6px;">';
        echo '<select name="' . $prefix . '_m">';
        foreach ($monthNames as $n => $name) echo '<option value="' . $n . '"' . ($n === $cm ? ' selected' : '') . '>' . $name . '</option>';
        echo '</select><select name="' . $prefix . '_d">';
        for ($d = 1; $d <= 31; $d++) echo '<option value="' . $d . '"' . ($d === $cd ? ' selected' : '') . '>' . $d . '</option>';
        echo '</select><select name="' . $prefix . '_y">';
        for ($y = min($yNow - 2, $cy); $y <= max($yNow + 2, $cy); $y++) echo '<option value="' . $y . '"' . ($y === $cy ? ' selected' : '') . '>' . $y . '</option>';
        echo '</select></div></div>';
    }
    summary_date_picker('From', 'from', $from, $monthNames, $yNow);
    summary_date_picker('To', 'to', $to, $monthNames, $yNow);
    ?>
    <button type="submit" class="btn">Show</button>
    <a class="btn btn-secondary" href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-t') ?>">This Month</a>
    <a class="btn btn-secondary" href="?from=<?= date('Y-m-01', strtotime('first day of last month')) ?>&to=<?= date('Y-m-t', strtotime('first day of last month')) ?>">Last Month</a>
    <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
</form>

<div class="card">
    <?php if (!$byDay): ?>
        <p>No duty entries between <?= h(date('F j Y', strtotime($from))) ?> and <?= h(date('F j Y', strtotime($to))) ?>.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Day</th>
                    <?php foreach ($branches as $b): ?>
                        <th style="text-align:right;"><?= h($b['name']) ?></th>
                        <?php if (in_array((int)$b['id'], $barBranchIds, true)): ?>
                            <th style="text-align:right;">Bar Night</th>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <th style="text-align:right;">Total (All Branches)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($byDay as $date => $perBranch): ?>
                <tr>
                    <td><?= h(date('F j Y', strtotime($date))) ?></td>
                    <td><?= h(date('D', strtotime($date))) ?></td>
                    <?php foreach ($branches as $b): $bid = (int)$b['id']; $amt = $perBranch[$bid] ?? null; ?>
                        <td style="text-align:right;"><?= $amt === null ? '—' : money($amt) ?></td>
                        <?php if (in_array($bid, $barBranchIds, true)): $bamt = $barByDay[$date][$bid] ?? null; ?>
                            <td style="text-align:right;"><?= $bamt === null ? '—' : money($bamt) ?></td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <td style="text-align:right;"><strong><?= money(array_sum($perBranch) + array_sum($barByDay[$date] ?? [])) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2">TOTAL</th>
                    <?php foreach ($branches as $b): ?>
                        <th style="text-align:right;"><?= money($colTotals[(int)$b['id']] ?? 0) ?></th>
                        <?php if (in_array((int)$b['id'], $barBranchIds, true)): ?>
                            <th style="text-align:right;"><?= money($barTotals[(int)$b['id']] ?? 0) ?></th>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <th style="text-align:right;"><?= money($grand) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>