<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_admin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$branch = ['name' => '', 'address' => '', 'is_active' => 1, 'parent_branch_id' => null];
$existingAccount = null;
$account = ['username' => ''];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM branches WHERE id = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) { flash('error', 'Branch not found.'); redirect('/branches/list.php'); }
    $branch = $found;

    $existingAccount = get_branch_account($id);
    if ($existingAccount) $account['username'] = $existingAccount['username'];
}

// A sub-branch is created via "+ Sub-Branch" on an existing branch's row
// (branches/form.php?parent_id=X) and works exactly like any other branch —
// it just has parent_branch_id set, so it's grouped under its parent
// wherever branches are listed, and reachable through its parent's login.
// Only one level of nesting is allowed: you can't make a sub-branch of a
// sub-branch. When editing an existing branch, the parent can be changed
// (including cleared entirely) to fully separate a sub-branch from its
// parent — it becomes a normal top-level branch with its own login.
$parentId = $id ? (int)($branch['parent_branch_id'] ?? 0) : (int)($_GET['parent_id'] ?? $_POST['parent_id'] ?? 0);
$parentBranch = null;
if ($parentId) {
    $parentBranch = get_branch($parentId);
    if (!$parentBranch) {
        flash('error', 'That parent branch no longer exists.');
        redirect('/branches/list.php');
    }
    if (!$id && !empty($parentBranch['parent_branch_id'])) {
        flash('error', '"' . $parentBranch['name'] . '" is already a sub-branch — you can\'t add a sub-branch under it.');
        redirect('/branches/list.php');
    }
}

$isSubBranch = (bool)$parentBranch;

// Whether this branch currently has sub-branches of its own — if so, it
// can't be turned into a sub-branch itself (only one level of nesting).
$hasOwnSubBranches = $id ? (bool)get_sub_branches($id, false) : false;

// For the "Parent Branch" dropdown when editing: every other top-level
// branch this one could be nested under.
$parentOptions = [];
if ($id) {
    foreach (get_top_level_branches(false) as $tb) {
        if ((int)$tb['id'] !== (int)$id) $parentOptions[] = $tb;
    }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $branch['name'] = trim($_POST['name'] ?? '');
    $branch['address'] = trim($_POST['address'] ?? '');
    $branch['is_active'] = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

    // Only editable from the dropdown when editing an existing branch;
    // new sub-branches keep the parent they were created under via the URL.
    if ($id) {
        $requestedParentId = (int)($_POST['parent_branch_id'] ?? 0);
        if ($requestedParentId === (int)$id) {
            $errors[] = 'A branch can\'t be its own parent.';
        } elseif ($requestedParentId && $hasOwnSubBranches) {
            $errors[] = 'This branch already has its own sub-branches, so it can\'t become a sub-branch itself. Remove or reassign its sub-branches first.';
        } elseif ($requestedParentId) {
            $reqParent = get_branch($requestedParentId);
            if (!$reqParent || !empty($reqParent['parent_branch_id'])) {
                $errors[] = 'Please choose a top-level branch to nest under.';
            } else {
                $parentId = $requestedParentId;
                $parentBranch = $reqParent;
                $isSubBranch = true;
            }
        } else {
            $parentId = 0;
            $parentBranch = null;
            $isSubBranch = false;
        }
    }

    $newUsername = trim($_POST['username'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';

    if ($branch['name'] === '') $errors[] = 'Branch name is required.';

    if ($newUsername !== '') {
        $dupCheck = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $dupCheck->execute([$newUsername, $existingAccount['id'] ?? 0]);
        if ($dupCheck->fetch()) $errors[] = 'That username is already taken by another account.';
    }

    if (!$errors) {
        if ($id) {
            $stmt = $pdo->prepare('UPDATE branches SET name=?, address=?, is_active=?, parent_branch_id=? WHERE id=?');
            $stmt->execute([$branch['name'], $branch['address'], $branch['is_active'], $parentId ?: null, $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO branches (name, address, is_active, parent_branch_id) VALUES (?,?,?,?)');
            $stmt->execute([$branch['name'], $branch['address'], $branch['is_active'], $parentId ?: null]);
            $id = (int)$pdo->lastInsertId();
        }

        // Every regular (top-level) branch needs its own login account, so
        // its cashier can only ever see this branch's data. Sub-branches
        // don't get one of their own by default — they're reached through
        // their parent branch's login (accessible_branch_ids() lets that
        // login in) — unless one already exists, in which case we keep it
        // in sync, or this branch was just separated from its parent (was a
        // sub-branch, is now top-level), in which case it needs one now.
        if ($existingAccount) {
            $finalUsername = $newUsername !== '' ? $newUsername : $existingAccount['username'];
            if ($newPassword !== '') {
                $stmt = $pdo->prepare('UPDATE users SET username=?, password=?, full_name=?, is_active=1 WHERE id=?');
                $stmt->execute([$finalUsername, password_hash($newPassword, PASSWORD_DEFAULT), $branch['name'] . ' Account', $existingAccount['id']]);
                $_SESSION['password_reveal'] = [
                    'staff_name' => $branch['name'] . ' (branch account)',
                    'username'   => $finalUsername,
                    'password'   => $newPassword,
                ];
            } else {
                $stmt = $pdo->prepare('UPDATE users SET username=?, full_name=? WHERE id=?');
                $stmt->execute([$finalUsername, $branch['name'] . ' Account', $existingAccount['id']]);
            }
        } elseif (!$isSubBranch) {
            $finalUsername = $newUsername !== '' ? $newUsername : generate_branch_username($branch['name']);
            $finalPassword = $newPassword !== '' ? $newPassword : substr(bin2hex(random_bytes(5)), 0, 10);
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password, role, branch_id, staff_id, full_name, is_active)
                 VALUES (?, ?, ?, ?, NULL, ?, 1)'
            );
            $stmt->execute([$finalUsername, password_hash($finalPassword, PASSWORD_DEFAULT), 'cashier', $id, $branch['name'] . ' Account']);
            $_SESSION['password_reveal'] = [
                'staff_name' => $branch['name'] . ' (branch account)',
                'username'   => $finalUsername,
                'password'   => $finalPassword,
            ];
        }

        flash('success', $isSubBranch ? 'Sub-branch saved.' : 'Branch saved.');
        redirect('/branches/list.php');
    }
}

$pageTitle = $id
    ? ($isSubBranch ? 'Edit Sub-Branch' : 'Edit Branch')
    : ($isSubBranch ? 'Add Sub-Branch' : 'Add Branch');
require __DIR__ . '/../includes/header.php';
?>
<h1><?= h($pageTitle) ?><?= $isSubBranch && !$id ? ' to ' . h($parentBranch['name']) : '' ?></h1>
<p class="subtitle"><?= $isSubBranch
    ? 'This sub-branch shares its parent branch\'s login — it doesn\'t need one of its own.'
    : 'Every branch has its own login account, so staff signed in with it can only ever see this branch\'s staff, duty entries, and periods.' ?></p>

<?php if ($isSubBranch): ?>
<div class="alert alert-info">
    This is a sub-branch of <strong><?= h($parentBranch['name']) ?></strong>. It works just like a regular branch —
    its own staff, duty calendar, and periods — kept separate for things like a bar's sales. It does not get its
    own login: sign in with <strong><?= h($parentBranch['name']) ?></strong>'s account and switch to
    <strong><?= h($branch['name'] ?: 'this sub-branch') ?></strong> from the branch picker on the dashboard.
    <?php if ($id): ?>Want it fully independent instead? Change "Parent Branch" to "None" below and save.<?php endif; ?>
</div>
<?php endif; ?>

<?php foreach ($errors as $e): ?><div class="alert alert-error"><?= h($e) ?></div><?php endforeach; ?>

<div class="card">
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <?php if (!$id && $parentId): ?><input type="hidden" name="parent_id" value="<?= (int)$parentId ?>"><?php endif; ?>

        <label for="name">Branch Name</label>
        <input type="text" id="name" name="name" value="<?= h($branch['name']) ?>" required>

        <label for="address">Address (optional)</label>
        <input type="text" id="address" name="address" value="<?= h($branch['address']) ?>">

        <?php if ($id): ?>
        <label for="parent_branch_id">Parent Branch</label>
        <select id="parent_branch_id" name="parent_branch_id" <?= $hasOwnSubBranches ? 'disabled' : '' ?>>
            <option value="0" <?= !$parentId ? 'selected' : '' ?>>None — top-level branch</option>
            <?php foreach ($parentOptions as $po): ?>
                <option value="<?= (int)$po['id'] ?>" <?= $parentId === (int)$po['id'] ? 'selected' : '' ?>><?= h($po['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($hasOwnSubBranches): ?>
            <input type="hidden" name="parent_branch_id" value="0">
            <p class="subtitle small" style="margin-top:-2px;">This branch has its own sub-branches, so it must stay top-level.</p>
        <?php else: ?>
            <p class="subtitle small" style="margin-top:-2px;">Set to "None" to fully separate this from any parent — it becomes an independent branch with its own login.</p>
        <?php endif; ?>
        <?php endif; ?>

        <label for="is_active">Status</label>
        <select id="is_active" name="is_active">
            <option value="1" <?= $branch['is_active'] ? 'selected' : '' ?>>Active</option>
            <option value="0" <?= !$branch['is_active'] ? 'selected' : '' ?>>Inactive</option>
        </select>

        <?php if (!$isSubBranch || $existingAccount): ?>
        <h2 style="margin-top:26px;">Branch Login Account</h2>
        <p class="subtitle small">
            <?= $existingAccount
                ? 'This is the account this branch signs in with. Change the username to rename the login, or set a new password below.'
                : 'Leave blank to auto-generate a username and password from the branch name — you can copy them from the confirmation screen after saving.' ?>
        </p>
        <div class="form-row">
            <div>
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?= h($account['username']) ?>" placeholder="<?= $existingAccount ? '' : 'auto-generated if left blank' ?>">
            </div>
            <div>
                <label for="new_password">Password <?= $existingAccount ? '(leave blank to keep current)' : '' ?></label>
                <div class="pw-field">
                    <input type="password" id="new_password" name="new_password" placeholder="<?= $existingAccount ? 'unchanged' : 'auto-generated if blank' ?>">
                    <button type="button" class="btn-eye" id="pwToggle" aria-label="Show password">👁</button>
                </div>
            </div>
        </div>
        <script>
        (function () {
            var input = document.getElementById('new_password');
            var btn = document.getElementById('pwToggle');
            if (!input || !btn) return;
            btn.addEventListener('click', function () {
                var showing = input.type === 'text';
                input.type = showing ? 'password' : 'text';
                btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
                btn.classList.toggle('is-active', !showing);
            });
        })();
        </script>
        <?php endif; ?>

        <div class="actions" style="margin-top:22px;">
            <button type="submit" class="btn">Save</button>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/branches/list.php">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
