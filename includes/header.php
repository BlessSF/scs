<?php
// Expects $pageTitle to be set by the including page.
$user = current_user();
$__here = $_SERVER['SCRIPT_NAME'] ?? '';
function nav_active($needle, $here) { return strpos($here, $needle) !== false ? ' class="active"' : ''; }
function nav_item($needle, $href, $label, $icon, $here) {
    return '<a' . nav_active($needle, $here) . ' href="' . BASE_URL . $href . '">' . $label . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0c4a3e">
<title><?= isset($pageTitle) ? h($pageTitle) . ' — ' : '' ?><?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: time() ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/topbar.css?v=<?= @filemtime(__DIR__ . '/../assets/css/topbar.css') ?: time() ?>">
</head>
<body>
<?php if ($user): ?>
<header class="topbar<?= ($user['role'] ?? '') === 'admin' ? ' topbar-wide' : '' ?>">
    <div class="topbar-inner">
        <a class="brand" href="<?= BASE_URL ?>/dashboard.php"><?= h(get_setting('company_name', 'Service Charge')) ?></a>
        <button type="button" class="nav-toggle" id="navToggle" aria-label="Menu" aria-expanded="false" aria-controls="mainNav">
            <span></span><span></span><span></span>
        </button>
        <nav class="main-nav" id="mainNav">
            <?php if ($user['role'] === 'admin'): ?>
                <?= nav_item('dashboard.php', '/dashboard.php', 'Dashboard', 'dashboard', $__here) ?>
                <?= nav_item('/branches/', '/branches/list.php', 'Branches', 'branches', $__here) ?>
                <?= nav_item('/staff/', '/staff/list.php', 'Staff', 'staff', $__here) ?>
                <?php if (function_exists('can_view_shared') && can_view_shared()): ?>
                    <?= nav_item('/shared/', '/shared/list.php', 'Shared', 'shared', $__here) ?>
                <?php endif; ?>
                <?= nav_item('/owners/', '/owners/earnings.php', 'Owner Earnings', 'owners', $__here) ?>
                <?= nav_item('/duty/', '/duty/calendar.php', 'Duty Entry', 'duty', $__here) ?>
                <?= nav_item('/periods/', '/periods/list.php', 'Periods', 'periods', $__here) ?>
                <?= nav_item('/locks/', '/locks/index.php', 'Lock', 'lock', $__here) ?>
                <?php if (($user['username'] ?? '') === 'admin'): /* Claribel's account only */ ?>
                    <?= nav_item('/summary/', '/summary/daily.php', 'Daily Summary', 'summary', $__here) ?>
                <?php endif; ?>
                <?= nav_item('settings.php', '/settings.php', 'Settings', 'settings', $__here) ?>
            <?php elseif ($user['role'] === 'owner'): ?>
                <?= nav_item('dashboard.php', '/dashboard.php', 'Dashboard', 'dashboard', $__here) ?>
                <?= nav_item('/owners/', '/owners/earnings.php', 'Owner Earnings', 'owners', $__here) ?>
            <?php elseif ($user['role'] === 'cashier'): ?>
                <?= nav_item('dashboard.php', '/dashboard.php', 'Dashboard', 'dashboard', $__here) ?>
                <?= nav_item('/duty/', '/duty/calendar.php', 'Duty Entry', 'duty', $__here) ?>
                <?= nav_item('/staff/', '/staff/list.php', 'Staff', 'staff', $__here) ?>
            <?php else: ?>
                <?= nav_item('dashboard.php', '/dashboard.php', 'Dashboard', 'dashboard', $__here) ?>
                <?= nav_item('my-attendance.php', '/portal/my-attendance.php', 'My Attendance', 'attendance', $__here) ?>
            <?php endif; ?>
            <?php $__lockedBranch = is_branch_locked() ? get_branch(current_branch_id()) : null; ?>
            <div class="user-menu user-menu-mobile">
                <span><?= h($user['full_name']) ?> (<?= h($user['role']) ?><?= $__lockedBranch ? ' · <a href="' . BASE_URL . '/branches/view.php?id=' . (int)$__lockedBranch['id'] . '">' . h($__lockedBranch['name']) . '</a>' : '' ?>)</span>
                <a href="<?= BASE_URL ?>/logout.php" class="btn-link">Logout</a>
            </div>
        </nav>
        <div class="user-menu user-menu-desktop">
            <span class="um-info">
                <strong><?= h($user['full_name']) ?></strong>
                <small><?= h(ucfirst($user['role'])) ?><?= $__lockedBranch ? ' · <a href="' . BASE_URL . '/branches/view.php?id=' . (int)$__lockedBranch['id'] . '">' . h($__lockedBranch['name']) . '</a>' : '' ?></small>
            </span>
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