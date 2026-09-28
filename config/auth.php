<?php
/**
 * Authentication / authorization helpers.
 * Include AFTER config.php.
 */

function current_user() {
    return $_SESSION['user'] ?? null;
}

function is_logged_in() {
    return isset($_SESSION['user']);
}

function is_admin() {
    return is_logged_in() && $_SESSION['user']['role'] === 'admin';
}

function is_cashier() {
    return is_logged_in() && $_SESSION['user']['role'] === 'cashier';
}

/**
 * The branch id this account is locked to, or null if it can see every
 * branch (admins, and any cashier account that was never assigned one).
 */
function current_branch_id() {
    if (!is_logged_in()) return null;
    $b = $_SESSION['user']['branch_id'] ?? null;
    return $b ? (int)$b : null;
}

/** True if the current account is restricted to a single branch. */
function is_branch_locked() {
    return current_branch_id() !== null;
}

/**
 * Branch ids the current login can access: its own branch plus any of that
 * branch's sub-branches (so an "H" login can also reach "H Bar" without
 * needing a separate account). Returns null if the account isn't locked to
 * a branch at all (admins, and any unrestricted cashier account).
 */
function accessible_branch_ids() {
    $locked = current_branch_id();
    if ($locked === null) return null;
    global $pdo;
    $ids = [$locked];
    $stmt = $pdo->prepare('SELECT id FROM branches WHERE parent_branch_id = ?');
    $stmt->execute([$locked]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $subId) {
        $ids[] = (int)$subId;
    }
    return $ids;
}

/**
 * Block access if the current account is locked to a branch and $branchId
 * isn't one it can reach (its own branch or one of its sub-branches).
 * Admins and unrestricted cashier accounts pass through untouched. Call
 * this after you've resolved which branch a record (staff member, duty
 * day, period, ...) belongs to.
 */
function require_branch_access($branchId) {
    require_login();
    $locked = current_branch_id();
    if ($locked !== null && !in_array((int)$branchId, accessible_branch_ids(), true)) {
        http_response_code(403);
        die('Access denied. This account can only access its own branch.');
    }
}

/**
 * Resolve which branch id a page/query should actually use.
 * - Branch-locked accounts get whatever branch they asked for, as long as
 *   it's their own branch or one of its sub-branches (e.g. "H" logging in
 *   can switch to "H Bar"); anything else falls back to their own branch —
 *   a query string can't be used to peek at an unrelated branch.
 * - Everyone else gets whatever was requested (0 = "all branches" where
 *   that's a valid choice, e.g. list filters).
 */
function scoped_branch_id($requestedId = 0) {
    $locked = current_branch_id();
    if ($locked === null) return (int)$requestedId;
    $requested = (int)$requestedId;
    if ($requested && in_array($requested, accessible_branch_ids(), true)) {
        return $requested;
    }
    return $locked;
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function require_admin() {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        die('Access denied. This page is for administrators only.');
    }
}

/**
 * Require the current user to have one of the given roles.
 * Usage: require_role(['admin', 'cashier']);
 */
function require_role(array $roles) {
    require_login();
    if (!in_array($_SESSION['user']['role'], $roles, true)) {
        http_response_code(403);
        die('Access denied. You do not have permission to view this page.');
    }
}

/**
 * Usernames allowed to see the "Shared" page (owners / other admins).
 * Only these accounts get the Shared link in the top bar and can open
 * shared/list.php -- every other account (including any future admin)
 * is blocked. Add or remove usernames here.
 */
function shared_allowed_users() {
    return ['admin', 'admin2'];
}

function can_view_shared() {
    return is_admin()
        && in_array($_SESSION['user']['username'] ?? '', shared_allowed_users(), true);
}

function require_shared_access() {
    require_login();
    if (!can_view_shared()) {
        http_response_code(403);
        die('Access denied. You do not have permission to view this page.');
    }
}

/** Simple CSRF token helpers */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify() {
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        die('Invalid or expired form submission (CSRF check failed). Go back and try again.');
    }
}
