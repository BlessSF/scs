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
$themeKey = branch_theme_key($branch['name'] ?? '');
$colors = branch_theme_colors($themeKey);
$companyName = get_setting('company_name', '');
$approvedBy = get_setting('approved_by_name', '');

// 4 slips per sheet of bond paper (2 columns x 2 rows)
$sheets = array_chunk($rows, 4);
$sheetCount = count($sheets);

$pageTitle = 'All Slips — ' . $period['name'];
require __DIR__ . '/../includes/header.php';
?>
<style id="pageSizeStyle">@page { size: 8.5in 11in; margin: 0; }</style>
<style>
/* ---------- Print-preview toolbar ---------- */
.pp-toolbar {
    display: flex; flex-wrap: wrap; gap: 12px; align-items: center;
    background: #fff; border: 1px solid var(--border); border-radius: var(--radius);
    padding: 12px 16px; margin: 14px 0 18px; box-shadow: var(--shadow-sm, 0 1px 3px rgba(0,0,0,.06));
}
.pp-toolbar label { font-weight: 700; font-size: .85rem; margin-right: 4px; }
.pp-toolbar select { width: auto; min-width: 150px; }
.pp-toolbar .pp-info { margin-left: auto; font-size: .85rem; color: var(--text-muted); }

/* ---------- Preview area (grey desk with paper sheets) ---------- */
.pp-desk {
    background: #d9dde1; border-radius: var(--radius);
    padding: 28px 12px; overflow-x: auto;
}
.pp-zoom { width: max-content; margin: 0 auto; }
.pp-sheet-label {
    text-align: center; font-size: 12px; color: #555; margin: 0 0 6px; font-weight: 600;
}

/* One sheet = one piece of bond paper */
.sheet {
    --paper-w: 8.5in; --paper-h: 11in;
    width: var(--paper-w); height: var(--paper-h);
    background: #fff; box-shadow: 0 4px 18px rgba(0,0,0,.18);
    margin: 0 auto 28px; padding: .3in; box-sizing: border-box;
    display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr;
    position: relative; overflow: hidden;
}
/* dashed cut guides */
.sheet::before, .sheet::after { content: ""; position: absolute; pointer-events: none; }
.sheet::before { left: 50%; top: .15in; bottom: .15in; border-left: 1px dashed #bbb; }
.sheet::after  { top: 50%; left: .15in; right: .15in; border-top: 1px dashed #bbb; }

.sheet .ps {
    margin: .12in; padding: .16in .2in; box-sizing: border-box;
    border: 1px solid #cfd6dc; border-radius: 6px;
    display: flex; flex-direction: column; overflow: hidden;
    font-size: 9.5pt; color: #111; line-height: 1.25;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.sheet .ps-empty { border: none; }
.ps h2 { font-size: 11pt; text-align: center; margin: 0 0 8px; line-height: 1.2; }
.ps-row { display: flex; justify-content: space-between; gap: 8px; padding: 3px 0; border-bottom: 1px dashed #ccc; }
.ps-row > :last-child { text-align: right; white-space: nowrap; }
.ps-row.total { border-top: 1.5px solid #222; border-bottom: none; font-weight: 800; font-size: 10pt; margin-top: 3px; padding-top: 5px; }
.ps-sig { margin-top: auto; padding-top: 18px; display: flex; justify-content: space-between; font-size: 8.5pt; }
.ps-sig div { border-top: 1px solid #222; width: 46%; text-align: center; padding-top: 3px; }
.ps-sig-name { display: block; font-weight: 700; min-height: 1.1em; }

/* ---------- Printing ---------- */
@media print {
    .topbar, .no-print, .site-footer, .alert { display: none !important; }
    html, body { background: #fff !important; margin: 0 !important; padding: 0 !important; }
    main.container { max-width: none !important; width: auto !important; margin: 0 !important; padding: 0 !important; }
    .pp-desk { background: none !important; padding: 0 !important; border-radius: 0; overflow: visible; }
    .pp-zoom { zoom: 1 !important; width: auto; }
    .pp-sheet-label { display: none; }
    .sheet { box-shadow: none; margin: 0; page-break-after: always; break-after: page; }
    .sheet:last-child { page-break-after: auto; break-after: auto; }
}
</style>

<div class="no-print">
    <div class="actions">
        <button class="btn" onclick="window.print()">🖨 Print All Slips</button>
        <a class="btn btn-secondary" href="<?= BASE_URL ?>/periods/view.php?id=<?= $period['id'] ?><?= $shift ? '&shift='.urlencode($shift) : '' ?>">Back to Period</a>
    </div>

    <h1><?= h($branch['name'] ?? '') ?> — <?= h($period['name']) ?></h1>
    <p class="subtitle">
        <?= count($rows) ?> slip<?= count($rows) === 1 ? '' : 's' ?> on
        <?= $sheetCount ?> sheet<?= $sheetCount === 1 ? '' : 's' ?> of bond paper (4 slips per sheet).
        <?php if ($shift): ?> Filtered: <?= h($shift === 'bar_night' ? 'Bar Night only' : 'Regular day only') ?><?php endif; ?>
    </p>

    <div class="pp-toolbar">
        <div>
            <label for="ppPaper">Paper</label>
            <select id="ppPaper">
                <option value="short">Short bond (8.5 × 11 in)</option>
                <option value="long">Long bond (8.5 × 13 in)</option>
                <option value="a4">A4 (8.27 × 11.69 in)</option>
            </select>
        </div>
        <div>
            <label for="ppZoom">Preview size</label>
            <select id="ppZoom">
                <option value="fit">Fit to screen</option>
                <option value="0.5">50%</option>
                <option value="0.75">75%</option>
                <option value="1">100% (actual)</option>
            </select>
        </div>
        <span class="pp-info">Tip: in the print window set <strong>Margins: None</strong>, turn off <strong>Headers and footers</strong>, and set the same paper size.</span>
    </div>
</div>

<?php if (!$rows): ?>
    <div class="card no-print"><p class="muted">No staff have earnings recorded for this period yet.</p></div>
<?php else: ?>
<div class="pp-desk">
  <div class="pp-zoom" id="ppZoomBox">
    <?php foreach ($sheets as $si => $sheetRows): ?>
    <p class="pp-sheet-label no-print">Sheet <?= $si + 1 ?> of <?= $sheetCount ?></p>
    <div class="sheet">
        <?php for ($k = 0; $k < 4; $k++): $row = $sheetRows[$k] ?? null; ?>
        <?php if (!$row): ?>
            <div class="ps ps-empty"></div>
        <?php else: ?>
        <div class="ps" style="border-top: 4px solid <?= h($colors['solid']) ?>;">
            <h2><?= h($companyName) ?> Service Charge Slip</h2>

            <div class="ps-row"><span>Name</span><strong><?= h($row['full_name']) ?></strong></div>
            <div class="ps-row"><span>Branch</span><span><?= h($branch['name'] ?? '') ?></span></div>
            <div class="ps-row"><span>Covered Period</span><span><?= h(date('M j', strtotime($period['start_date']))) ?> &ndash; <?= h(date('M j, Y', strtotime($period['end_date']))) ?></span></div>
            <div class="ps-row"><span>Days on Duty</span><span><?= (int)$row['regular_days'] + (int)$row['bar_night_days'] ?></span></div>
            <div class="ps-row"><span>Regular Day SC (<?= (int)$row['regular_days'] ?> day<?= (int)$row['regular_days'] === 1 ? '' : 's' ?>)</span><span><?= money($row['regular_sc']) ?></span></div>
            <div class="ps-row"><span>Bar Night SC (<?= (int)$row['bar_night_days'] ?> day<?= (int)$row['bar_night_days'] === 1 ? '' : 's' ?>)</span><span><?= money($row['bar_night_sc']) ?></span></div>
            <div class="ps-row total"><span>GROSS SERVICE CHARGE</span><span><?= money($row['combined_total_sc']) ?></span></div>

            <?php if ((float)$row['damages_charges'] > 0): ?>
            <div class="ps-row"><span>Damages &amp; Charges</span><span>&minus; <?= money($row['damages_charges']) ?></span></div>
            <?php endif; ?>
            <?php if ((float)$row['cash_advance'] > 0): ?>
            <div class="ps-row"><span>Cash Advance</span><span>&minus; <?= money($row['cash_advance']) ?></span></div>
            <?php endif; ?>
            <div class="ps-row"><span>Overcost / COGS</span><span>&minus; <?= money($row['overcost_cogs']) ?></span></div>

            <div class="ps-row total"><span>NET</span><span><?= money($row['net']) ?></span></div>

            <div class="ps-sig">
                <div><span class="ps-sig-name"><?= h($row['full_name']) ?></span>Received By</div>
                <div><span class="ps-sig-name"><?= h($approvedBy) ?></span>Approved By</div>
            </div>
        </div>
        <?php endif; ?>
        <?php endfor; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
    var PAPERS = {
        short: { w: '8.5in',  h: '11in',    wIn: 8.5 },
        long:  { w: '8.5in',  h: '13in',    wIn: 8.5 },
        a4:    { w: '8.27in', h: '11.69in', wIn: 8.27 }
    };
    var paperSel = document.getElementById('ppPaper');
    var zoomSel  = document.getElementById('ppZoom');
    var box      = document.getElementById('ppZoomBox');
    var pageCss  = document.getElementById('pageSizeStyle');
    if (!paperSel || !box) return;

    function save(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
    function load(k)    { try { return localStorage.getItem(k); } catch (e) { return null; } }

    function applyPaper() {
        var p = PAPERS[paperSel.value] || PAPERS.short;
        pageCss.textContent = '@page { size: ' + p.w + ' ' + p.h + '; margin: 0; }';
        box.querySelectorAll('.sheet').forEach(function (s) {
            s.style.setProperty('--paper-w', p.w);
            s.style.setProperty('--paper-h', p.h);
        });
        save('scs_slip_paper', paperSel.value);
        applyZoom();
    }

    function applyZoom() {
        var z = zoomSel.value;
        if (z === 'fit') {
            var p = PAPERS[paperSel.value] || PAPERS.short;
            var avail = box.parentElement.clientWidth - 24;
            z = Math.min(1, avail / (p.wIn * 96));
        }
        box.style.zoom = z;
        save('scs_slip_zoom', zoomSel.value);
    }

    var sp = load('scs_slip_paper'); if (sp && PAPERS[sp]) paperSel.value = sp;
    var sz = load('scs_slip_zoom');  if (sz) zoomSel.value = sz;

    paperSel.addEventListener('change', applyPaper);
    zoomSel.addEventListener('change', applyZoom);
    window.addEventListener('resize', function () { if (zoomSel.value === 'fit') applyZoom(); });
    window.addEventListener('beforeprint', function () { box.style.zoom = 1; });
    window.addEventListener('afterprint', applyZoom);
    applyPaper();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
