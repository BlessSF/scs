<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/auth.php';
require __DIR__ . '/includes/functions.php';
require_admin();

$ownerReveal = $_SESSION['owner_reveal'] ?? null;
unset($_SESSION['owner_reveal']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_owner') {
    csrf_verify();
    $fullName = trim($_POST['owner_full_name'] ?? '');
    $username = trim($_POST['owner_username'] ?? '');
    $password = $_POST['owner_password'] ?? '';
    $linkStaff = (int)($_POST['owner_staff_id'] ?? 0) ?: null;
    if ($password === '') $password = substr(bin2hex(random_bytes(5)), 0, 10);
    if ($fullName === '' || $username === '') {
        flash('error', 'Owner name and username are required.');
    } else {
        $chk = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $chk->execute([$username]);
        if ($chk->fetch()) {
            flash('error', 'That username is already taken.');
        } else {
            $pdo->prepare("INSERT INTO users (username, password, role, branch_id, staff_id, full_name, is_active) VALUES (?, ?, 'owner', NULL, ?, ?, 1)")
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $linkStaff, $fullName]);
            $_SESSION['owner_reveal'] = ['label' => 'Owner account created', 'username' => $username, 'password' => $password];
            flash('success', 'Owner account created.');
        }
    }
    redirect('/settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_owner') {
    csrf_verify();
    $uid = (int)($_POST['user_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'owner'");
    $stmt->execute([$uid]);
    if ($u = $stmt->fetch()) {
        $password = substr(bin2hex(random_bytes(5)), 0, 10);
        $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
        $_SESSION['owner_reveal'] = ['label' => 'New password for ' . $u['full_name'], 'username' => $u['username'], 'password' => $password];
    }
    redirect('/settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_owner') {
    csrf_verify();
    $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ? AND role = 'owner'")->execute([(int)($_POST['user_id'] ?? 0)]);
    redirect('/settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    set_setting('company_name', trim($_POST['company_name'] ?? ''));
    set_setting('currency_symbol', trim($_POST['currency_symbol'] ?? '₱'));
    set_setting('default_management_share_percent', (float)($_POST['default_management_share_percent'] ?? 20));
    set_setting('overcost_rate', (float)($_POST['overcost_rate'] ?? 0.11112393));
    set_setting('approved_by_name', trim($_POST['approved_by_name'] ?? ''));
    flash('success', 'Settings updated.');
    redirect('/settings.php');
}

$pageTitle = 'Settings';
require __DIR__ . '/includes/header.php';
?>
<h1>Settings</h1>

<div class="card">
    <form method="post" action="">
        <?= csrf_field() ?>
        <label for="company_name">Company Name</label>
        <input type="text" id="company_name" name="company_name" value="<?= h(get_setting('company_name')) ?>">

        <div class="form-row">
            <div>
                <label for="currency_symbol">Currency Symbol</label>
                <input type="text" id="currency_symbol" name="currency_symbol" value="<?= h(get_setting('currency_symbol')) ?>">
            </div>
            <div>
                <label for="default_management_share_percent">Default Management Share %</label>
                <input type="number" step="0.01" id="default_management_share_percent" name="default_management_share_percent" value="<?= h(get_setting('default_management_share_percent')) ?>">
            </div>
        </div>

        <p class="small muted">This default is only used when creating a new period; each period can override its own management share %.</p>

        <div class="form-row">
            <div>
                <label for="overcost_rate">Overcost Rate</label>
                <input type="number" step="any" id="overcost_rate" name="overcost_rate" value="<?= h(get_setting('overcost_rate', 0.11112393)) ?>" placeholder="0.11112393">
            </div>
        </div>
        <p class="small muted">Overcost per employee = Employee Gross &times; this Rate. Applied automatically to every period. Default: 0.11112393 (= 0.33336833 &times; 0.3333368).</p>

        <label for="approved_by_name">Approved By (default name on slips)</label>
        <input type="text" id="approved_by_name" name="approved_by_name" value="<?= h(get_setting('approved_by_name')) ?>" placeholder="e.g. Juan Dela Cruz">
        <p class="small muted">This name is printed under the "Approved By" signature line on every staff slip. Change it here any time — it updates on all slips immediately, no need to edit each one.</p>

        <div class="actions" style="margin-top:16px;">
            <button type="submit" class="btn">Save Settings</button>
        </div>
    </form>
</div>

<div class="card">
    <h2>Owner Accounts</h2>
    <p class="subtitle small">Owners sign in separately from admins and can only see the Dashboard and Owner Earnings.</p>
    <?php if ($ownerReveal): ?>
        <div class="alert alert-success">
            <strong><?= h($ownerReveal['label']) ?></strong> &mdash; shown once, copy it now.<br>
            Username: <code><?= h($ownerReveal['username']) ?></code> &nbsp; Password: <code><?= h($ownerReveal['password']) ?></code>
        </div>
    <?php endif; ?>
    <table>
        <thead><tr><th>Username</th><th>Name</th><th>Sees earnings of</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php $owners = $pdo->query("SELECT u.*, s.full_name AS staff_name FROM users u LEFT JOIN staff s ON s.id = u.staff_id WHERE u.role = 'owner' ORDER BY u.username")->fetchAll();
        foreach ($owners as $o): ?>
            <tr>
                <td><?= h($o['username']) ?></td>
                <td><?= h($o['full_name']) ?></td>
                <td class="small"><?= $o['staff_name'] ? h($o['staff_name']) : '<span class="muted">everyone</span>' ?></td>
                <td><?= $o['is_active'] ? 'Yes' : 'No' ?></td>
                <td>
                    <form method="post" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="reset_owner"><input type="hidden" name="user_id" value="<?= (int)$o['id'] ?>"><button type="submit" class="btn btn-sm" onclick="return confirm('Generate a new password for this owner?');">Reset Password</button></form>
                    <form method="post" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_owner"><input type="hidden" name="user_id" value="<?= (int)$o['id'] ?>"><button type="submit" class="btn btn-sm btn-secondary"><?= $o['is_active'] ? 'Disable' : 'Enable' ?></button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$owners): ?><tr><td colspan="5" class="muted">No owner accounts yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <form method="post" action="" style="margin-top:16px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_owner">
        <div class="form-row">
            <div><label for="owner_full_name">Owner Name</label><input type="text" id="owner_full_name" name="owner_full_name" required></div>
            <div><label for="owner_username">Username</label><input type="text" id="owner_username" name="owner_username" required></div>
            <div><label for="owner_password">Password (blank = auto-generate)</label><input type="text" id="owner_password" name="owner_password" autocomplete="off"></div>
        </div>
        <label for="owner_staff_id">Owner's staff record (they see only their own earnings)</label>
        <select id="owner_staff_id" name="owner_staff_id">
            <option value="">— none: sees everyone's earnings —</option>
            <?php foreach ($pdo->query("SELECT id, full_name FROM staff WHERE is_hidden = 1 ORDER BY full_name") as $hs): ?>
                <option value="<?= (int)$hs['id'] ?>"><?= h($hs['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">+ Add Owner Account</button>
    </form>
</div>

<div class="card">
    <h2>All Login Accounts</h2>
    <p class="subtitle small">Branch accounts are managed from the <a href="<?= BASE_URL ?>/branches/list.php">Branches</a> page; staff accounts from <a href="<?= BASE_URL ?>/staff/list.php">Staff</a>.</p>
    <table>
        <thead><tr><th>Username</th><th>Full Name</th><th>Role</th><th>Branch</th><th>Active</th></tr></thead>
        <tbody>
        <?php
        $accountRows = $pdo->query("SELECT u.*, b.name AS branch_name FROM users u LEFT JOIN branches b ON b.id = u.branch_id ORDER BY u.role, u.username");
        foreach ($accountRows as $u): ?>
            <tr>
                <td><?= h($u['username']) ?></td>
                <td><?= h($u['full_name']) ?></td>
                <td><?= h($u['role']) ?></td>
                <td class="small"><?= $u['branch_name'] ? h($u['branch_name']) : '<span class="muted">' . (in_array($u['role'], ['cashier','owner'], true) ? 'all branches' : '—') . '</span>' ?></td>
                <td><?= $u['is_active'] ? 'Yes' : 'No' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>