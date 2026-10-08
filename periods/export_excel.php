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

$themeKey = branch_theme_key($branch['name'] ?? '');
$colors = branch_theme_colors($themeKey);

$filenameSafe = preg_replace('/[^A-Za-z0-9_-]+/', '_', ($branch['name'] ?? 'branch') . '_' . $period['name']);

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filenameSafe . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

function xcell($val) { return h($val); }
?>
<html>
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]><xml>
<x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
<x:Name><?= xcell(substr($period['name'], 0, 31)) ?></x:Name>
<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook>
</xml><![endif]-->
<style>
    table { border-collapse: collapse; font-family: Calibri, Arial, sans-serif; font-size: 12px; }
    td, th { border: 1px solid #d0d0d0; padding: 6px 10px; }
    .co-title { background: <?= $colors['dark'] ?>; color: #ffffff; font-size: 16px; font-weight: bold; }
    .co-sub   { background: <?= $colors['solid'] ?>; color: <?= $colors['text'] ?>; font-weight: bold; }
    .co-head  { background: <?= $colors['solid'] ?>; color: <?= $colors['text'] ?>; font-weight: bold; }
    .co-total { background: #f0f0f0; font-weight: bold; }
    .num { text-align: right; }
</style>
</head>
<body>
<table>
    <tr><td class="co-title" colspan="10"><?= xcell(get_setting('company_name', APP_NAME)) ?></td></tr>
    <tr><td class="co-sub" colspan="10"><?= xcell(($branch['name'] ?? '') . ' — ' . $period['name']) ?></td></tr>
    <tr>
        <td colspan="10">
            <?= xcell(date('M j, Y', strtotime($period['start_date'])) . ' - ' . date('M j, Y', strtotime($period['end_date']))) ?>
            &nbsp;&middot;&nbsp; Management share: <?= xcell($period['management_share_percent']) ?>%
            &nbsp;&middot;&nbsp; Status: <?= xcell(ucfirst($period['status'])) ?>
            &nbsp;&middot;&nbsp; Filter: <?= xcell($shift ? ($shift === 'bar_night' ? 'Bar Night only' : 'Regular day only') : 'All sales') ?>
        </td>
    </tr>
    <tr><td colspan="10"></td></tr>
    <tr class="co-head">
        <th>Name</th>
        <th>Status</th>
        <th>Days</th>
        <th>Total SC</th>
        <th>Mgmt Share</th>
        <th>Gross</th>
        <th>Damages</th>
        <th>Cash Advance</th>
        <th>Overcost</th>
        <th>Net</th>
    </tr>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><?= xcell($r['full_name'] . (!empty($r['visiting_from']) ? ' (visiting from ' . $r['visiting_from'] . ')' : '')) ?></td>
        <td><?= xcell(ucfirst(str_replace('_', ' ', $r['status']))) ?></td>
        <td class="num"><?= (int)$r['days_worked'] ?></td>
        <td class="num"><?= number_format($r['total_sc'], 2, '.', '') ?></td>
        <td class="num"><?= number_format($r['management_share'], 2, '.', '') ?></td>
        <td class="num"><?= number_format($r['gross'], 2, '.', '') ?></td>
        <td class="num"><?= number_format($r['damages_charges'], 2, '.', '') ?></td>
        <td class="num"><?= number_format($r['cash_advance'], 2, '.', '') ?></td>
        <td class="num"><?= number_format($r['overcost_cogs'], 2, '.', '') ?></td>
        <td class="num"><?= number_format($r['net'], 2, '.', '') ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
    <tr><td colspan="10">No duty records fall within this period's date range yet for this branch.</td></tr>
    <?php endif; ?>
    <?php if ($rows): ?>
    <tr class="co-total">
        <td colspan="3">TOTAL</td>
        <td class="num"><?= number_format($totals['total_sc'], 2, '.', '') ?></td>
        <td class="num"><?= number_format($totals['management_share'], 2, '.', '') ?></td>
        <td class="num"><?= number_format($totals['gross'], 2, '.', '') ?></td>
        <td class="num"><?= number_format($totals['damages_charges'], 2, '.', '') ?></td>
        <td class="num"><?= number_format($totals['cash_advance'], 2, '.', '') ?></td>
        <td class="num"><?= number_format($totals['overcost_cogs'], 2, '.', '') ?></td>
        <td class="num"><?= number_format($totals['net'], 2, '.', '') ?></td>
    </tr>
    <?php endif; ?>
</table>
</body>
</html>