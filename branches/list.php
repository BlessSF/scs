<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_admin();

$branches = $pdo->query("SELECT b.*, p.name AS parent_name,
        (SELECT COUNT(DISTINCT s.id) FROM staff s LEFT JOIN staff_branches sb ON sb.staff_id = s.id
            WHERE s.is_active = 1 AND (s.branch_id = b.id OR sb.branch_id = b.id)) AS staff_count,
        (SELECT username FROM users u WHERE u.branch_id = b.id AND u.role = 'cashier' LIMIT 1) AS account_username,
        (SELECT COUNT(*) FROM branches c WHERE c.parent_branch_id = b.id) AS sub_branch_count
    FROM branches b
    LEFT JOIN branches p ON p.id = b.parent_branch_id
    ORDER BY COALESCE(p.is_active, b.is_active) DESC, COALESCE(p.name, b.name),
             (b.parent_branch_id IS NOT NULL), b.name")->fetchAll();

// One-time password reveal, set by branches/form.php or reset_password.php — read once, then discard.
$passwordReveal = $_SESSION['password_reveal'] ?? null;
unset($_SESSION['password_reveal']);

$pageTitle = 'Branches';
require __DIR__ . '/../includes/header.php';
?>
<h1>Branches</h1>
<p class="subtitle">Each branch has its own staff, duty calendar, periods, summaries — and its own login account, so a branch can only ever see its own data. Use "+ Sub-Branch" on a branch to create a separately-tracked unit under it (e.g. a bar's sales kept apart from the main floor) — it shares its parent branch's login rather than needing its own.</p>

<?php if ($passwordReveal): ?>
<div class="card password-reveal">
    <div class="pr-icon">🔑</div>
    <div class="pr-body">
        <div class="pr-title"><?= h($passwordReveal['staff_name']) ?></div>
        <div class="pr-sub">This is shown once and cannot be retrieved again after you leave this page. Copy it now and share it with whoever runs this branch.</div>
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

<div class="actions">
    <a class="btn" href="<?= BASE_URL ?>/branches/form.php">+ Add Branch</a>
</div>

<div class="card">
    <table>
        <thead><tr><th>Name</th><th>Address</th><th class="text-right">Active Staff</th><th>Login Account</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($branches as $b): $isSub = !empty($b['parent_branch_id']); ?>
            <tr class="<?= $isSub ? 'sub-branch-row' : '' ?>">
                <td>
                    <?php if ($isSub): ?>
                        <span class="muted" style="padding-left:18px;">↳ <?= h($b['name']) ?></span>
                        <div class="small muted" style="padding-left:18px;">Sub-branch of <?= h($b['parent_name']) ?></div>
                    <?php else: ?>
                        <?= h($b['name']) ?>
                        <?php if ($b['sub_branch_count']): ?>
                            <span class="small muted">(<?= (int)$b['sub_branch_count'] ?> sub-branch<?= $b['sub_branch_count'] == 1 ? '' : 'es' ?>)</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="small"><?= h($b['address'] ?: '—') ?></td>
                <td class="text-right"><?= (int)$b['staff_count'] ?></td>
                <td class="small">
                    <?php if ($b['account_username']): ?>
                        <?= h($b['account_username']) ?>
                    <?php elseif ($isSub): ?>
                        <span class="muted">Uses <?= h($b['parent_name']) ?>'s login</span>
                    <?php else: ?>
                        <span class="muted">none yet</span>
                    <?php endif; ?>
                </td>
                <td><?= $b['is_active'] ? '<span class="badge badge-regular">Active</span>' : '<span class="badge badge-resigned">Inactive</span>' ?></td>
                <td>
                    <div class="row-actions" style="display:flex;gap:6px;flex-wrap:nowrap;align-items:center;">
                        <a class="icon-btn ib-edit" title="Edit" aria-label="Edit <?= h($b['name']) ?>" href="<?= BASE_URL ?>/branches/form.php?id=<?= $b['id'] ?>"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></a>
                        <?php if (!$isSub): ?>
                            <a class="icon-btn ib-sub" title="Add sub-branch" aria-label="Add sub-branch under <?= h($b['name']) ?>" href="<?= BASE_URL ?>/branches/form.php?parent_id=<?= $b['id'] ?>"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="6" cy="5" r="2.5"/><circle cx="6" cy="19" r="2.5"/><path d="M6 7.5v9"/><path d="M6 12h6a4 4 0 0 1 4 4"/><path d="M18 3v6M15 6h6"/></svg></a>
                        <?php endif; ?>
                        <?php if (!$isSub || $b['account_username']): ?>
                        <form method="post" action="<?= BASE_URL ?>/branches/reset_password.php" style="display:inline;" onsubmit="return confirm(<?= $b['account_username'] ? h(json_encode('Reset the password for ' . $b['name'] . '\'s account? Its current password will stop working immediately.')) : h(json_encode('Create a login account for ' . $b['name'] . '?')) ?>);">
                            <?= csrf_field() ?>
                            <input type="hidden" name="branch_id" value="<?= $b['id'] ?>">
                            <?php $__lbl = $b['account_username'] ? 'Reset password' : 'Create login account'; ?>
                            <button type="submit" class="icon-btn <?= $b['account_username'] ? 'ib-key' : 'ib-new' ?>" title="<?= h($__lbl) ?>" aria-label="<?= h($__lbl) ?> for <?= h($b['name']) ?>"><?= $b['account_username'] ? '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3L21 2M16 7l3 3M14 9l2 2"/></svg>' : '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.4 3.2-5.5 6.5-5.5s5.9 2.1 6.5 5.5"/><path d="M19 8v6M16 11h6"/></svg>' ?></button>
                        </form>
                        <?php endif; ?>
                        <form method="post" action="<?= BASE_URL ?>/branches/delete.php" style="display:inline;" onsubmit="return confirm('Delete ' + <?= h(json_encode($b['name'])) ?> + '? This also removes its login account and cannot be undone.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                            <button type="submit" class="icon-btn ib-del" title="Delete" aria-label="Delete <?= h($b['name']) ?>"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg></button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$branches): ?>
            <tr><td colspan="6" class="muted">No branches yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<style>
.icon-btn { width:34px; height:34px; padding:0; display:inline-flex; align-items:center; justify-content:center; border-radius:10px;
    border:1px solid var(--border-strong); background:#fff; color:var(--text-muted); cursor:pointer; text-decoration:none;
    transition:background .12s, color .12s, border-color .12s, transform .1s; }
.icon-btn:hover { transform:translateY(-1px); }
.icon-btn.ib-edit:hover { background:var(--primary); border-color:var(--primary); color:#fff; }
.icon-btn.ib-sub:hover  { background:#2f7fb8; border-color:#2f7fb8; color:#fff; }
.icon-btn.ib-key:hover, .icon-btn.ib-new:hover { background:#e08a1e; border-color:#e08a1e; color:#fff; }
.icon-btn.ib-del:hover  { background:var(--danger); border-color:var(--danger); color:#fff; }
.icon-btn:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }
.row-actions form { margin:0 !important; display:inline-flex !important; }
</style>
<?php require __DIR__ . '/../includes/footer.php'; ?>