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
$company = get_setting('company_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($branch['name'] ?? '') ?> — <?= h($period['name']) ?> — <?= h($company) ?></title>
<style>
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        color: #1c231f;
        background: #eef1ef;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .sheet { max-width: 900px; margin: 24px auto; background: #fff; box-shadow: 0 8px 30px rgba(0,0,0,.12); }
    .report-header {
        background: linear-gradient(120deg, <?= $colors['dark'] ?> 0%, <?= $colors['solid'] ?> 100%);
        color: <?= $colors['text'] ?>;
        padding: 28px 34px;
    }
    .report-header .company { font-size: .8rem; text-transform: uppercase; letter-spacing: .08em; opacity: .85; margin-bottom: 6px; }
    .report-header h1 { margin: 0 0 4px; font-size: 1.5rem; }
    .report-header .meta { font-size: .85rem; opacity: .92; }
    .status-chip {
        display: inline-block; margin-left: 8px; padding: 2px 10px; border-radius: 20px;
        font-size: .72rem; font-weight: 700; background: rgba(255,255,255,.22);
    }

    .stat-row { display: flex; gap: 14px; padding: 24px 34px 6px; flex-wrap: wrap; }
    .stat-box { flex: 1; min-width: 140px; border: 1px solid #e2e6e3; border-radius: 10px; padding: 14px 16px; }
    .stat-box .v { font-size: 1.25rem; font-weight: 800; color: <?= $colors['dark'] ?>; }
    .stat-box .l { font-size: .68rem; text-transform: uppercase; letter-spacing: .05em; color: #667169; margin-top: 3px; }

    .body-pad { padding: 10px 34px 34px; }
    h2 { font-size: 1rem; margin: 20px 0 10px; color: <?= $colors['dark'] ?>; }
    table { width: 100%; border-collapse: collapse; font-size: .82rem; }
    th, td { padding: 8px 9px; border-bottom: 1px solid #eceeec; text-align: left; }
    th { background: <?= $colors['solid'] ?>; color: <?= $colors['text'] ?>; font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; }
    td.num, th.num { text-align: right; }
    tr.total-row td { font-weight: 800; border-top: 2px solid <?= $colors['solid'] ?>; background: #f7f8f7; }
    .badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: .68rem; font-weight: 700; background: #e2f6e9; color: #176b3c; }

    .print-toolbar {
        position: sticky; top: 0; z-index: 10;
        display: flex; justify-content: center; gap: 10px;
        padding: 12px; background: #1c231f;
    }
    .print-toolbar button, .print-toolbar a {
        font-family: inherit; font-size: .85rem; font-weight: 700;
        padding: 9px 18px; border-radius: 999px; border: none; cursor: pointer;
        text-decoration: none; display: inline-block;
    }
    .print-toolbar .go { background: <?= $colors['solid'] ?>; color: <?= $colors['text'] ?>; }
    .print-toolbar .back { background: #4a534d; color: #fff; }

    .report-footer { text-align: center; color: #8b948d; font-size: .74rem; padding: 6px 34px 26px; }

    @media print {
        .print-toolbar { display: none !important; }
        body { background: #fff; }
        .sheet { margin: 0; box-shadow: none; max-width: 100%; }
    }
</style>
</head>
<body>
    <div class="print-toolbar">
        <button class="go" onclick="window.print()">🖨️ Print / Save as PDF</button>
        <a class="back" href="<?= BASE_URL ?>/periods/view.php?id=<?= $period['id'] ?><?= $shift ? '&shift='.urlencode($shift) : '' ?>">← Back</a>
    </div>

    <div class="sheet">
        <div class="report-header">
            <div class="company"><?= h($company) ?></div>
            <h1><?= h($branch['name'] ?? '') ?> — <?= h($period['name']) ?></h1>
            <div class="meta">
                <?= h(date('M j, Y', strtotime($period['start_date']))) ?> &ndash; <?= h(date('M j, Y', strtotime($period['end_date']))) ?>
                &middot; Management share: <?= h($period['management_share_percent']) ?>%
                <span class="status-chip"><?= h(ucfirst($period['status'])) ?></span>
                <?php if ($shift): ?>
                <span class="status-chip"><?= h($shift === 'bar_night' ? '🌙 Bar Night only' : '☀️ Regular day only') ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="stat-row">
            <div class="stat-box"><div class="v"><?= money($totals['total_sc']) ?></div><div class="l">Total SC (before mgmt share)</div></div>
            <div class="stat-box"><div class="v"><?= money($totals['management_share']) ?></div><div class="l">Management Share</div></div>
            <div class="stat-box"><div class="v"><?= money($totals['gross']) ?></div><div class="l">Gross Service Charge</div></div>
            <div class="stat-box"><div class="v"><?= money($totals['net']) ?></div><div class="l">Total Net Contribution</div></div>
        </div>

        <div class="body-pad">
            <h2>Staff Breakdown</h2>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th class="num">Days</th>
                        <th class="num">Total SC</th>
                        <th class="num">Mgmt Share</th>
                        <th class="num">Gross</th>
                        <th class="num">Damages</th>
                        <th class="num">Cash Adv.</th>
                        <th class="num">Overcost</th>
                        <th class="num">Net</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h($r['full_name'] . (!empty($r['visiting_from']) ? ' (visiting from ' . $r['visiting_from'] . ')' : '')) ?></td>
                        <td><span class="badge"><?= h(ucfirst(str_replace('_', ' ', $r['status']))) ?></span></td>
                        <td class="num"><?= (int)$r['days_worked'] ?></td>
                        <td class="num"><?= money($r['total_sc']) ?></td>
                        <td class="num"><?= money($r['management_share']) ?></td>
                        <td class="num"><?= money($r['gross']) ?></td>
                        <td class="num"><?= money($r['damages_charges']) ?></td>
                        <td class="num"><?= money($r['cash_advance']) ?></td>
                        <td class="num"><?= money($r['overcost_cogs']) ?></td>
                        <td class="num"><strong><?= money($r['net']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="10">No duty records fall within this period's date range yet for this branch.</td></tr>
                <?php endif; ?>
                </tbody>
                <?php if ($rows): ?>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="3">TOTAL</td>
                        <td class="num"><?= money($totals['total_sc']) ?></td>
                        <td class="num"><?= money($totals['management_share']) ?></td>
                        <td class="num"><?= money($totals['gross']) ?></td>
                        <td class="num"><?= money($totals['damages_charges']) ?></td>
                        <td class="num"><?= money($totals['cash_advance']) ?></td>
                        <td class="num"><?= money($totals['overcost_cogs']) ?></td>
                        <td class="num"><?= money($totals['net']) ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <div class="report-footer">Generated <?= h(date('F j, Y g:i A')) ?> &middot; <?= h($company) ?></div>
    </div>
</body>
</html>