<?php
/**
 * Full database backup: streams every table (structure + data) as one .sql file.
 * Admin only, POST + CSRF. Pure PHP/PDO, so it works on hosts without mysqldump.
 * Restore: phpMyAdmin -> Import -> choose the downloaded file.
 */
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/auth.php';
require __DIR__ . '/includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/settings.php');
}
csrf_verify();

/** Writes the whole dump through $out(string). */
function dump_database(PDO $pdo, callable $out, string $dbName): void {
    $q = fn($id) => '`' . str_replace('`', '``', $id) . '`';
    $val = function ($v) use ($pdo) {
        if ($v === null) return 'NULL';
        if (is_int($v) || is_float($v)) return (string)$v;
        $v = (string)$v;
        if ($v !== '' && !preg_match('//u', $v)) return '0x' . bin2hex($v);   // binary data
        return $pdo->quote($v);
    };

    $out("-- Service Charge System: full database backup\n");
    $out('-- Database: ' . $dbName . "\n-- Created:  " . date('Y-m-d H:i:s') . "\n\n");
    $out("/*!40101 SET NAMES utf8mb4 */;\n/*!40014 SET FOREIGN_KEY_CHECKS=0 */;\n/*!40014 SET UNIQUE_CHECKS=0 */;\n/*!40101 SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n\n");

    $tables = [];
    $views = [];
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $r) {
        if (strtoupper($r[1]) === 'VIEW') $views[] = $r[0]; else $tables[] = $r[0];
    }

    foreach ($tables as $t) {
        $create = $pdo->query('SHOW CREATE TABLE ' . $q($t))->fetch(PDO::FETCH_NUM)[1];
        $out("-- ----------------------------\n-- Table: $t\n-- ----------------------------\n");
        $out('DROP TABLE IF EXISTS ' . $q($t) . ";\n" . $create . ";\n\n");

        $stmt = $pdo->query('SELECT * FROM ' . $q($t));
        $cols = null; $batch = []; $size = 0;
        $flush = function () use (&$batch, &$size, &$cols, $out, $q, $t) {
            if (!$batch) return;
            $out('INSERT INTO ' . $q($t) . ' (' . $cols . ") VALUES\n" . implode(",\n", $batch) . ";\n");
            $batch = []; $size = 0;
        };
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($cols === null) $cols = implode(', ', array_map($q, array_keys($row)));
            $line = '(' . implode(', ', array_map($val, array_values($row))) . ')';
            $batch[] = $line; $size += strlen($line);
            if (count($batch) >= 200 || $size > 400000) $flush();
        }
        $stmt->closeCursor();
        $flush();
        $out("\n");
    }

    foreach ($views as $v) {
        $create = $pdo->query('SHOW CREATE VIEW ' . $q($v))->fetch(PDO::FETCH_NUM)[1];
        $out('DROP VIEW IF EXISTS ' . $q($v) . ";\n" . $create . ";\n\n");
    }

    $out("/*!40014 SET UNIQUE_CHECKS=1 */;\n/*!40014 SET FOREIGN_KEY_CHECKS=1 */;\n-- End of backup\n");
}

@set_time_limit(0);
@ini_set('zlib.output_compression', '0');
while (ob_get_level()) ob_end_clean();

set_setting('last_backup_at', date('Y-m-d H:i:s'));

$filename = 'service_charge_backup_' . date('Y-m-d_His') . '.sql';
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

// Stream rows instead of loading whole tables into memory.
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
try {
    dump_database($pdo, function ($s) { echo $s; flush(); }, DB_NAME);
} catch (Throwable $e) {
    echo "\n-- BACKUP FAILED: " . str_replace("\n", ' ', $e->getMessage()) . "\n-- This file is incomplete. Do not use it.\n";
}
exit;