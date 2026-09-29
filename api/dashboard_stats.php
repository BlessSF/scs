<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in() || (!is_admin() && !is_cashier())) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$accessibleIds  = accessible_branch_ids(); // null = every branch; else own branch + its sub-branches
$inPlaceholders = $accessibleIds ? implode(',', array_fill(0, count($accessibleIds), '?')) : '';

$staffSql = "SELECT COUNT(DISTINCT s.id) FROM staff s LEFT JOIN staff_branches sb ON sb.staff_id = s.id
    WHERE s.is_active = 1" . ($accessibleIds ? " AND (s.branch_id IN ($inPlaceholders) OR sb.branch_id IN ($inPlaceholders))" : "");
$stmt = $pdo->prepare($staffSql);
$stmt->execute($accessibleIds ? array_merge($accessibleIds, $accessibleIds) : []);
$staffCount = (int)$stmt->fetchColumn();

$branchSql = "SELECT COUNT(*) FROM branches WHERE is_active = 1" . ($accessibleIds ? " AND id IN ($inPlaceholders)" : "");
$stmt = $pdo->prepare($branchSql);
$stmt->execute($accessibleIds ?: []);
$branchCount = (int)$stmt->fetchColumn();

$periodsSql = "SELECT COUNT(*) FROM periods WHERE status = 'open'" . ($accessibleIds ? " AND branch_id IN ($inPlaceholders)" : "");
$stmt = $pdo->prepare($periodsSql);
$stmt->execute($accessibleIds ?: []);
$openPeriods = (int)$stmt->fetchColumn();

$thisMonthStart = date('Y-m-01');
$thisMonthEnd   = date('Y-m-t');

$monthSql = "SELECT COALESCE(SUM(total_amount),0) FROM duty_days WHERE duty_date BETWEEN ? AND ?" . ($accessibleIds ? " AND branch_id IN ($inPlaceholders)" : "");
$stmt = $pdo->prepare($monthSql);
$stmt->execute($accessibleIds ? array_merge([$thisMonthStart, $thisMonthEnd], $accessibleIds) : [$thisMonthStart, $thisMonthEnd]);
$monthTotal = (float)$stmt->fetchColumn();

$allTimeSql = "SELECT COALESCE(SUM(total_amount),0) FROM duty_days" . ($accessibleIds ? " WHERE branch_id IN ($inPlaceholders)" : "");
$stmt = $pdo->prepare($allTimeSql);
$stmt->execute($accessibleIds ?: []);
$allTimeTotal = (float)$stmt->fetchColumn();

$today = date('Y-m-d');
$todaySql = "SELECT COALESCE(SUM(total_amount),0) FROM duty_days WHERE duty_date = ?" . ($accessibleIds ? " AND branch_id IN ($inPlaceholders)" : "");
$stmt = $pdo->prepare($todaySql);
$stmt->execute($accessibleIds ? array_merge([$today], $accessibleIds) : [$today]);
$todayTotal = (float)$stmt->fetchColumn();

$branchStatsSql = "SELECT b.id, b.name,
        (SELECT COUNT(DISTINCT s.id) FROM staff s LEFT JOIN staff_branches sb ON sb.staff_id = s.id
            WHERE s.is_active = 1 AND (s.branch_id = b.id OR sb.branch_id = b.id)) AS staff_count,
        (SELECT COALESCE(SUM(dd.total_amount),0) FROM duty_days dd WHERE dd.branch_id = b.id AND dd.duty_date = ?) AS today_total
    FROM branches b WHERE b.is_active = 1" . ($accessibleIds ? " AND b.id IN ($inPlaceholders)" : "") . " ORDER BY b.name";
$stmt = $pdo->prepare($branchStatsSql);
$stmt->execute($accessibleIds ? array_merge([$today], $accessibleIds) : [$today]);
$branchStats = $stmt->fetchAll();

echo json_encode([
    'staff_count'   => $staffCount,
    'branch_count'  => $branchCount,
    'open_periods'  => $openPeriods,
    'month_total'   => money($monthTotal),
    'today_total'   => money($todayTotal),
    'all_time_total' => money($allTimeTotal),
    'branches'      => array_map(function ($b) {
        return [
            'id'              => (int)$b['id'],
            'name'            => $b['name'],
            'staff_count'     => (int)$b['staff_count'],
            'today_total'     => money($b['today_total']),
            'today_total_raw' => (float)$b['today_total'],
        ];
    }, $branchStats),
    'updated_at' => date('g:i:s A'),
]);