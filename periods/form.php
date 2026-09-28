<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_admin();

$branches = visible_branches(false);
if (!$branches) {
    flash('error', 'Create a branch first before creating a period.');
    redirect('/branches/form.php');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$period = [
    'branch_id' => scoped_branch_id($_GET['branch_id'] ?? $branches[0]['id']),
    'name' => '',
    'start_date' => date('Y-m-01'),
    'end_date' => date('Y-m-t'),
    'management_share_percent' => get_setting('default_management_share_percent', 20),
    'status' => 'open',
];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM periods WHERE id = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) { flash('error', 'Period not found.'); redirect('/periods/list.php'); }
    require_branch_access($found['branch_id']);
    $period = $found;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $period['branch_id'] = scoped_branch_id($_POST['branch_id'] ?? 0);
    $period['name'] = trim($_POST['name'] ?? '');
    $period['start_date'] = $_POST['start_date'] ?? '';
    $period['end_date'] = $_POST['end_date'] ?? '';
    $period['management_share_percent'] = (float)($_POST['management_share_percent'] ?? 0);
    $period['status'] = $_POST['status'] ?? 'open';

    if (!$period['branch_id']) $errors[] = 'Please select a branch.';
    if ($period['name'] === '') $errors[] = 'Name is required.';
    if (!$period['start_date'] || !$period['end_date']) $errors[] = 'Start and end dates are required.';
    if ($period['start_date'] && $period['end_date'] && $period['start_date'] > $period['end_date']) {
        $errors[] = 'Start date must be before end date.';
    }

    if (!$errors) {
        if ($id) {
            $stmt = $pdo->prepare('UPDATE periods SET branch_id=?, name=?, start_date=?, end_date=?, management_share_percent=?, status=? WHERE id=?');
            $stmt->execute([$period['branch_id'], $period['name'], $period['start_date'], $period['end_date'], $period['management_share_percent'], $period['status'], $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO periods (branch_id, name, start_date, end_date, management_share_percent, status) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$period['branch_id'], $period['name'], $period['start_date'], $period['end_date'], $period['management_share_percent'], $period['status']]);
            $id = (int)$pdo->lastInsertId();
        }
        flash('success', 'Period saved.');
        redirect('/periods/view.php?id=' . $id);
    }
}

$pageTitle = $id ? 'Edit Period' : 'New Period';
require __DIR__ . '/../includes/header.php';
?>
<h1><?= $id ? 'Edit Period' : 'New Period' ?></h1>

<?php foreach ($errors as $e): ?><div class="alert alert-error"><?= h($e) ?></div><?php endforeach; ?>

<div class="card">
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <label for="branch_id">Branch</label>
        <?php if (is_branch_locked()): ?>
            <input type="text" value="<?= h($branches[0]['name'] ?? '') ?>" disabled>
        <?php else: ?>
        <select id="branch_id" name="branch_id" required>
            <?php foreach ($branches as $b): ?>
                <option value="<?= $b['id'] ?>" <?= (int)$period['branch_id'] === (int)$b['id'] ? 'selected' : '' ?>><?= h(branch_option_label($b)) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <label for="name">Period Name</label>
        <input type="text" id="name" name="name" value="<?= h($period['name']) ?>" placeholder="e.g. APRIL 2026 or Q2 2026 (Apr-Jun)" required>

        <div class="form-row">
            <div>
                <label for="start_date">Start Date</label>
                <input type="date" id="start_date" name="start_date" value="<?= h($period['start_date']) ?>" required>
            </div>
            <div>
                <label for="end_date">End Date</label>
                <input type="date" id="end_date" name="end_date" value="<?= h($period['end_date']) ?>" required>
            </div>
        </div>

        <div class="form-row">
            <div>
                <label for="management_share_percent">Management Share %</label>
                <input type="number" step="0.01" min="0" max="100" id="management_share_percent" name="management_share_percent" value="<?= h($period['management_share_percent']) ?>">
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="open" <?= $period['status']==='open'?'selected':'' ?>>Open</option>
                    <option value="closed" <?= $period['status']==='closed'?'selected':'' ?>>Closed</option>
                </select>
            </div>
        </div>

        <div class="actions" style="margin-top:20px;">
            <button type="submit" class="btn">Save</button>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/periods/list.php">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>