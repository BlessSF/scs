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
    <h1><?= h(get_setting('company_name', 'Service Charge')) ?></h1>
    <p class="subtitle">Service Charge Management System</p>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <?= csrf_field() ?>
        <label for="username">Username</label>
        <input type="text" id="username" name="username" autofocus required>

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>

        <button type="submit" class="btn" style="width:100%;margin-top:20px;">Sign In</button>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
