<?php
require __DIR__ . '/config/config.php';
$_SESSION = [];
session_destroy();
header('Location: ' . BASE_URL . '/login.php');
exit;
