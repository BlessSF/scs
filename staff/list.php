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
$pageTitle = $__isSharedView ? 'Shared' : 'Staff';
require __DIR__ . '/../includes/header.php';
?>
<h1><?= $__isSharedView ? 'Shared' : 'Staff' ?></h1>
<p class="subtitle"><?= $__isSharedView
    ? 'Owners and other admins who aren\'t tied to one branch. Mark them "Hidden (Owner / Admin)" on their staff record and their cut is automatically included in every branch\'s period totals, without showing up in any branch\'s day-to-day views.'
    : 'Manage staff records, their branch, and their login accounts.' ?></p>

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

<div class="actions" style="justify-content:space-between; flex-wrap:wrap;">
    <a class="btn" href="<?= BASE_URL ?>/staff/form.php<?= $branchId ? '?branch_id=' . $branchId : '' ?>">+ Add Staff<?= ($branchId && $branchId === (function_exists('shared_branch_id') ? shared_branch_id() : 0)) ? ' to Shared' : '' ?></a>
    <form method="get" class="filter-bar">
        <input type="search" id="q" name="q" value="<?= h($search) ?>" placeholder="Search staff by name…" style="width:auto; min-width:180px;">
        <?php if (!is_branch_locked()): ?>
        <select id="branch_id" name="branch_id" onchange="this.form.submit()" style="width:auto;">
            <option value="0">All Branches</option>
            <?php foreach ($branches as $b): ?>
                <option value="<?= $b['id'] ?>" <?= $branchId === (int)$b['id'] ? 'selected' : '' ?>><?= h(branch_option_label($b)) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select id="status" name="status" onchange="this.form.submit()" style="width:auto;">
            <option value="">All Statuses</option>
            <option value="regular" <?= $status === 'regular' ? 'selected' : '' ?>>Regular</option>
            <option value="not_regular" <?= $status === 'not_regular' ? 'selected' : '' ?>>Not Regular</option>
            <option value="probationary" <?= $status === 'probationary' ? 'selected' : '' ?>>Probationary</option>
            <option value="resigned" <?= $status === 'resigned' ? 'selected' : '' ?>>Resigned</option>
        </select>
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <?php if ($branchId || $status || $search !== ''): ?>
            <a class="btn btn-sm btn-secondary" href="<?= BASE_URL ?>/staff/list.php">Clear</a>
        <?php endif; ?>
    </form>
</div>
<p style="margin:-4px 0 14px;">
    <span class="count-pill">👥 <?= count($staffList) ?> staff member<?= count($staffList) === 1 ? '' : 's' ?><?= ($branchId || $status || $search !== '') ? ' matching your filters' : '' ?></span>
</p>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Branch</th>
                <th>Status</th>
                <th>Date Added</th>
                <th>Remarks</th>
                <th>Login Account</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php
        $avatarPairs = [['--av-1','--av-1b'], ['--av-2','--av-2b'], ['--av-3','--av-3b'], ['--av-4','--av-4b'], ['--av-5','--av-5b'], ['--av-6','--av-6b']];
        foreach ($staffList as $s):
            $ustmt = $pdo->prepare('SELECT username FROM users WHERE staff_id = ?');
            $ustmt->execute([$s['id']]);
            $u = $ustmt->fetch();

            // Stable per-name initials + color, purely cosmetic.
            $nameParts = preg_split('/[\s,]+/', trim($s['full_name']), -1, PREG_SPLIT_NO_EMPTY);
            $initials = strtoupper(substr($nameParts[0] ?? '?', 0, 1) . substr(end($nameParts) ?: '', 0, 1));
            $pair = $avatarPairs[crc32($s['full_name']) % count($avatarPairs)];
        ?>
            <tr>
                <td>
                    <div class="name-cell">
                        <span class="avatar-chip" style="--av-c1:var(<?= $pair[0] ?>); --av-c2:var(<?= $pair[1] ?>);"><?= h($initials) ?></span>
                        <span class="nc-text">
                            <span class="nc-name"><?= h($s['full_name']) ?></span>
                            <?php if (!$s['is_active']): ?><span class="muted small">Inactive</span><?php endif; ?>
                        </span>
                    </div>
                </td>
                <td>
                    <?php $branchNames = staff_branch_names($s['id'], $s['branch_id']); ?>
                    <?php if (count($branchNames) > 1): ?>
                        <?php foreach ($branchNames as $bi => $bn): ?>
                            <span class="badge badge-regular" style="margin:1px 2px 1px 0;"><?= h($bn) ?><?= $bi === 0 ? ' (home)' : '' ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?= h($s['branch_name']) ?>
                    <?php endif; ?>
                </td>
                <td><span class="badge badge-<?= h($s['status']) ?>"><?= h(ucfirst(str_replace('_',' ',$s['status']))) ?></span></td>
                <td><?= h($s['date_added']) ?></td>
                <td class="small"><?= h($s['remarks'] ?: '—') ?></td>
                <td class="small"><?= $u ? h($u['username']) : '<span class="muted">none</span>' ?></td>
                <td>
                    <div class="row-actions">
                        <a class="btn btn-sm btn-secondary" href="<?= BASE_URL ?>/staff/form.php?id=<?= $s['id'] ?>">Edit</a>
                        <?php if ($u): ?>
                            <form method="post" action="<?= BASE_URL ?>/staff/reset_password.php" style="display:inline;" onsubmit="return confirm('Reset the password for <?= h(addslashes($s['full_name'])) ?>? Their current password will stop working immediately.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="staff_id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-secondary">Reset Password</button>
                            </form>
                        <?php endif; ?>
                        <?php if (is_admin()): ?>
                            <form method="post" action="<?= BASE_URL ?>/staff/delete.php" style="display:inline;" onsubmit="return confirm('Delete <?= h(addslashes($s['full_name'])) ?>? This cannot be undone. Staff with existing duty or deduction records can\'t be deleted — mark them Resigned instead.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$staffList): ?>
            <tr><td colspan="7" class="muted" style="text-align:center; padding:30px;">No staff records yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>