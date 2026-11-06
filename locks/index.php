<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_admin();   // only admins can lock / unlock

$year = (int)($_GET['year'] ?? $_POST['year'] ?? date('Y'));
$year = max(2000, min(2100, $year));

$sharedId = shared_branch_id();
$branches = array_values(array_filter(get_branches(false), fn($b) => (int)$b['id'] !== $sharedId));
$branchNames = [];
foreach ($branches as $b) $branchNames[(int)$b['id']] = $b['name'];

// Existing locks for this year: "ym|branch_id" => locked_at   (branch_id 0 = all branches)
$existing = [];
$tableOk = true;
try {
    $st = $GLOBALS['pdo']->prepare('SELECT ym, branch_id, locked_at FROM month_branch_locks WHERE ym LIKE ?');
    $st->execute([$year . '-%']);
    foreach ($st->fetchAll() as $r) $existing[$r['ym'] . '|' . (int)$r['branch_id']] = $r['locked_at'];
} catch (PDOException $e) {
    $tableOk = false;
}

// Auto-save endpoint: each click posts cells[] = "YYYY-MM|branchId|1" (lock) or "...|0" (unlock).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    header('Content-Type: application/json');
    $n = 0;
    try {
        $ins = $pdo->prepare('INSERT IGNORE INTO month_branch_locks (ym, branch_id, locked_by) VALUES (?, ?, ?)');
        $del = $pdo->prepare('DELETE FROM month_branch_locks WHERE ym = ? AND branch_id = ?');
        foreach ((array)($_POST['cells'] ?? []) as $cell) {
            if (!preg_match('/^(\d{4}-(0[1-9]|1[0-2]))\|(\d+)\|([01])$/', $cell, $m)) continue;
            $bid = (int)$m[3];
            if ($bid !== 0 && !isset($branchNames[$bid])) continue;
            if ($m[4] === '1') $ins->execute([$m[1], $bid, current_user()['id'] ?? null]);
            else $del->execute([$m[1], $bid]);
            $n++;
        }
        echo json_encode(['ok' => true, 'changed' => $n]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not save. Have you run database/add_month_branch_locks.sql?']);
    }
    exit;
}

if (!$tableOk) flash('error', 'The month_branch_locks table is missing. Run database/add_month_branch_locks.sql first.');

$pageTitle = 'Lock Months';
require __DIR__ . '/../includes/header.php';
?>
<style>
.lk-top { display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; margin-bottom:18px; }
.lk-year { display:flex; align-items:center; gap:10px; }
.lk-year strong { font-size:1.25rem; min-width:56px; text-align:center; }
.lk-quick { display:flex; flex-wrap:wrap; gap:8px; align-items:center; font-size:.8rem; color:var(--text-muted); }
.lk-chip { border:1px solid var(--border-strong); background:#fff; color:var(--primary-dark); border-radius:999px; padding:5px 12px;
    font-size:.78rem; font-weight:600; cursor:pointer; transition:.12s; }
.lk-chip:hover { background:var(--bg-alt); }
.lk-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(250px, 1fr)); gap:16px; }
.lk-card { background:#fff; border:1px solid var(--border); border-radius:16px; padding:16px 16px 10px; box-shadow:0 1px 3px rgba(0,0,0,.04);
    border-top:4px solid var(--success); transition:.15s; }
.lk-card.partial { border-top-color:var(--accent); }
.lk-card.full { border-top-color:var(--danger); background:#fffafa; }
.lk-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:2px; }
.lk-month { font-size:1.05rem; font-weight:800; color:var(--primary-dark); }
.lk-state { font-size:.7rem; font-weight:700; padding:3px 9px; border-radius:999px; background:var(--success-light); color:#176b3c; }
.lk-card.partial .lk-state { background:#fdf0dc; color:#9a5b0c; }
.lk-card.full .lk-state { background:var(--danger-light); color:#8f2c1f; }
.lk-row { display:flex; justify-content:space-between; align-items:center; padding:9px 0; border-top:1px solid var(--border); cursor:pointer; }
.lk-row:first-of-type { margin-top:10px; }
.lk-row.all { background:#fff8ea; margin:10px -16px 0; padding:10px 16px; border-top:1px solid #f0e2c4; border-bottom:1px solid #f0e2c4; }
.lk-name { font-size:.88rem; font-weight:600; display:flex; align-items:center; gap:8px; }
.lk-row.all .lk-name { color:#8a5200; }
.lk-sub { font-size:.7rem; font-weight:500; color:var(--text-faint); }
.lk-row input { position:absolute; opacity:0; pointer-events:none; }
.lk-sw { position:relative; width:42px; height:24px; border-radius:999px; background:#cfd6d1; flex:none; transition:.18s; }
.lk-sw::after { content:""; position:absolute; top:3px; left:3px; width:18px; height:18px; border-radius:50%; background:#fff;
    box-shadow:0 1px 3px rgba(0,0,0,.3); transition:.18s; }
.lk-row input:checked ~ .lk-sw { background:var(--danger); }
.lk-row input:checked ~ .lk-sw::after { left:21px; }
.lk-row input:focus-visible ~ .lk-sw { outline:2px solid var(--primary); outline-offset:2px; }
.lk-row:hover .lk-sw { filter:brightness(.95); }
.lk-lockicon { opacity:0; transition:.15s; }
.lk-row input:checked ~ .lk-name-wrap .lk-lockicon, .lk-row.on .lk-lockicon { opacity:1; }
.lk-legend { margin-top:16px; font-size:.8rem; color:var(--text-muted); display:flex; gap:18px; flex-wrap:wrap; }
.lk-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; vertical-align:middle; }
#lockToast { position:fixed; right:20px; bottom:20px; padding:10px 16px; border-radius:10px; font-size:.85rem; font-weight:600;
    background:#12372a; color:#fff; opacity:0; transform:translateY(8px); transition:.2s; pointer-events:none; z-index:50; }
#lockToast.show { opacity:1; transform:none; }
#lockToast.err { background:#8f2c1f; }
</style>

<h1>Lock Months</h1>
<p class="subtitle">Flip a switch to lock that month for a branch &mdash; it saves automatically. A locked branch can't add or change duty entries in that month while other branches keep working. <strong>All branches</strong> locks the month for everyone. Admins can always edit.</p>

<form id="lockForm" onsubmit="return false;">
    <?= csrf_field() ?>
    <div class="lk-top">
        <div class="lk-year">
            <a class="btn btn-secondary" href="?year=<?= $year - 1 ?>">&larr; <?= $year - 1 ?></a>
            <strong><?= $year ?></strong>
            <a class="btn btn-secondary" href="?year=<?= $year + 1 ?>"><?= $year + 1 ?> &rarr;</a>
        </div>
        <div class="lk-quick">
            Whole year:
            <button type="button" class="lk-chip" data-col="0">All branches</button>
            <?php foreach ($branches as $b): ?>
                <button type="button" class="lk-chip" data-col="<?= (int)$b['id'] ?>"><?= h($b['name']) ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="lk-grid">
    <?php for ($m = 1; $m <= 12; $m++):
        $ym = sprintf('%04d-%02d', $year, $m);
        $cols = array_merge([0], array_keys($branchNames));
        $lockedCount = 0;
        foreach ($cols as $bid) if (isset($existing[$ym . '|' . $bid])) $lockedCount++;
        $allLocked = isset($existing[$ym . '|0']);
        $cls = $allLocked || $lockedCount === count($cols) ? 'full' : ($lockedCount ? 'partial' : '');
        $label = $allLocked ? 'Locked for all' : ($lockedCount ? $lockedCount . ' branch' . ($lockedCount > 1 ? 'es' : '') . ' locked' : 'Open'); ?>
        <div class="lk-card <?= $cls ?>" data-ym="<?= $ym ?>">
            <div class="lk-head">
                <span class="lk-month"><?= h(date('F', strtotime($ym . '-01'))) ?></span>
                <span class="lk-state"><?= h($label) ?></span>
            </div>
            <?php foreach ($cols as $bid): $k = $ym . '|' . $bid; $on = isset($existing[$k]); ?>
                <label class="lk-row <?= $bid === 0 ? 'all' : '' ?>" title="<?= $on ? 'Locked ' . h($existing[$k]) : 'Open' ?>">
                    <input type="checkbox" name="lock[<?= h($k) ?>]" value="1" data-row="<?= $ym ?>" data-col="<?= (int)$bid ?>" <?= $on ? 'checked' : '' ?>>
                    <span class="lk-name"><?= $bid === 0 ? '🔒 All branches' : h($branchNames[$bid]) ?></span>
                    <span class="lk-sw"></span>
                </label>
            <?php endforeach; ?>
        </div>
    <?php endfor; ?>
    </div>

    <div class="lk-legend">
        <span><span class="lk-dot" style="background:var(--success)"></span>Open</span>
        <span><span class="lk-dot" style="background:var(--accent)"></span>Some branches locked</span>
        <span><span class="lk-dot" style="background:var(--danger)"></span>Fully locked</span>
    </div>
</form>

<div id="lockToast"></div>
<script>
(function () {
    var form = document.getElementById('lockForm');
    var token = form.querySelector('input[name=csrf_token]').value;
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.lk-row input'));
    var toast = document.getElementById('lockToast'), timer;

    function say(msg, err) {
        toast.textContent = msg; toast.className = 'show' + (err ? ' err' : '');
        clearTimeout(timer); timer = setTimeout(function () { toast.className = ''; }, 1800);
    }
    function refreshCard(card) {
        var inputs = Array.prototype.slice.call(card.querySelectorAll('input'));
        var n = inputs.filter(function (i) { return i.checked; }).length;
        var all = card.querySelector('input[data-col="0"]').checked;
        card.className = 'lk-card' + ((all || n === inputs.length) ? ' full' : (n ? ' partial' : ''));
        card.querySelector('.lk-state').textContent = all ? 'Locked for all'
            : (n ? n + ' branch' + (n > 1 ? 'es' : '') + ' locked' : 'Open');
    }
    function refreshAll() { document.querySelectorAll('.lk-card').forEach(refreshCard); }

    function save(changed) {
        if (!changed.length) return;
        refreshAll();
        var fd = new FormData();
        fd.append('csrf_token', token);
        changed.forEach(function (b) {
            fd.append('cells[]', b.name.replace(/^lock\[|\]$/g, '') + '|' + (b.checked ? 1 : 0));
        });
        fetch(location.pathname + location.search, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok && j.ok, j: j }; }); })
            .then(function (res) {
                if (!res.ok) throw new Error((res.j && res.j.error) || 'Save failed');
                say('✓ Saved');
            })
            .catch(function (e) {
                changed.forEach(function (b) { b.checked = !b.checked; });
                refreshAll();
                say(e.message || 'Save failed — reload and try again', true);
            });
    }
    boxes.forEach(function (b) { b.addEventListener('change', function () { save([b]); }); });

    document.querySelectorAll('.lk-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var list = boxes.filter(function (b) { return b.getAttribute('data-col') === chip.getAttribute('data-col'); });
            var allOn = list.every(function (b) { return b.checked; });
            if (!confirm((allOn ? 'Unlock' : 'Lock') + ' every month of <?= $year ?> for ' + chip.textContent + '?')) return;
            var changed = list.filter(function (b) { return b.checked === allOn; });
            changed.forEach(function (b) { b.checked = !allOn; });
            save(changed);
        });
    });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>