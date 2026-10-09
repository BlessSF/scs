<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role(['admin', 'cashier']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$branches = visible_branches(false);
$defaultBranchId = scoped_branch_id($_GET['branch_id'] ?? ($branches[0]['id'] ?? 1));
$staff = ['branch_id' => $defaultBranchId, 'full_name' => '', 'status' => 'regular', 'remarks' => '', 'date_added' => date('Y-m-d'), 'is_active' => 1, 'is_hidden' => 0, 'excluded_days' => '', 'counted_months' => '', 'follow_staff_id' => null];
$account = ['username' => ''];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM staff WHERE id = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) { flash('error', 'Staff not found.'); redirect('/staff/list.php'); }
    require_branch_access($found['branch_id']);
    $staff = $found;

    $ustmt = $pdo->prepare('SELECT username FROM users WHERE staff_id = ?');
    $ustmt->execute([$id]);
    $u = $ustmt->fetch();
    if ($u) $account['username'] = $u['username'];
}

$extraBranchIds = $id ? get_staff_branch_ids($id) : [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $staff['branch_id']  = scoped_branch_id($_POST['branch_id'] ?? 0);
    $staff['full_name']  = trim($_POST['full_name'] ?? '');
    $staff['status']     = $_POST['status'] ?? 'regular';
    $staff['remarks']    = trim($_POST['remarks'] ?? '');
    $staff['date_added'] = $_POST['date_added'] ?: date('Y-m-d');
    $staff['is_active']  = isset($_POST['is_active']) ? 1 : 0;
    $staff['is_hidden']  = is_admin() ? ((int)($_POST['is_hidden'] ?? 0) === 1 ? 1 : 0) : ($staff['is_hidden'] ?? 0);
    if (is_admin()) {
        $rawDays = $_POST['excluded_days'] ?? [];
        $validDays = array_filter(array_map('intval', (array)$rawDays), function($d) { return $d >= 0 && $d <= 6; });
        $staff['excluded_days'] = implode(',', $validDays);

        // Optional: only count this owner in specific months of a given year.
        $cmYear = (int)($_POST['counted_year'] ?? date('Y'));
        if ($cmYear < 2000 || $cmYear > 2100) $cmYear = (int)date('Y');
        $cmList = [];
        foreach ((array)($_POST['counted_months'] ?? []) as $m) {
            $m = (int)$m;
            if ($m >= 1 && $m <= 12) $cmList[$m] = sprintf('%04d-%02d', $cmYear, $m);
        }
        ksort($cmList);
        $staff['counted_months'] = implode(',', $cmList);

        // Optional: only count this owner on the dates a chosen staff member worked.
        $fid = (int)($_POST['follow_staff_id'] ?? 0);
        $staff['follow_staff_id'] = ($fid > 0 && $fid !== (int)$id) ? $fid : null;
    }
    $extraBranchIds = array_map('intval', $_POST['extra_branch_ids'] ?? []);

    $newUsername = trim($_POST['username'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';

    if ($staff['full_name'] === '') {
        $errors[] = 'Full name is required.';
    }
    if (!$staff['branch_id']) {
        $errors[] = 'Please select a branch.';
    }

    if (!$errors) {
        if ($id) {
            $stmt = $pdo->prepare('UPDATE staff SET branch_id=?, full_name=?, status=?, remarks=?, date_added=?, is_active=?, is_hidden=?, excluded_days=?, counted_months=?, follow_staff_id=? WHERE id=?');
            $stmt->execute([$staff['branch_id'], $staff['full_name'], $staff['status'], $staff['remarks'], $staff['date_added'], $staff['is_active'], $staff['is_hidden'], $staff['excluded_days'], $staff['counted_months'], $staff['follow_staff_id'], $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO staff (branch_id, full_name, status, remarks, date_added, is_active, is_hidden, excluded_days, counted_months, follow_staff_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$staff['branch_id'], $staff['full_name'], $staff['status'], $staff['remarks'], $staff['date_added'], $staff['is_active'], $staff['is_hidden'], $staff['excluded_days'], $staff['counted_months'], $staff['follow_staff_id']]);
            $id = (int)$pdo->lastInsertId();
        }

        // A staff member can also be assigned to additional branches.
        // Admins can set any branch. Cashiers can only touch branches they
        // can see — so we must PRESERVE any existing assignments outside
        // their visible scope rather than wiping them.
        $allowedExtraIds = array_map(function ($b) { return (int)$b['id']; }, $branches);
        if (is_admin()) {
            // Admin sees all branches — just save what was submitted.
            set_staff_branches($id, array_intersect($extraBranchIds, $allowedExtraIds), $staff['branch_id']);
        } else {
            // Cashier: fetch all existing branch assignments for this staff member,
            // keep the ones outside the cashier's scope untouched, and merge in
            // whatever the cashier submitted for the branches they CAN see.
            $existingStmt = $pdo->prepare('SELECT branch_id FROM staff_branches WHERE staff_id = ?');
            $existingStmt->execute([$id]);
            $existingBranchIds = array_column($existingStmt->fetchAll(), 'branch_id');

            // Branches outside cashier's view — preserve as-is.
            $preserved = array_diff($existingBranchIds, $allowedExtraIds);
            // Branches inside cashier's view — use what they submitted.
            $submitted = array_intersect($extraBranchIds, $allowedExtraIds);
            // Merge both sets.
            $merged = array_values(array_unique(array_merge($preserved, $submitted)));
            set_staff_branches($id, $merged, $staff['branch_id']);
        }

        // Hidden + active staff are on every duty day of the branches they are
        // assigned to (minus excluded weekdays). Run AFTER the branch
        // assignments above are saved so the new assignments are honored.
        if ($staff['is_hidden'] && $staff['is_active']) {
            backfill_hidden_staff_duty_attendance($pdo, $id);
        }

        // Handle login account (optional)
        if ($newUsername !== '') {
            $existing = $pdo->prepare('SELECT * FROM users WHERE staff_id = ?');
            $existing->execute([$id]);
            $existingUser = $existing->fetch();

            $dupCheck = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
            $dupCheck->execute([$newUsername, $existingUser['id'] ?? 0]);
            if ($dupCheck->fetch()) {
                $errors[] = 'That username is already taken.';
            } else {
                if ($existingUser) {
                    if ($newPassword !== '') {
                        $stmt = $pdo->prepare('UPDATE users SET username=?, password=?, full_name=? WHERE id=?');
                        $stmt->execute([$newUsername, password_hash($newPassword, PASSWORD_DEFAULT), $staff['full_name'], $existingUser['id']]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE users SET username=?, full_name=? WHERE id=?');
                        $stmt->execute([$newUsername, $staff['full_name'], $existingUser['id']]);
                    }
                } else {
                    $pwd = $newPassword !== '' ? $newPassword : substr(bin2hex(random_bytes(4)), 0, 8);
                    $stmt = $pdo->prepare('INSERT INTO users (username, password, role, staff_id, full_name) VALUES (?,?,?,?,?)');
                    $stmt->execute([$newUsername, password_hash($pwd, PASSWORD_DEFAULT), 'staff', $id, $staff['full_name']]);
                    if ($newPassword === '') {
                        flash('info', "A login account was created. Temporary password: $pwd");
                    }
                }
            }
        }

        if (!$errors) {
            $__flashMsg = 'Staff record saved.';
            if ($staff['is_hidden'] && $staff['is_active']) {
                $__flashMsg .= ' Added to every existing duty day across every branch, so their share is already counted retroactively.';
            }
            flash('success', $__flashMsg);
            redirect('/staff/list.php');
        }
    }
}

$pageTitle = $id ? 'Edit Staff' : 'Add Staff';
require __DIR__ . '/../includes/header.php';
?>
<h1><?= $id ? 'Edit Staff' : 'Add Staff' ?></h1>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?= h($e) ?></div>
<?php endforeach; ?>

<div class="card">
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="form-row">
            <div>
                <label for="branch_id">Branch</label>
                <?php if (is_branch_locked()): ?>
                    <input type="text" value="<?= h($branches[0]['name'] ?? '') ?>" disabled>
                <?php else: ?>
                <select id="branch_id" name="branch_id" required>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= (int)$staff['branch_id'] === (int)$b['id'] ? 'selected' : '' ?>><?= h(branch_option_label($b)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <div>
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" value="<?= h($staff['full_name']) ?>" required>
            </div>
        </div>

        <?php if (count($branches) > 1): ?>
        <div style="margin-top:4px;">
            <label>Also assign to these branches</label>
            <p class="subtitle small" style="margin-top:-2px;">Check any other branch this staff member also works at (e.g. someone based at Hero who also covers shifts at H-Bar). They'll show up in that branch's staff list and duty entry too, without a duplicate record.</p>
            <div class="staff-select-toolbar">
                <span class="staff-select-count" id="extraBranchCount">0 selected</span>
                <button type="button" class="btn btn-sm btn-secondary" id="extraBranchSelectAll">Select all</button>
                <button type="button" class="btn btn-sm btn-secondary" id="extraBranchClearAll">Clear</button>
            </div>
            <div class="staff-select-list" id="extraBranchList">
                <?php foreach ($branches as $b): ?>
                    <label class="staff-row<?= in_array((int)$b['id'], $extraBranchIds, true) ? ' is-checked' : '' ?>">
                        <input type="checkbox" name="extra_branch_ids[]" value="<?= $b['id'] ?>" <?= in_array((int)$b['id'], $extraBranchIds, true) ? 'checked' : '' ?>>
                        <span class="sr-check" aria-hidden="true"></span>
                        <span class="sr-name"><?= h(branch_option_label($b)) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <script>
            (function () {
                var list = document.getElementById('extraBranchList');
                var count = document.getElementById('extraBranchCount');
                var selectAll = document.getElementById('extraBranchSelectAll');
                var clearAll = document.getElementById('extraBranchClearAll');
                if (!list || !count) return;
                var boxes = function () { return list.querySelectorAll('input[type="checkbox"]'); };
                function refreshCount() {
                    var n = list.querySelectorAll('input[type="checkbox"]:checked').length;
                    count.textContent = n + ' selected';
                }
                refreshCount();
                list.addEventListener('change', refreshCount);
                if (selectAll) selectAll.addEventListener('click', function () {
                    boxes().forEach(function (box) {
                        box.checked = true;
                        box.closest('.staff-row').classList.add('is-checked');
                    });
                    refreshCount();
                });
                if (clearAll) clearAll.addEventListener('click', function () {
                    boxes().forEach(function (box) {
                        box.checked = false;
                        box.closest('.staff-row').classList.remove('is-checked');
                    });
                    refreshCount();
                });
            })();
            </script>
        </div>
        <?php endif; ?>

        <div class="form-row" style="margin-top:18px;">
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php foreach (['regular' => 'Regular', 'not_regular' => 'Not Yet Regular', 'probationary' => 'Probationary', 'resigned' => 'Resigned'] as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $staff['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="date_added">Date Added</label>
                <input type="date" id="date_added" name="date_added" value="<?= h($staff['date_added']) ?>">
            </div>
        </div>

        <div class="form-row">
            <div>
                <label for="is_active">Active</label>
                <select name="is_active" id="is_active">
                    <option value="1" <?= $staff['is_active'] ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= !$staff['is_active'] ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <?php if (is_admin()): ?>
            <div>
                <label for="is_hidden">Hidden (Owner / Admin)</label>
                <select name="is_hidden" id="is_hidden">
                    <option value="0" <?= empty($staff['is_hidden']) ? 'selected' : '' ?>>No — visible in branch views</option>
                    <option value="1" <?= !empty($staff['is_hidden']) ? 'selected' : '' ?>>Yes — auto-added, hidden from branch views</option>
                </select>
            </div>
            <?php else: ?>
            <div></div>
            <?php endif; ?>
            <div></div>
        </div>

        <?php if (is_admin()): ?>
        <?php
            $excludedArr = array_filter(array_map('trim', explode(',', $staff['excluded_days'] ?? '')), 'strlen');
            $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        ?>
        <div id="excluded-days-wrap" style="<?= empty($staff['is_hidden']) ? 'display:none;' : '' ?>margin-top:14px;">
            <label style="margin-bottom:6px; display:block;">Exclude from Auto-Attendance <span style="font-weight:400; color:var(--muted);">(days this owner is NOT counted)</span></label>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <?php foreach ($dayNames as $num => $name): ?>
                <label style="display:flex; align-items:center; gap:5px; font-weight:400; cursor:pointer;">
                    <input type="checkbox" name="excluded_days[]" value="<?= $num ?>"
                        <?= in_array((string)$num, $excludedArr) ? 'checked' : '' ?>
                        style="width:16px; height:16px; cursor:pointer;">
                    <?= $name ?>
                </label>
                <?php endforeach; ?>
            </div>
            <p class="small" style="color:var(--muted); margin-top:6px;">Leave all unchecked to count this owner every day.</p>

            <?php
                $cmArr = array_filter(array_map('trim', explode(',', $staff['counted_months'] ?? '')), 'strlen');
                $cmYear = $cmArr ? (int)substr(reset($cmArr), 0, 4) : (int)date('Y');
                $cmSel = [];
                foreach ($cmArr as $ym) { if ((int)substr($ym, 0, 4) === $cmYear) $cmSel[(int)substr($ym, 5, 2)] = true; }
                $monthNames = [1=>'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            ?>
            <label style="margin:14px 0 6px; display:block;">Only Count in These Months <span style="font-weight:400; color:var(--muted);">(limit this owner to certain months)</span></label>
            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                <span style="font-weight:400;">Year</span>
                <input type="number" name="counted_year" value="<?= $cmYear ?>" min="2000" max="2100" style="width:90px;">
                <?php foreach ($monthNames as $num => $name): ?>
                <label style="display:flex; align-items:center; gap:5px; font-weight:400; cursor:pointer;">
                    <input type="checkbox" name="counted_months[]" value="<?= $num ?>"
                        <?= isset($cmSel[$num]) ? 'checked' : '' ?>
                        style="width:16px; height:16px; cursor:pointer;">
                    <?= $name ?>
                </label>
                <?php endforeach; ?>
            </div>
            <p class="small" style="color:var(--muted); margin-top:6px;">Leave all unchecked to count this owner in every month. Example: check Aug and Sep so a Jul&ndash;Sep period only counts her for those two months.</p>

            <?php
                $followCandidates = $pdo->query("SELECT s.id, s.full_name, b.name AS branch_name
                    FROM staff s JOIN branches b ON b.id = s.branch_id
                    WHERE s.is_hidden = 0 AND s.is_active = 1 ORDER BY b.name, s.full_name")->fetchAll();
            ?>
            <label for="follow_staff_id" style="margin:14px 0 6px; display:block;">Count Only on the Same Days As <span style="font-weight:400; color:var(--muted);">(optional)</span></label>
            <select name="follow_staff_id" id="follow_staff_id" style="max-width:420px;">
                <option value="">— Normal (count every day) —</option>
                <?php foreach ($followCandidates as $fc): ?>
                    <option value="<?= (int)$fc['id'] ?>" <?= (int)($staff['follow_staff_id'] ?? 0) === (int)$fc['id'] ? 'selected' : '' ?>>
                        <?= h($fc['full_name']) ?> (<?= h($fc['branch_name']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="small" style="color:var(--muted); margin-top:6px;">If chosen, this owner is counted only on the dates that staff member was on duty, so their days always match.</p>
        </div>
        <script>
        (function(){
            var sel = document.getElementById('is_hidden');
            var wrap = document.getElementById('excluded-days-wrap');
            if (!sel || !wrap) return;
            sel.addEventListener('change', function(){
                wrap.style.display = this.value === '1' ? '' : 'none';
            });
        })();
        </script>
        <?php endif; ?>

        <label for="remarks" style="margin-top:14px; display:block;">Remarks</label>
        <textarea id="remarks" name="remarks" rows="2"><?= h($staff['remarks']) ?></textarea>

        <h2 style="margin-top:26px;">Login Account (optional)</h2>
        <p class="subtitle small">Give this staff member a username &amp; password so they can log in and view their own attendance and slips.</p>
        <div class="form-row">
            <div>
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?= h($account['username']) ?>" placeholder="leave blank for no login access">
            </div>
            <div>
                <label for="new_password">Password <?= $account['username'] ? '(leave blank to keep current)' : '' ?></label>
                <div class="pw-field">
                    <input type="password" id="new_password" name="new_password" placeholder="<?= $account['username'] ? 'unchanged' : 'auto-generated if blank' ?>">
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
        <script>
        (function () {
            document.querySelectorAll('input[name="extra_branch_ids[]"]').forEach(function (box) {
                box.addEventListener('change', function () {
                    box.closest('.staff-row').classList.toggle('is-checked', box.checked);
                });
            });
        })();
        </script>

        <div class="actions" style="margin-top:22px;">
            <button type="submit" class="btn">Save</button>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/staff/list.php">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>