<?php
// Expects $pageTitle to be set by the including page.
$user = current_user();
$__here = $_SERVER['SCRIPT_NAME'] ?? '';
function nav_active($needle, $here) { return strpos($here, $needle) !== false ? ' class="active"' : ''; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0c4a3e">
<title><?= isset($pageTitle) ? h($pageTitle) . ' — ' : '' ?><?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: time() ?>">
</head>
<body>
<?php if ($user): ?>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="<?= BASE_URL ?>/dashboard.php"><?= h(get_setting('company_name', 'Service Charge')) ?></a>
        <button type="button" class="nav-toggle" id="navToggle" aria-label="Menu" aria-expanded="false" aria-controls="mainNav">
            <span></span><span></span><span></span>
        </button>
        <nav class="main-nav" id="mainNav">
            <?php if ($user['role'] === 'admin'): ?>
                <a<?= nav_active('dashboard.php', $__here) ?> href="<?= BASE_URL ?>/dashboard.php">Dashboard</a>
                <a<?= nav_active('/branches/', $__here) ?> href="<?= BASE_URL ?>/branches/list.php">Branches</a>
                <a<?= nav_active('/staff/', $__here) ?> href="<?= BASE_URL ?>/staff/list.php">Staff</a>
                <?php if (function_exists('can_view_shared') && can_view_shared()): ?>
                    <a<?= nav_active('/shared/', $__here) ?> href="<?= BASE_URL ?>/shared/list.php">Shared</a>
                <?php endif; ?>
                <a<?= nav_active('/owners/', $__here) ?> href="<?= BASE_URL ?>/owners/earnings.php">Owner Earnings</a>
                <a<?= nav_active('/duty/', $__here) ?> href="<?= BASE_URL ?>/duty/calendar.php">Duty Entry</a>
                <a<?= nav_active('/periods/', $__here) ?> href="<?= BASE_URL ?>/periods/list.php">Periods</a>
                <a<?= nav_active('settings.php', $__here) ?> href="<?= BASE_URL ?>/settings.php">Settings</a>
            <?php elseif ($user['role'] === 'cashier'): ?>
                <a<?= nav_active('dashboard.php', $__here) ?> href="<?= BASE_URL ?>/dashboard.php">Dashboard</a>
                <a<?= nav_active('/duty/', $__here) ?> href="<?= BASE_URL ?>/duty/calendar.php">Duty Entry</a>
                <a<?= nav_active('/staff/', $__here) ?> href="<?= BASE_URL ?>/staff/list.php">Staff</a>
            <?php else: ?>
                <a<?= nav_active('dashboard.php', $__here) ?> href="<?= BASE_URL ?>/dashboard.php">Dashboard</a>
                <a<?= nav_active('my-attendance.php', $__here) ?> href="<?= BASE_URL ?>/portal/my-attendance.php">My Attendance</a>
            <?php endif; ?>
            <?php $__lockedBranch = is_branch_locked() ? get_branch(current_branch_id()) : null; ?>
            <div class="user-menu user-menu-mobile">
                <span><?= h($user['full_name']) ?> (<?= h($user['role']) ?><?= $__lockedBranch ? ' · <a href="' . BASE_URL . '/branches/view.php?id=' . (int)$__lockedBranch['id'] . '">' . h($__lockedBranch['name']) . '</a>' : '' ?>)</span>
                <a href="<?= BASE_URL ?>/logout.php" class="btn-link">Logout</a>
            </div>
        </nav>
        <div class="user-menu user-menu-desktop">
            <span><?= h($user['full_name']) ?> (<?= h($user['role']) ?><?= $__lockedBranch ? ' · <a href="' . BASE_URL . '/branches/view.php?id=' . (int)$__lockedBranch['id'] . '">' . h($__lockedBranch['name']) . '</a>' : '' ?>)</span>
            <a href="<?= BASE_URL ?>/logout.php" class="btn-link">Logout</a>
        </div>
    </div>
</header>
<script>
(function () {
    var btn = document.getElementById('navToggle');
    var nav = document.getElementById('mainNav');
    if (!btn || !nav) return;
    btn.addEventListener('click', function () {
        var open = nav.classList.toggle('is-open');
        btn.classList.toggle('is-open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    nav.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', function () {
            nav.classList.remove('is-open');
            btn.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
        });
    });
})();
</script>
<?php endif; ?>
<main class="container">
<?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?= h($f['type']) ?>"><?= h($f['message']) ?></div>
<?php endforeach; ?>