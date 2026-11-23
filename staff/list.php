<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role(['admin', 'cashier']);

$branches = visible_branches(false);
$branchId = scoped_branch_id($_GET['branch_id'] ?? 0);
$status   = trim($_GET['status'] ?? '');
$search   = trim($_GET['q'] ?? '');

$validStatuses = ['regular', 'not_regular', 'resigned', 'probationary'];

$sql = 'SELECT DISTINCT s.*, b.name AS branch_name
        FROM staff s
        JOIN branches b ON b.id = s.branch_id
        LEFT JOIN staff_branches sb ON sb.staff_id = s.id
        WHERE 1=1';
// Only the Shared-page accounts (admin, admin2) may see hidden owner/admin staff.
if (!can_view_shared()) {
    $sql .= ' AND s.is_hidden = 0';
}
$params = [];
if ($branchId) {
    // Match staff whose home branch is this one, OR who are additionally
    // assigned to it (e.g. shared staff visible in both Hero and H-Bar).
    $sql .= ' AND (s.branch_id = ? OR sb.branch_id = ?)';
    $params[] = $branchId;
    $params[] = $branchId;
}
if ($status && in_array($status, $validStatuses, true)) {
    $sql .= ' AND s.status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $sql .= ' AND s.full_name LIKE ?';
    $params[] = '%' . $search . '%';
}
$sql .= ' ORDER BY b.name, s.is_active DESC, s.full_name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$staffList = $stmt->fetchAll();

// One-time password reveal, set by reset_password.php — read once, then discard.
$passwordReveal = $_SESSION['password_reveal'] ?? null;
unset($_SESSION['password_reveal']);

$__isSharedView = $branchId && $branchId === (function_exists('shared_branch_id') ? shared_branch_id() : 0);

// ---- Counts for the filter chips ------------------------------------------
// Each set of counts respects the OTHER filters, so a chip always tells you how
// many people you would get if you clicked it.
$hiddenSql = can_view_shared() ? '' : ' AND s.is_hidden = 0';
$cnt = function (string $extraWhere, array $extraParams) use ($pdo, $hiddenSql, $search) {
    $q = "SELECT COUNT(DISTINCT s.id) FROM staff s LEFT JOIN staff_branches sb ON sb.staff_id = s.id WHERE 1=1" . $hiddenSql;
    $p = [];
    if ($search !== '') { $q .= ' AND s.full_name LIKE ?'; $p[] = '%' . $search . '%'; }
    $q .= $extraWhere; $p = array_merge($p, $extraParams);
    $st = $pdo->prepare($q); $st->execute($p);
    return (int)$st->fetchColumn();
};
$statusClause = ($status && in_array($status, $validStatuses, true)) ? ' AND s.status = ?' : '';
$statusParam  = $statusClause ? [$status] : [];
$branchChips = [];
foreach ($branches as $b) {
    $bid = (int)$b['id'];
    $branchChips[] = ['id' => $bid, 'name' => $b['name'], 'sub' => !empty($b['parent_branch_id']),
        'n' => $cnt(' AND (s.branch_id = ? OR sb.branch_id = ?)' . $statusClause, array_merge([$bid, $bid], $statusParam))];
}
$branchAll = $cnt('' . $statusClause, $statusParam);
$branchScope = $branchId ? ' AND (s.branch_id = ? OR sb.branch_id = ?)' : '';
$branchScopeP = $branchId ? [$branchId, $branchId] : [];
$statusChips = ['' => ['All', $cnt($branchScope, $branchScopeP)]];
foreach (['regular' => 'Regular', 'probationary' => 'Probationary', 'not_regular' => 'Not regular', 'resigned' => 'Resigned'] as $k => $label) {
    $statusChips[$k] = [$label, $cnt($branchScope . ' AND s.status = ?', array_merge($branchScopeP, [$k]))];
}
function stf_url(array $over) {
    $q = array_merge($_GET, $over);
    foreach ($q as $k => $v) if ($v === '' || $v === 0 || $v === '0' || $v === null) unset($q[$k]);
    return '?' . http_build_query($q);
}
function stf_branch_color($name) {
    static $map = []; static $palette = ['#e08a1e', '#1fa24d', '#3b5b8f', '#b8436a', '#7a5ab0', '#2a9aa8'];
    $u = strtoupper($name);
    if (strpos($u, 'DOIS') !== false) return '#e08a1e';
    if (strpos($u, 'STELLA') !== false) return '#1fa24d';
    if (strpos($u, 'HERO') !== false) return '#c2201c';
    if (strpos($u, 'SHARED') !== false) return '#8b948d';
    if (!isset($map[$u])) $map[$u] = $palette[count($map) % count($palette)];
    return $map[$u];
}
$groupCounts = [];
foreach ($staffList as $__s) { $groupCounts[$__s['branch_name']] = ($groupCounts[$__s['branch_name']] ?? 0) + 1; }
$groupBy = !$branchId && count($groupCounts) > 1;   // group rows only when viewing several branches
$filtersOn = ($branchId && !is_branch_locked()) || $status || $search !== '';

$pageTitle = $__isSharedView ? 'Shared' : 'Staff';
require __DIR__ . '/../includes/header.php';
?>
<style>
.stf-head { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
.stf-head h1 { margin:0; }
.stf-head p { margin:4px 0 0; color:var(--text-muted); max-width:760px; }
.stf-add { display:inline-flex; align-items:center; gap:7px; height:42px; padding:0 20px; margin:0; }
.stf-add svg { width:16px; height:16px; }

.stf-filter { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:14px 16px; margin-bottom:14px; display:grid; gap:12px; }
.stf-search { position:relative; max-width:420px; }
.stf-search svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); width:16px; height:16px; color:var(--text-faint); pointer-events:none; }
.stf-search input { width:100%; height:40px; margin:0; padding:0 12px 0 36px; font-size:14.5px; }
.stf-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.stf-lab { font-size:12px; font-weight:700; color:var(--text-muted); width:64px; flex:none; }
.stf-chips { display:flex; gap:6px; flex-wrap:wrap; }
.stf-chip { display:inline-flex; align-items:center; gap:7px; height:34px; padding:0 8px 0 13px; border:1px solid var(--border-strong); border-radius:999px; font-size:13.5px; font-weight:600; color:var(--text); background:var(--surface); text-decoration:none; white-space:nowrap; }
.stf-chip i { font-style:normal; min-width:24px; height:22px; padding:0 7px; border-radius:999px; background:var(--bg-alt); color:var(--text-muted); font-size:12px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
.stf-chip:hover { background:var(--bg-alt); }
.stf-chip.on { background:var(--primary); border-color:var(--primary); color:#fff; }
.stf-chip.on i { background:rgba(255,255,255,.22); color:#fff; }
.stf-chip .d { width:9px; height:9px; border-radius:3px; }
.stf-chip.zero { color:var(--text-faint); }
.stf-meta { display:flex; align-items:center; justify-content:space-between; gap:12px; margin:0 2px 10px; color:var(--text-muted); font-size:13.5px; }
.stf-meta a { color:var(--primary-dark); font-weight:600; text-decoration:none; }
.stf-meta a:hover { text-decoration:underline; }

.stf-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; box-shadow:0 1px 2px rgba(15,35,28,.04); }
.stf-scroll { overflow:auto; max-height:calc(100vh - 150px); }
table.stf { width:100%; border-collapse:collapse; font-size:14.5px; }
table.stf th { position:sticky; top:0; z-index:1; text-align:left; font-size:11.5px; letter-spacing:.05em; text-transform:uppercase; color:var(--text-muted); padding:11px 16px; border-bottom:1px solid var(--border-strong); background:var(--surface-soft); white-space:nowrap; }
table.stf td { padding:12px 16px; border-bottom:1px solid var(--border); vertical-align:middle; }
table.stf tr:last-child td { border-bottom:0; }
table.stf tbody tr:hover td { background:#f6faf8; }
table.stf tr.off td { opacity:.6; }
.stf-group td { padding:10px 16px; background:#f3f6f4; border-bottom:1px solid var(--border-strong); font-size:12px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--text-muted); }
.stf-group .gdot { display:inline-block; width:10px; height:10px; border-radius:3px; margin-right:9px; vertical-align:-1px; }
.stf-group em { font-style:normal; margin-left:10px; padding:1px 8px; border-radius:999px; background:#e3e9e6; color:var(--text-muted); font-size:11.5px; letter-spacing:0; }
table.stf tbody tr.stf-group:hover td { background:#f3f6f4; }
.stf-person { display:flex; align-items:center; gap:12px; min-width:220px; }
.stf-av { width:36px; height:36px; border-radius:50%; flex:none; display:flex; align-items:center; justify-content:center; font-size:12.5px; font-weight:800; background:#e4efeb; color:var(--primary-dark); }
.stf-nm { font-weight:700; font-size:14.5px; line-height:1.25; }
.stf-sub { font-size:12.5px; color:var(--text-faint); margin-top:1px; }
.stf-tag { display:inline-block; margin-left:8px; padding:1px 8px; border-radius:999px; background:#eef0f5; color:#4a5270; font-size:10.5px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; vertical-align:2px; }
.stf-br { display:inline-flex; align-items:center; gap:8px; font-weight:600; }
.stf-br i { width:9px; height:9px; border-radius:3px; flex:none; }
.stf-also { display:block; font-size:12.5px; color:var(--text-muted); margin:2px 0 0 17px; font-weight:400; }
.stf-st { display:inline-flex; align-items:center; gap:7px; font-size:13.5px; font-weight:600; white-space:nowrap; }
.stf-st i { width:8px; height:8px; border-radius:50%; background:var(--success); }
.stf-st.not_regular i { background:var(--warning); }
.stf-st.probationary i { background:#3c4a9e; }
.stf-st.resigned i { background:var(--danger); }
.stf-date { color:var(--text-muted); white-space:nowrap; font-size:13.5px; }
.stf-login code { font:600 13px ui-monospace,Consolas,monospace; background:var(--bg-alt); padding:3px 8px; border-radius:6px; }
.stf-login .none { color:var(--text-faint); font-size:13px; }
.stf-act { display:flex; gap:6px; justify-content:flex-end; }
.stf-act form { margin:0; display:inline-flex; }
.stf-ib { width:34px; height:34px; padding:0; display:inline-flex; align-items:center; justify-content:center; border-radius:10px; border:1px solid var(--border-strong); background:#fff; color:var(--text-muted); cursor:pointer; text-decoration:none; transition:background .12s,color .12s,border-color .12s; }
.stf-ib svg { width:17px; height:17px; }
.stf-ib.e:hover { background:var(--primary); border-color:var(--primary); color:#fff; }
.stf-ib.k:hover { background:#e08a1e; border-color:#e08a1e; color:#fff; }
.stf-ib.d:hover { background:var(--danger); border-color:var(--danger); color:#fff; }
.stf-ib:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }
.stf-empty { padding:44px 16px; text-align:center; color:var(--text-muted); }
@media (max-width:900px){ .stf-hide-m { display:none; } .stf-lab { width:100%; } }
</style>

<div class="stf-head">
    <div>
        <h1><?= $__isSharedView ? 'Shared' : 'Staff' ?></h1>
        <p><?= $__isSharedView
            ? 'Owners and other admins who aren\'t tied to one branch.'
            : 'Staff records, their branches, and their login accounts.' ?></p>
    </div>
    <a class="btn stf-add" href="<?= BASE_URL ?>/staff/form.php<?= $branchId ? '?branch_id=' . $branchId : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>Add staff<?= $__isSharedView ? ' to Shared' : '' ?></a>
</div>

<?php if ($passwordReveal): ?>
<div class="card password-reveal">
    <div class="pr-icon">🔑</div>
    <div class="pr-body">
        <div class="pr-title">New password for <?= h($passwordReveal['staff_name']) ?></div>
        <div class="pr-sub">This is shown once and cannot be retrieved again after you leave this page. Copy it now and share it with the staff member.</div>
        <div class="pr-row">
            <span class="pr-label">Username</span>
            <code class="pr-value"><?= h($passwordReveal['username']) ?></code>
        </div>
        <div class="pr-row">
            <span class="pr-label">Password</span>
            <code class="pr-value" id="prPassword"><?= h($passwordReveal['password']) ?></code>
            <button type="button" class="btn btn-sm btn-secondary" id="prToggle">Show</button>
            <button type="button" class="btn btn-sm btn-secondary" id="prCopy">Copy</button>
        </div>
    </div>
</div>
<script>
(function () {
    var passEl = document.getElementById('prPassword');
    var toggleBtn = document.getElementById('prToggle');
    var copyBtn = document.getElementById('prCopy');
    if (!passEl) return;
    var real = passEl.textContent;
    var masked = '•'.repeat(real.length);
    passEl.textContent = masked;
    var shown = false;
    toggleBtn.addEventListener('click', function () {
        shown = !shown;
        passEl.textContent = shown ? real : masked;
        toggleBtn.textContent = shown ? 'Hide' : 'Show';
    });
    copyBtn.addEventListener('click', function () {
        navigator.clipboard.writeText(real).then(function () {
            copyBtn.textContent = 'Copied!';
            setTimeout(function () { copyBtn.textContent = 'Copy'; }, 1500);
        });
    });
})();
</script>
<?php endif; ?>

<div class="stf-filter">
    <form method="get" class="stf-search" style="margin:0;">
        <?php if ($branchId && !is_branch_locked()): ?><input type="hidden" name="branch_id" value="<?= (int)$branchId ?>"><?php endif; ?>
        <?php if ($status): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
        <input type="search" name="q" value="<?= h($search) ?>" placeholder="Search by name…">
    </form>

    <?php if (!is_branch_locked() && count($branchChips) > 1): ?>
    <div class="stf-row">
        <div class="stf-lab">Branch</div>
        <div class="stf-chips">
            <a class="stf-chip <?= !$branchId ? 'on' : '' ?>" href="<?= h(stf_url(['branch_id' => ''])) ?>">All <i><?= $branchAll ?></i></a>
            <?php foreach ($branchChips as $c): ?>
                <a class="stf-chip <?= $branchId === $c['id'] ? 'on' : '' ?> <?= $c['n'] === 0 ? 'zero' : '' ?>" href="<?= h(stf_url(['branch_id' => $c['id']])) ?>">
                    <span class="d" style="background:<?= stf_branch_color($c['name']) ?>"></span><?= h(($c['sub'] ? '— ' : '') . $c['name']) ?> <i><?= $c['n'] ?></i></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="stf-row">
        <div class="stf-lab">Status</div>
        <div class="stf-chips">
            <?php foreach ($statusChips as $k => [$label, $n]): ?>
                <a class="stf-chip <?= $status === $k ? 'on' : '' ?> <?= ($n === 0 && $k !== '') ? 'zero' : '' ?>" href="<?= h(stf_url(['status' => $k])) ?>"><?= h($label) ?> <i><?= $n ?></i></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="stf-meta">
    <span>Showing <b><?= count($staffList) ?></b> staff member<?= count($staffList) === 1 ? '' : 's' ?></span>
    <?php if ($filtersOn): ?><a href="<?= BASE_URL ?>/staff/list.php">Clear filters</a><?php endif; ?>
</div>

<div class="stf-card">
  <div class="stf-scroll">
    <table class="stf">
        <thead>
            <tr>
                <th>Name</th>
                <th>Branch</th>
                <th>Status</th>
                <th class="stf-hide-m">Added</th>
                <th>Login</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php $lastGroup = null; foreach ($staffList as $s):
            $gcol = stf_branch_color($s['branch_name']);
            if ($groupBy && $s['branch_name'] !== $lastGroup): $lastGroup = $s['branch_name']; ?>
            <tr class="stf-group"><td colspan="6"><span class="gdot" style="background:<?= $gcol ?>"></span><?= h($s['branch_name']) ?><em><?= $groupCounts[$s['branch_name']] ?></em></td></tr>
            <?php endif;
            $ustmt = $pdo->prepare('SELECT username FROM users WHERE staff_id = ?');
            $ustmt->execute([$s['id']]);
            $u = $ustmt->fetch();

            $nameParts = preg_split('/[\s,]+/', trim($s['full_name']), -1, PREG_SPLIT_NO_EMPTY);
            $initials = strtoupper(substr($nameParts[0] ?? '?', 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : substr($nameParts[0] ?? '', 1, 1)));

            $branchNames = staff_branch_names($s['id'], $s['branch_id']);
            $home = $branchNames[0] ?? $s['branch_name'];
            $also = array_slice($branchNames, 1);
            $stLabel = ucfirst(str_replace('_', ' ', $s['status']));
        ?>
            <tr class="<?= $s['is_active'] ? '' : 'off' ?>">
                <td>
                    <div class="stf-person">
                        <span class="stf-av" style="background:<?= $gcol ?>1f; color:<?= $gcol ?>;"><?= h($initials) ?></span>
                        <div>
                            <div class="stf-nm"><?= h($s['full_name']) ?><?php if (!empty($s['is_hidden'])): ?><span class="stf-tag" title="Hidden owner / admin">Hidden</span><?php endif; ?></div>
                            <?php if (!$s['is_active'] || !empty($s['remarks'])): ?>
                                <div class="stf-sub"><?= !$s['is_active'] ? 'Inactive' : '' ?><?= (!$s['is_active'] && !empty($s['remarks'])) ? ' · ' : '' ?><?= h($s['remarks'] ?? '') ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td>
                    <span class="stf-br"><i style="background:<?= stf_branch_color($home) ?>"></i><?= h($home) ?></span>
                    <?php if ($also): ?><span class="stf-also">Also: <?= h(implode(', ', $also)) ?></span><?php endif; ?>
                </td>
                <td><span class="stf-st <?= h($s['status']) ?>"><i></i><?= h($stLabel) ?></span></td>
                <td class="stf-date stf-hide-m"><?= h(date('M j, Y', strtotime($s['date_added']))) ?></td>
                <td class="stf-login"><?= $u ? '<code>' . h($u['username']) . '</code>' : '<span class="none">No login</span>' ?></td>
                <td>
                    <div class="stf-act">
                        <a class="stf-ib e" title="Edit" aria-label="Edit <?= h($s['full_name']) ?>" href="<?= BASE_URL ?>/staff/form.php?id=<?= $s['id'] ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></a>
                        <?php if ($u): ?>
                            <form method="post" action="<?= BASE_URL ?>/staff/reset_password.php" onsubmit="return confirm('Reset the password for <?= h(addslashes($s['full_name'])) ?>? Their current password will stop working immediately.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="staff_id" value="<?= $s['id'] ?>">
                                <button type="submit" class="stf-ib k" title="Reset password" aria-label="Reset password for <?= h($s['full_name']) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3L21 2M16 7l3 3M14 9l2 2"/></svg></button>
                            </form>
                        <?php endif; ?>
                        <?php if (is_admin()): ?>
                            <form method="post" action="<?= BASE_URL ?>/staff/delete.php" onsubmit="return confirm('Delete <?= h(addslashes($s['full_name'])) ?>? This cannot be undone. Staff with existing duty or deduction records can\'t be deleted — mark them Resigned instead.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <button type="submit" class="stf-ib d" title="Delete" aria-label="Delete <?= h($s['full_name']) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$staffList): ?>
            <tr><td colspan="6"><div class="stf-empty"><?= $filtersOn ? 'No staff match these filters.' : 'No staff records yet.' ?></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>