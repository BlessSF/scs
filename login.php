<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/auth.php';
require __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    redirect('/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
        $stmt->execute([$username]);
        $u = $stmt->fetch();

        if ($u && password_verify($password, $u['password'])) {
            $_SESSION['user'] = [
                'id'        => $u['id'],
                'username'  => $u['username'],
                'role'      => $u['role'],
                'branch_id' => $u['branch_id'] ?? null,
                'staff_id'  => $u['staff_id'],
                'full_name' => $u['full_name'],
            ];
            redirect('/dashboard.php');
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$pageTitle = 'Login';
require __DIR__ . '/includes/header.php';
?>
<div class="login-wrap">
    <aside class="login-hero">
        <span class="lh-orb lh-orb-1"></span>
        <span class="lh-orb lh-orb-2"></span>
        <span class="lh-orb lh-orb-3"></span>

        <div class="lh-badge">✨ Welcome back</div>
        <h2><?= h(get_setting('company_name', 'Service Charge')) ?></h2>
        <p class="lh-tag">Fair, transparent service charge &mdash; for every branch and every team member.</p>

        <ul class="lh-features">
            <li><span class="lh-ico">🗓️</span><div><b>Daily duty entry</b><small>Log totals and attendance in seconds</small></div></li>
            <li><span class="lh-ico">📊</span><div><b>Live branch stats</b><small>See every branch update in real time</small></div></li>
            <li><span class="lh-ico">🧾</span><div><b>Instant payslips</b><small>Clear payouts, ready to print</small></div></li>
        </ul>

        <div class="lh-dots" aria-hidden="true">
            <span style="background:#ff8a1e"></span><span style="background:#1fa24d"></span>
            <span style="background:#d62b2b"></span><span style="background:#f4b74a"></span>
        </div>
    </aside>

    <section class="login-form-side">
        <h1>Sign in</h1>
        <p class="subtitle">Service Charge Management System</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <?= csrf_field() ?>
            <label for="username">Username</label>
            <div class="lf-field">
                <span class="lf-icon">👤</span>
                <input type="text" id="username" name="username" placeholder="Enter your username" autocomplete="username" autofocus required>
            </div>

            <label for="password">Password</label>
            <div class="lf-field">
                <span class="lf-icon">🔒</span>
                <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                <button type="button" class="lf-eye" id="togglePw" aria-label="Show password">Show</button>
            </div>

            <button type="submit" class="btn lf-submit">Sign In &rarr;</button>
        </form>
        <p class="lf-foot">Trouble signing in? Ask your administrator.</p>
    </section>
</div>
<script>
(function () {
    var b = document.getElementById('togglePw'), i = document.getElementById('password');
    if (!b || !i) return;
    b.addEventListener('click', function () {
        var show = i.type === 'password';
        i.type = show ? 'text' : 'password';
        b.textContent = show ? 'Hide' : 'Show';
        b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>