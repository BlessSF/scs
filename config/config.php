<?php
/**
 * Core configuration
 * Update the DB credentials below to match your environment (default XAMPP values shown).
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'service_charge_db');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_NAME', 'Service Charge Management System');

// --- Auto-detect BASE_URL -------------------------------------------------
// Works whether you serve the parent folder (e.g. XAMPP htdocs -> /scs/...)
// or serve the "scs" folder itself directly (e.g. `php -S localhost:8000`
// run from inside scs, or VS Code's PHP Server extension) -> "" (root).
// No manual editing needed in either case.
$appRoot = realpath(__DIR__ . '/..');                       // filesystem path to the scs folder
$docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? $appRoot);  // filesystem path the web server treats as "/"

if ($docRoot && $appRoot && strpos($appRoot, $docRoot) === 0) {
    $base = substr($appRoot, strlen($docRoot));
} else {
    $base = '';
}
$base = str_replace('\\', '/', $base); // Windows path separators -> URL separators
define('BASE_URL', rtrim($base, '/'));
// ---------------------------------------------------------------------------

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()) .
        '<br>Check config/config.php and make sure you imported database/schema.sql');
}
