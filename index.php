<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/auth.php';

if (is_logged_in()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
} else {
    header('Location: ' . BASE_URL . '/login.php');
}
exit;
