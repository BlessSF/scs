<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$shift = $_GET['shift'] ?? '';
$shift = in_array($shift, ['regular', 'bar_night'], true) ? $shift : null;
$summary = period_summary($pdo, $id, $shift);
if (!$summary) {
    flash('error', 'Period not found.');
    redirect('/periods/list.php');
}
$period = $summary['period'];
require_branch_access($period['branch_id']);
$branch = get_branch($period['branch_id']);
$rows = $summary['rows'];
$totals = $summary['totals'];

$filenameSafe = preg_replace('/[^A-Za-z0-9_-]+/', '_', ($branch['name'] ?? 'branch') . '_' . $period['name']);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filenameSafe . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
// UTF-8 BOM so Excel renders the currency symbol and special characters correctly
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [get_setting('company_name', APP_NAME)]);
fputcsv($out, [$branch['name'] ?? '', $period['name']]);
fputcsv($out, [
    date('M j, Y', strtotime($period['start_date'])) . ' - ' . date('M j, Y', strtotime($period['end_date'])),
    'Management share: ' . $period['management_share_percent'] . '%',
    'Status: ' . ucfirst($period['status']),
    $shift ? ('Filter: ' . ($shift === 'bar_night' ? 'Bar Night only' : 'Regular day only')) : 'Filter: All sales',
]);
fputcsv($out, []);

fputcsv($out, ['Name', 'Status', 'Days', 'Total SC', 'Mgmt Share', 'Gross', 'Damages', 'Cash Advance', 'Overcost', 'Net']);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['full_name'] . (!empty($r['visiting_from']) ? ' (visiting from ' . $r['visiting_from'] . ')' : ''),
        ucfirst(str_replace('_', ' ', $r['status'])),
        $r['days_worked'],
        number_format($r['total_sc'], 2, '.', ''),
        number_format($r['management_share'], 2, '.', ''),
        number_format($r['gross'], 2, '.', ''),
        number_format($r['damages_charges'], 2, '.', ''),
        number_format($r['cash_advance'], 2, '.', ''),
        number_format($r['overcost_cogs'], 2, '.', ''),
        number_format($r['net'], 2, '.', ''),
    ]);
}

fputcsv($out, []);
fputcsv($out, [
    'TOTAL', '', '',
    number_format($totals['total_sc'], 2, '.', ''),
    number_format($totals['management_share'], 2, '.', ''),
    number_format($totals['gross'], 2, '.', ''),
    number_format($totals['damages_charges'], 2, '.', ''),
    number_format($totals['cash_advance'], 2, '.', ''),
    number_format($totals['overcost_cogs'], 2, '.', ''),
    number_format($totals['net'], 2, '.', ''),
]);

fclose($out);
exit;