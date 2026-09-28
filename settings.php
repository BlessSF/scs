<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/auth.php';
require __DIR__ . '/includes/functions.php';
require_admin();

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
                <td class="small"><?= $u['branch_name'] ? h($u['branch_name']) : '<span class="muted">' . ($u['role'] === 'cashier' ? 'all branches' : '—') . '</span>' ?></td>
                <td><?= $u['is_active'] ? 'Yes' : 'No' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
