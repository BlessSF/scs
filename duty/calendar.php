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

// Pull recorded duty days for this month, this branch. A day can now have
// both a 'regular' row and a 'bar_night' row (Fri/Sat) -- keep them broken if 
// out separately so the calendar cell can show each on its own line.
$stmt = $pdo->prepare("SELECT dd.duty_date,
                               SUM(CASE WHEN dd.shift = 'regular'   THEN dd.total_amount ELSE 0 END) AS regular_amount,
                               SUM(CASE WHEN dd.shift = 'bar_night' THEN dd.total_amount ELSE 0 END) AS bar_night_amount,
                               SUM(CASE WHEN dd.shift = 'regular'   THEN COALESCE(ac.staff_count, 0) ELSE 0 END) AS regular_staff_count,
                               SUM(CASE WHEN dd.shift = 'bar_night' THEN COALESCE(ac.staff_count, 0) ELSE 0 END) AS bar_night_staff_count
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

    <div class="day-mode-toggle" id="dayModeToggle" data-branch="<?= $branchId ?>">
        <button type="button" class="btn-mode is-active" id="modeRegularBtn" data-mode="regular">Regular Day</button>
        <button type="button" class="btn-mode" id="modeBarNightBtn" data-mode="bar_night">🌙 Activate Bar Night</button>
    </div>
    <p class="small muted" id="dayModeHint" style="margin:6px 0 0;">Regular Day — every day of the week is open for entry.</p>

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
                <?php if ($rec && (float)$rec['bar_night_amount'] > 0): ?>
                    <span class="d-amt"><?= money($rec['regular_amount']) ?></span>
                    <?php if (is_admin()): ?><span class="small muted"><?= (int)$rec['regular_staff_count'] ?> staff</span><?php endif; ?>
                    <span class="d-amt d-amt-bar">🌙 <?= money($rec['bar_night_amount']) ?></span>
                    <?php if (is_admin()): ?><span class="small muted"><?= (int)$rec['bar_night_staff_count'] ?> staff</span><?php endif; ?>
                <?php elseif ($rec): ?>
                    <span class="d-amt"><?= money($rec['regular_amount']) ?></span>
                    <?php if (is_admin()): ?><span class="small muted"><?= (int)$rec['regular_staff_count'] ?> staff</span><?php endif; ?>
                <?php else: ?>
                    <span class="small muted">—</span>
                <?php endif; ?>
            </a>
        <?php endfor; ?>
    </div>
</div>
<script>
(function () {
    var wrap = document.getElementById('dayModeToggle');
    var grid = document.getElementById('dutyDayGrid');
    var hint = document.getElementById('dayModeHint');
    var regularBtn = document.getElementById('modeRegularBtn');
    var barNightBtn = document.getElementById('modeBarNightBtn');
    if (!wrap || !grid) return;

    var storageKey = 'dutyDayMode_branch_' + wrap.getAttribute('data-branch');

    function applyMode(mode, save) {
        var isBarNight = mode === 'bar_night';
        grid.classList.toggle('mode-bar-night', isBarNight);
        regularBtn.classList.toggle('is-active', !isBarNight);
        barNightBtn.classList.toggle('is-active', isBarNight);
        hint.textContent = isBarNight
            ? 'Bar Night — only Friday and Saturday are open for entry; Sunday–Thursday are locked.'
            : 'Regular Day — every day of the week is open for entry.';
        if (save) {
            try { localStorage.setItem(storageKey, mode); } catch (e) {}
        }
    }

    regularBtn.addEventListener('click', function () { applyMode('regular', true); });
    barNightBtn.addEventListener('click', function () { applyMode('bar_night', true); });

    var saved = 'regular';
    try { saved = localStorage.getItem(storageKey) || 'regular'; } catch (e) {}
    applyMode(saved, false);
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>