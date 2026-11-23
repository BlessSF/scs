<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_shared_access();   // only Claribel Moquete (admin) and admin2

$sharedId = function_exists('shared_branch_id') ? shared_branch_id() : 0;
if (!$sharedId) {
    // No "Shared" branch in this database yet -- create it once, automatically.
    $pdo->prepare('INSERT INTO branches (name, is_active) VALUES (?, 1)')->execute(['Shared']);
    $sharedId = (int)$pdo->lastInsertId();
    if (function_exists('audit_log')) {
        call_user_func('audit_log', 'create', 'branch', $sharedId, 'Shared', 'Auto-created the "Shared" branch', [], $sharedId);
    }
}

$status = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');

$validStatuses = ['regular', 'not_regular', 'resigned', 'probationary'];

$sql = 'SELECT DISTINCT s.*, b.name AS branch_name
        FROM staff s
        JOIN branches b ON b.id = s.branch_id
        LEFT JOIN staff_branches sb ON sb.staff_id = s.id
        WHERE (s.branch_id = ? OR sb.branch_id = ?)';
$params = [$sharedId, $sharedId];

if ($status && in_array($status, $validStatuses, true)) {
    $sql .= ' AND s.status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $sql .= ' AND s.full_name LIKE ?';
    $params[] = '%' . $search . '%';
}
$sql .= ' ORDER BY s.is_active DESC, s.full_name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$staffList = $stmt->fetchAll();

// One-time password reveal, set by reset_password.php — read once, then discard.
$passwordReveal = $_SESSION['password_reveal'] ?? null;
unset($_SESSION['password_reveal']);

$pageTitle = 'Shared';
require __DIR__ . '/../includes/header.php';
?>
<style>
.sh-head { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:6px; }
.sh-head h1 { margin:0; }
.sh-note { display:flex; gap:10px; align-items:flex-start; background:#f3f8f6; border:1px solid #d9e8e2; border-radius:10px; padding:10px 14px; margin:8px 0 18px; font-size:14px; color:var(--text-muted); max-width:860px; }
.sh-note svg { flex:none; width:18px; height:18px; margin-top:1px; color:var(--primary); }
.sh-note b { color:var(--text); font-weight:600; }
</style>
<div class="sh-head"><h1>Shared</h1></div>
<div class="sh-note">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v5h1"/></svg>
    <span>Owners and admins who aren't tied to one branch. Tick <b>Hidden (Owner / Admin)</b> on their staff record and their cut is added to every branch's period totals, without appearing in any branch's daily views.</span>
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

<style>
.sh-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
.sh-bar form { display:flex; align-items:center; gap:10px; flex-wrap:wrap; flex:1; margin:0; }
.sh-search { position:relative; flex:1 1 240px; max-width:340px; }
.sh-search svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); width:16px; height:16px; color:var(--text-faint); pointer-events:none; }
.sh-search input { width:100%; height:40px; margin:0; padding:0 12px 0 36px; font-size:14.5px; }
.sh-seg { max-width:100%; overflow-x:auto; display:flex; border:1px solid var(--border-strong); border-radius:10px; overflow:hidden; height:40px; background:var(--surface); }
.sh-seg a { display:flex; align-items:center; padding:0 14px; font-size:13.5px; font-weight:600; color:var(--text); text-decoration:none; border-right:1px solid var(--border-strong); white-space:nowrap; }
.sh-seg a:last-child { border-right:0; }
.sh-seg a:hover { background:var(--bg-alt); }
.sh-seg a.on { background:var(--primary); color:#fff; }
.sh-count { color:var(--text-muted); font-size:13.5px; margin-left:auto; white-space:nowrap; }
.sh-add { display:inline-flex; align-items:center; gap:7px; height:40px; padding:0 18px; margin:0; }
.sh-add svg { width:16px; height:16px; }

.sh-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }
.sh-scroll { overflow-x:auto; }
table.sh { width:100%; border-collapse:collapse; font-size:14.5px; }
table.sh th { text-align:left; font-size:11.5px; letter-spacing:.05em; text-transform:uppercase; color:var(--text-muted); padding:11px 16px; border-bottom:1px solid var(--border-strong); background:var(--surface-soft); white-space:nowrap; }
table.sh td { padding:14px 16px; border-bottom:1px solid var(--border); vertical-align:middle; }
table.sh tr:last-child td { border-bottom:0; }
table.sh tbody tr:hover td { background:#f6faf8; }
table.sh tr.off td { opacity:.6; }
.sh-person { display:flex; align-items:center; gap:12px; min-width:200px; }
.sh-av { width:38px; height:38px; border-radius:50%; flex:none; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:800; background:#e4efeb; color:var(--primary-dark); }
.sh-nm { font-weight:700; font-size:15px; line-height:1.25; color:var(--text); }
.sh-sub { font-size:12.5px; color:var(--text-faint); margin-top:1px; }
.sh-mh { text-align:center !important; padding-bottom:6px !important; }
tr.sh-sub-row th { padding-top:6px; padding-bottom:9px; text-align:center; font-size:11px; letter-spacing:.03em; color:var(--text-muted); line-height:1.2; min-width:76px; max-width:110px; white-space:normal; }
.sh-cell { text-align:center; }
.sh-yes { display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:50%; background:var(--primary); color:#fff; }
.sh-yes svg { width:14px; height:14px; }
.sh-no { color:var(--border-strong); font-weight:700; }

.sh-home { display:inline-block; padding:2px 9px; border-radius:6px; background:#e4efeb; color:var(--primary-dark); font-size:12.5px; font-weight:700; }
.sh-also { display:block; font-size:12.5px; color:var(--text-muted); margin-top:3px; }
.sh-st { display:inline-flex; align-items:center; gap:7px; font-size:13.5px; font-weight:600; white-space:nowrap; }
.sh-st i { width:8px; height:8px; border-radius:50%; background:var(--success); }
.sh-st.not_regular i { background:var(--warning); }
.sh-st.probationary i { background:#3c4a9e; }
.sh-st.resigned i { background:var(--danger); }
.sh-date { color:var(--text-muted); white-space:nowrap; font-size:13.5px; }
.sh-login code { font:600 13px ui-monospace,Consolas,monospace; background:var(--bg-alt); padding:3px 8px; border-radius:6px; color:var(--text); }
.sh-login .none { color:var(--text-faint); font-size:13px; }
.sh-act { display:flex; gap:6px; justify-content:flex-end; }
.sh-act form { margin:0; display:inline-flex; }
.sh-ib { width:34px; height:34px; padding:0; display:inline-flex; align-items:center; justify-content:center; border-radius:10px; border:1px solid var(--border-strong); background:#fff; color:var(--text-muted); cursor:pointer; text-decoration:none; transition:background .12s,color .12s,border-color .12s; }
.sh-ib svg { width:17px; height:17px; }
.sh-ib:hover.e { background:var(--primary); border-color:var(--primary); color:#fff; }
.sh-ib:hover.k { background:#e08a1e; border-color:#e08a1e; color:#fff; }
.sh-ib:hover.d { background:var(--danger); border-color:var(--danger); color:#fff; }
.sh-ib:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }
.sh-empty { padding:44px 16px; text-align:center; color:var(--text-muted); }
@media (max-width:900px){ .sh-hide-m { display:none; } .sh-count { margin-left:0; } }
</style>

<?php
$statusTabs = ['' => 'All', 'regular' => 'Regular', 'probationary' => 'Probationary', 'not_regular' => 'Not regular', 'resigned' => 'Resigned'];
function sh_url($over) { $q = array_merge($_GET, $over); foreach ($q as $k => $v) if ($v === '') unset($q[$k]); return '?' . http_build_query($q); }
?>
<?php
// Branch columns (everything except the Shared pseudo-branch).
$matrixBranches = array_values(array_filter(get_branches(true), fn($b) => (int)$b['id'] !== (int)$sharedId));
?>
<div class="sh-bar">
    <form method="get">
        <?php if ($status): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
        <div class="sh-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <input type="search" name="q" value="<?= h($search) ?>" placeholder="Search by name">
        </div>
        <div class="sh-seg">
            <?php foreach ($statusTabs as $k => $label): ?>
                <a class="<?= $status === $k ? 'on' : '' ?>" href="<?= h(sh_url(['status' => $k])) ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </div>
        <span class="sh-count"><?= count($staffList) ?> <?= count($staffList) === 1 ? 'person' : 'people' ?></span>
    </form>
    <a class="btn sh-add" href="<?= BASE_URL ?>/staff/form.php?branch_id=<?= $sharedId ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>Add staff</a>
</div>

<div class="sh-card">
    <div class="sh-scroll">
    <table class="sh">
        <thead>
            <tr>
                <th rowspan="2">Name</th>
                <th class="sh-mh" colspan="<?= max(1, count($matrixBranches)) ?>">Shared in</th>
                <th rowspan="2">Status</th>
                <th rowspan="2" class="sh-hide-m">Added</th>
                <th rowspan="2">Login</th>
                <th rowspan="2"></th>
            </tr>
            <tr class="sh-sub-row">
                <?php foreach ($matrixBranches as $mb): ?><th class="sh-bh"><?= h($mb['name']) ?></th><?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($staffList as $s):
            $ustmt = $pdo->prepare('SELECT username FROM users WHERE staff_id = ?');
            $ustmt->execute([$s['id']]);
            $u = $ustmt->fetch();

            $nameParts = preg_split('/[\s,]+/', trim($s['full_name']), -1, PREG_SPLIT_NO_EMPTY);
            $initials = strtoupper(substr($nameParts[0] ?? '?', 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : substr($nameParts[0] ?? '', 1, 1)));

            // Where is this person actually shared? Active hidden owners are added to
            // EVERY branch's duty days automatically; anyone else only counts where assigned.
            $autoAll = !empty($s['is_hidden']) && !empty($s['is_active']);
            $assignedIds = array_map('intval', get_staff_branch_ids($s['id']));
            $assignedIds[] = (int)$s['branch_id'];
            $sharedCount = 0;
            $cells = [];
            foreach ($matrixBranches as $mb) {
                $on = $autoAll || in_array((int)$mb['id'], $assignedIds, true);
                $cells[] = $on; if ($on) $sharedCount++;
            }
            $stLabel = ucfirst(str_replace('_', ' ', $s['status']));
        ?>
            <tr class="<?= $s['is_active'] ? '' : 'off' ?>">
                <td>
                    <div class="sh-person">
                        <span class="sh-av"><?= h($initials) ?></span>
                        <div>
                            <div class="sh-nm"><?= h($s['full_name']) ?></div>
                            <div class="sh-sub"><?php
                                if (!$s['is_active']) echo 'Inactive';
                                elseif ($autoAll) echo 'All branches';
                                else echo $sharedCount . ' of ' . count($matrixBranches) . ' branches';
                                if (!empty($s['remarks'])) echo ' · ' . h($s['remarks']);
                            ?></div>
                        </div>
                    </div>
                </td>
                <?php foreach ($cells as $on): ?>
                <td class="sh-cell"><?php if ($on): ?><span class="sh-yes" title="Shared in this branch"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span><?php else: ?><span class="sh-no" title="Not shared in this branch">–</span><?php endif; ?></td>
                <?php endforeach; ?>
                <td><span class="sh-st <?= h($s['status']) ?>"><i></i><?= h($stLabel) ?></span></td>
                <td class="sh-date sh-hide-m"><?= h(date('M j, Y', strtotime($s['date_added']))) ?></td>
                <td class="sh-login"><?= $u ? '<code>' . h($u['username']) . '</code>' : '<span class="none">No login</span>' ?></td>
                <td>
                    <div class="sh-act">
                        <a class="sh-ib e" title="Edit" aria-label="Edit <?= h($s['full_name']) ?>" href="<?= BASE_URL ?>/staff/form.php?id=<?= $s['id'] ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></a>
                        <?php if ($u): ?>
                            <form method="post" action="<?= BASE_URL ?>/staff/reset_password.php" onsubmit="return confirm('Reset the password for <?= h(addslashes($s['full_name'])) ?>? Their current password will stop working immediately.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="staff_id" value="<?= $s['id'] ?>">
                                <button type="submit" class="sh-ib k" title="Reset password" aria-label="Reset password for <?= h($s['full_name']) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3L21 2M16 7l3 3M14 9l2 2"/></svg></button>
                            </form>
                        <?php endif; ?>
                        <?php if (is_admin()): ?>
                            <form method="post" action="<?= BASE_URL ?>/staff/delete.php" onsubmit="return confirm('Delete <?= h(addslashes($s['full_name'])) ?>? This cannot be undone. Staff with existing duty or deduction records can\'t be deleted — mark them Resigned instead.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <button type="submit" class="sh-ib d" title="Delete" aria-label="Delete <?= h($s['full_name']) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$staffList): ?>
            <tr><td colspan="<?= 5 + max(1, count($matrixBranches)) ?>"><div class="sh-empty"><?= ($status || $search !== '') ? 'No one matches this filter.' : 'No shared staff yet.' ?></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>