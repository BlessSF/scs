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
                    <div class="row-actions">
                        <a class="btn btn-sm btn-secondary" href="<?= BASE_URL ?>/branches/form.php?id=<?= $b['id'] ?>">Edit</a>
                        <?php if (!$isSub): ?>
                            <a class="btn btn-sm btn-secondary" href="<?= BASE_URL ?>/branches/form.php?parent_id=<?= $b['id'] ?>">+ Sub-Branch</a>
                        <?php endif; ?>
                        <?php if (!$isSub || $b['account_username']): ?>
                        <form method="post" action="<?= BASE_URL ?>/branches/reset_password.php" style="display:inline;" onsubmit="return confirm(<?= $b['account_username'] ? h(json_encode('Reset the password for ' . $b['name'] . '\'s account? Its current password will stop working immediately.')) : h(json_encode('Create a login account for ' . $b['name'] . '?')) ?>);">
                            <?= csrf_field() ?>
                            <input type="hidden" name="branch_id" value="<?= $b['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-secondary"><?= $b['account_username'] ? 'Reset Password' : 'Create Account' ?></button>
                        </form>
                        <?php endif; ?>
                        <form method="post" action="<?= BASE_URL ?>/branches/delete.php" style="display:inline;" onsubmit="return confirm('Delete ' + <?= h(json_encode($b['name'])) ?> + '? This also removes its login account and cannot be undone.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
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
<?php require __DIR__ . '/../includes/footer.php'; ?>
