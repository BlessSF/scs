<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_admin();

$branches = visible_branches(false);
$__sharedId = function_exists('shared_branch_id') ? (int)shared_branch_id() : 0;
if ($__sharedId) $branches = array_values(array_filter($branches, fn($b) => (int)$b['id'] !== $__sharedId));
$branchId = scoped_branch_id($_GET['branch_id'] ?? 0);

$sql = 'SELECT p.*, b.name AS branch_name FROM periods p JOIN branches b ON b.id = p.branch_id';
$params = [];
if (is_branch_locked()) {            // a branch login only ever sees its own periods
    $sql .= ' WHERE p.branch_id = ?';
    $params[] = $branchId;
}
$sql .= ' ORDER BY p.start_date DESC, b.name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allPeriods = $stmt->fetchAll();

$statusSel = ($_GET['status'] ?? '') === 'open' ? 'open' : ((($_GET['status'] ?? '') === 'closed') ? 'closed' : '');

// Chip counts (each respects the other filter).
$cntBranch = []; $cntStatus = ['' => 0, 'open' => 0, 'closed' => 0]; $cntAllBranch = 0;
foreach ($allPeriods as $p) {
    $okBranch = !$branchId || (int)$p['branch_id'] === $branchId;
    $okStatus = $statusSel === '' || $p['status'] === $statusSel;
    if ($okStatus) { $cntBranch[(int)$p['branch_id']] = ($cntBranch[(int)$p['branch_id']] ?? 0) + 1; $cntAllBranch++; }
    if ($okBranch) { $cntStatus[''] ++; $cntStatus[$p['status']] = ($cntStatus[$p['status']] ?? 0) + 1; }
}
$periods = array_values(array_filter($allPeriods, function ($p) use ($branchId, $statusSel) {
    return (!$branchId || (int)$p['branch_id'] === $branchId) && ($statusSel === '' || $p['status'] === $statusSel);
}));

function per_url(array $over) {
    $q = array_merge($_GET, $over);
    foreach ($q as $k => $v) if ($v === '' || $v === 0 || $v === '0' || $v === null) unset($q[$k]);
    return '?' . http_build_query($q);
}
function per_color($name) {
    static $map = []; static $palette = ['#e08a1e', '#1fa24d', '#c2201c', '#3b5b8f', '#7a5ab0', '#2a9aa8'];
    $u = strtoupper($name);
    if (strpos($u, 'DOIS') !== false) return '#e08a1e';
    if (strpos($u, 'STELLA') !== false) return '#1fa24d';
    if (strpos($u, 'HERO') !== false) return '#c2201c';
    if (strpos($u, 'SHARED') !== false) return '#8b948d';
    if (!isset($map[$u])) $map[$u] = $palette[count($map) % count($palette)];
    return $map[$u];
}
$todayTs = strtotime(date('Y-m-d'));
$filtersOn = ($branchId && !is_branch_locked()) || $statusSel !== '';

$pageTitle = 'Periods';
require __DIR__ . '/../includes/header.php';
?>
<style>
.per-head { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
.per-head h1 { margin:0; }
.per-head p { margin:4px 0 0; color:var(--text-muted); max-width:720px; }
.per-add { display:inline-flex; align-items:center; gap:7px; height:42px; padding:0 20px; margin:0; }
.per-add svg { width:16px; height:16px; }

.per-filter { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:14px 16px; margin-bottom:14px; display:grid; gap:12px; }
.per-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.per-lab { font-size:12px; font-weight:700; color:var(--text-muted); width:64px; flex:none; }
.per-chips { display:flex; gap:6px; flex-wrap:wrap; }
.per-chip { display:inline-flex; align-items:center; gap:7px; height:34px; padding:0 8px 0 13px; border:1px solid var(--border-strong); border-radius:999px; font-size:13.5px; font-weight:600; color:var(--text); background:var(--surface); text-decoration:none; white-space:nowrap; }
.per-chip i { font-style:normal; min-width:24px; height:22px; padding:0 7px; border-radius:999px; background:var(--bg-alt); color:var(--text-muted); font-size:12px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
.per-chip:hover { background:var(--bg-alt); }
.per-chip.on { background:var(--primary); border-color:var(--primary); color:#fff; }
.per-chip.on i { background:rgba(255,255,255,.22); color:#fff; }
.per-chip .d { width:9px; height:9px; border-radius:3px; }
.per-chip.zero { color:var(--text-faint); }
.per-meta { display:flex; justify-content:space-between; margin:0 2px 10px; color:var(--text-muted); font-size:13.5px; }
.per-meta a { color:var(--primary-dark); font-weight:600; text-decoration:none; }

.per-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; box-shadow:0 1px 2px rgba(15,35,28,.04); }
.per-scroll { overflow-x:auto; }
table.per { width:100%; border-collapse:collapse; font-size:14.5px; }
table.per th { text-align:left; font-size:11.5px; letter-spacing:.05em; text-transform:uppercase; color:var(--text-muted); padding:11px 16px; border-bottom:1px solid var(--border-strong); background:var(--surface-soft); white-space:nowrap; }
table.per td { padding:14px 16px; border-bottom:1px solid var(--border); vertical-align:middle; }
table.per tr:last-child td { border-bottom:0; }
table.per tbody tr:hover td { background:#f6faf8; }
table.per .num { text-align:right; }
.per-name { font-weight:700; font-size:15px; line-height:1.3; }
.per-br { display:inline-flex; align-items:center; gap:8px; font-weight:600; white-space:nowrap; }
.per-br i { width:9px; height:9px; border-radius:3px; flex:none; }
.per-dates { white-space:nowrap; font-weight:600; }
.per-note { font-size:12.5px; color:var(--text-faint); margin-top:3px; font-weight:400; }
.per-note.warn { color:#9a6200; font-weight:600; }
.per-prog { height:5px; border-radius:999px; background:var(--bg-alt); overflow:hidden; margin-top:7px; width:170px; }
.per-prog i { display:block; height:100%; background:var(--primary-light); border-radius:999px; }
.per-share { font-variant-numeric:tabular-nums; font-weight:600; }
.per-st { display:inline-flex; align-items:center; gap:7px; font-size:13.5px; font-weight:600; white-space:nowrap; }
.per-st i { width:8px; height:8px; border-radius:50%; background:var(--success); }
.per-st.closed i { background:var(--text-faint); }
.per-act { display:flex; gap:6px; justify-content:flex-end; }
.per-act form { margin:0; display:inline-flex; }
.per-ib { width:36px; height:36px; padding:0; display:inline-flex; align-items:center; justify-content:center; border-radius:10px; border:1px solid transparent; cursor:pointer; text-decoration:none; transition:transform .1s, background .12s, color .12s, box-shadow .12s; }
.per-ib svg { width:18px; height:18px; }
.per-ib:hover { transform:translateY(-1px); }
.per-ib.v { background:#e3f1ed; color:#0f6b58; }  .per-ib.v:hover { background:#146c5b; color:#fff; box-shadow:0 4px 10px rgba(20,108,91,.3); }
.per-ib.e { background:#e5f0fa; color:#1f5f99; }  .per-ib.e:hover { background:#2b6ea8; color:#fff; box-shadow:0 4px 10px rgba(43,110,168,.3); }
.per-ib.d { background:#fbe6e3; color:#b3382a; }  .per-ib.d:hover { background:#c2402f; color:#fff; box-shadow:0 4px 10px rgba(194,64,47,.3); }
.per-ib:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }
.per-empty { padding:44px 16px; text-align:center; color:var(--text-muted); }
@media (max-width:900px){ .per-hide-m { display:none; } .per-lab { width:100%; } }
</style>

<div class="per-head">
    <div>
        <h1>Periods</h1>
        <p>A period is one payout cycle for one branch. Create one to get the service-charge summary and printable slips for that branch over its dates.</p>
    </div>
    <a class="btn per-add" href="<?= BASE_URL ?>/periods/form.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>New period</a>
</div>

<div class="per-filter">
    <?php if (!is_branch_locked() && count($branches) > 1): ?>
    <div class="per-row">
        <div class="per-lab">Branch</div>
        <div class="per-chips">
            <a class="per-chip <?= !$branchId ? 'on' : '' ?>" href="<?= h(per_url(['branch_id' => ''])) ?>">All <i><?= $cntAllBranch ?></i></a>
            <?php foreach ($branches as $b): $n = $cntBranch[(int)$b['id']] ?? 0; ?>
                <a class="per-chip <?= $branchId === (int)$b['id'] ? 'on' : '' ?> <?= $n === 0 ? 'zero' : '' ?>" href="<?= h(per_url(['branch_id' => (int)$b['id']])) ?>">
                    <span class="d" style="background:<?= per_color($b['name']) ?>"></span><?= h(branch_option_label($b)) ?> <i><?= $n ?></i></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <div class="per-row">
        <div class="per-lab">Status</div>
        <div class="per-chips">
            <a class="per-chip <?= $statusSel === '' ? 'on' : '' ?>" href="<?= h(per_url(['status' => ''])) ?>">All <i><?= $cntStatus[''] ?></i></a>
            <a class="per-chip <?= $statusSel === 'open' ? 'on' : '' ?>" href="<?= h(per_url(['status' => 'open'])) ?>">Open <i><?= $cntStatus['open'] ?></i></a>
            <a class="per-chip <?= $statusSel === 'closed' ? 'on' : '' ?>" href="<?= h(per_url(['status' => 'closed'])) ?>">Closed <i><?= $cntStatus['closed'] ?></i></a>
        </div>
    </div>
</div>

<div class="per-meta">
    <span>Showing <b><?= count($periods) ?></b> period<?= count($periods) === 1 ? '' : 's' ?></span>
    <?php if ($filtersOn): ?><a href="<?= BASE_URL ?>/periods/list.php">Clear filters</a><?php endif; ?>
</div>

<div class="per-card">
  <div class="per-scroll">
    <table class="per">
        <thead>
            <tr><th>Period</th><th>Branch</th><th>Dates</th><th class="num per-hide-m">Mgmt share</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($periods as $p):
            $sTs = strtotime($p['start_date']); $eTs = strtotime($p['end_date']);
            $days = (int)round(($eTs - $sTs) / 86400) + 1;
            $note = ''; $warn = false; $pct = null;
            if ($todayTs < $sTs) { $d = (int)round(($sTs - $todayTs) / 86400); $note = 'Starts in ' . $d . ' day' . ($d === 1 ? '' : 's'); }
            elseif ($todayTs > $eTs) {
                $d = (int)round(($todayTs - $eTs) / 86400);
                $note = $days . ' days · ended ' . $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
                if ($p['status'] === 'open') { $note .= ' — still open'; $warn = true; }
            } else {
                $done = (int)round(($todayTs - $sTs) / 86400) + 1; $pct = max(2, min(100, round($done / $days * 100)));
                $note = 'Day ' . $done . ' of ' . $days;
            }
        ?>
            <tr>
                <td><div class="per-name"><?= h($p['name']) ?></div></td>
                <td><span class="per-br"><i style="background:<?= per_color($p['branch_name']) ?>"></i><?= h($p['branch_name']) ?></span></td>
                <td>
                    <div class="per-dates"><?= h(date('M j', $sTs)) ?> – <?= h(date('M j, Y', $eTs)) ?></div>
                    <div class="per-note <?= $warn ? 'warn' : '' ?>"><?= h($note) ?></div>
                    <?php if ($pct !== null): ?><div class="per-prog"><i style="width:<?= $pct ?>%"></i></div><?php endif; ?>
                </td>
                <td class="num per-share per-hide-m"><?= h(rtrim(rtrim(number_format((float)$p['management_share_percent'], 2), '0'), '.')) ?>%</td>
                <td><span class="per-st <?= h($p['status']) ?>"><i></i><?= h(ucfirst($p['status'])) ?></span></td>
                <td>
                    <div class="per-act">
                        <a class="per-ib v" title="View summary" aria-label="View summary of <?= h($p['name']) ?>" href="<?= BASE_URL ?>/periods/view.php?id=<?= $p['id'] ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg></a>
                        <a class="per-ib e" title="Edit" aria-label="Edit <?= h($p['name']) ?>" href="<?= BASE_URL ?>/periods/form.php?id=<?= $p['id'] ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></a>
                        <form method="post" action="<?= BASE_URL ?>/periods/delete.php" onsubmit="return confirm('Delete period &quot;<?= h(addslashes($p['name'])) ?>&quot;? This also removes any deductions recorded for it. This cannot be undone.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <button type="submit" class="per-ib d" title="Delete" aria-label="Delete <?= h($p['name']) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg></button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$periods): ?>
            <tr><td colspan="6"><div class="per-empty"><?= $filtersOn ? 'No periods match these filters.' : 'No periods yet.' ?></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>