<?php
/**
 * Admin-only dashboard block: per-branch Gross SC cards (today / this month /
 * all time) that double as filters for a month-by-month chart.
 * Expects: $pdo, $branchStats (from dashboard.php).
 * "Gross SC" here means the sum of duty_days.total_amount, same as the cards above.
 */
/** @var PDO $pdo */
/** @var array $branchStats  provided by dashboard.php, which includes this file */
$__sharedId = shared_branch_id();
$__cards = array_values(array_filter($branchStats, fn($b) => (int)$b['id'] !== $__sharedId));

// Monthly gross SC per branch (all time) -- filtered client-side.
$__monthly = [];
$__years = [(int)date('Y')];
foreach ($pdo->query("SELECT branch_id, DATE_FORMAT(duty_date, '%Y-%m') AS ym, SUM(total_amount) AS amt
                      FROM duty_days GROUP BY branch_id, ym")->fetchAll() as $r) {
    $__monthly[(int)$r['branch_id']][$r['ym']] = (float)$r['amt'];
    $__years[] = (int)substr($r['ym'], 0, 4);
}
$__years = array_values(array_unique($__years));
sort($__years);

// Locked months (branch_id 0 = all branches).
$__locks = [];
try {
    foreach ($pdo->query('SELECT ym, branch_id FROM month_branch_locks')->fetchAll() as $r) {
        $__locks[$r['ym']][] = (int)$r['branch_id'];
    }
} catch (PDOException $e) { /* lock table not created yet */ }

$__cardData = [];
foreach ($__cards as $b) {
    $theme = branch_theme_colors(branch_theme_key($b['name']));
    $id = (int)$b['id'];
    $__cardData[] = [
        'id'    => $id,
        'name'  => $b['name'],
        'color' => branch_theme_key($b['name']) === 'hero' ? $theme['dark'] : $theme['solid'],
        'today' => (float)$b['today_total'],
        'month' => (float)$b['month_total'],
        'all'   => array_sum($__monthly[$id] ?? []),
        'url'   => BASE_URL . '/branches/view.php?id=' . $id,
    ];
}
$__payload = [
    'branches' => $__cardData,
    'monthly'  => (object)$__monthly,
    'locks'    => (object)$__locks,
    'years'    => $__years,
    'thisYm'   => date('Y-m'),
    'lastYm'   => date('Y-m', strtotime('first day of last month')),
    'cur'      => get_setting('currency_symbol', '₱'),
];
?>
<style>
.sc-cards .branch-card { cursor:pointer; }
.sc-cards .bc-row .k { white-space:nowrap; }
.sc-cards .bc-row.all { margin-top:6px; padding-top:8px; border-top:1px dashed var(--border-strong); font-size:.9rem; font-weight:800; }
.sc-cards .bc-row.all .k { color:var(--text); font-weight:700; }
.sc-cards .branch-card.is-selected .bc-row.all, .sc-cards .branch-card:hover .bc-row.all { border-top-color:rgba(255,255,255,.4); }
.sc-cards .branch-card.is-selected .bc-row.all .k, .sc-cards .branch-card:hover .bc-row.all .k { color:#fff; }
.sc-cards .bc-open { display:inline-block; margin-top:8px; font-size:.72rem; font-weight:700; color:var(--primary); text-decoration:none; }
.sc-cards .bc-open:hover { text-decoration:underline; }
.sc-cards .branch-card.is-selected .bc-open, .sc-cards .branch-card:hover .bc-open { color:#fff; }
.sc-chart { margin-top:18px; }
.sc-head { display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; margin-bottom:4px; }
.sc-head h2 { margin:0; }
.sc-year { display:flex; align-items:center; gap:8px; }
.sc-year strong { min-width:48px; text-align:center; font-size:1.05rem; }
.sc-year button { width:32px; height:32px; border-radius:8px; border:1px solid var(--border-strong); background:#fff; cursor:pointer; font-size:1rem; color:var(--primary-dark); }
.sc-year button:disabled { opacity:.35; cursor:default; }
.sc-showing { font-size:.82rem; color:var(--text-muted); margin-bottom:10px; }
.sc-showing b { color:var(--text); }
.sc-showing button { border:0; background:none; color:var(--primary); font-weight:700; cursor:pointer; padding:0 0 0 8px; font-size:.82rem; }
.sc-wrap { position:relative; width:100%; }
.sc-wrap svg { display:block; width:100%; height:auto; overflow:visible; }
.sc-tip { position:absolute; pointer-events:none; background:#12372a; color:#fff; border-radius:10px; padding:9px 12px; font-size:.78rem;
    box-shadow:0 8px 20px rgba(0,0,0,.25); opacity:0; transition:opacity .1s; z-index:5; min-width:150px; }
.sc-tip.show { opacity:1; }
.sc-tip .t-title { font-weight:800; margin-bottom:5px; }
.sc-tip .t-row { display:flex; justify-content:space-between; gap:14px; padding:1px 0; }
.sc-tip .t-row i { display:inline-block; width:8px; height:8px; border-radius:2px; margin-right:6px; }
.sc-tip .t-total { border-top:1px solid rgba(255,255,255,.25); margin-top:5px; padding-top:5px; font-weight:800; display:flex; justify-content:space-between; }
.sc-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-top:16px; }
.sc-stat { background:var(--surface-soft); border:1px solid var(--border); border-radius:12px; padding:12px 14px; }
.sc-stat .l { font-size:.7rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--text-muted); }
.sc-stat .v { font-size:1.15rem; font-weight:800; color:var(--primary-dark); margin-top:3px; }
.sc-stat .s { font-size:.75rem; color:var(--text-muted); margin-top:1px; }
.sc-up { color:#1a7f3f !important; } .sc-down { color:#c2402f !important; }
.sc-empty { text-align:center; color:var(--text-muted); padding:60px 0; font-size:.9rem; }
</style>

<div class="section-heading">
    <h2>Service Charge by Branch</h2>
    <span class="hint">Tap one or more branches to filter the chart</span>
</div>
<div class="branch-grid sc-cards" id="scCards">
    <?php foreach ($__cardData as $c): $key = branch_theme_key($c['name']); ?>
        <div class="branch-card branch-<?= h($key) ?>" data-id="<?= $c['id'] ?>" tabindex="0" role="button" aria-pressed="false">
            <div class="bc-top">
                <span class="bc-dot"></span>
                <span class="bc-name"><?= h($c['name']) ?></span>
                <span class="bc-check">✓</span>
            </div>
            <div class="bc-row"><span class="k">Today</span><span class="v"><?= h(money($c['today'])) ?></span></div>
            <div class="bc-row"><span class="k">This month</span><span class="v"><?= h(money($c['month'])) ?></span></div>
            <div class="bc-row all"><span class="k">All time</span><span class="v"><?= h(money($c['all'])) ?></span></div>
            <a class="bc-open" href="<?= h($c['url']) ?>">Open branch page →</a>
        </div>
    <?php endforeach; ?>
    <?php if (!$__cardData): ?><p class="muted">No branches yet. <a href="<?= BASE_URL ?>/branches/form.php">Add one</a>.</p><?php endif; ?>
</div>

<div class="card sc-chart">
    <div class="sc-head">
        <h2>Gross SC per month</h2>
        <div class="sc-year">
            <button type="button" id="scPrev" aria-label="Previous year">&lsaquo;</button>
            <strong id="scYear"></strong>
            <button type="button" id="scNext" aria-label="Next year">&rsaquo;</button>
        </div>
    </div>
    <div class="sc-showing" id="scShowing"></div>
    <div class="sc-wrap" id="scWrap"><div id="scSvgHost"></div><div class="sc-tip" id="scTip"></div></div>
    <div class="sc-stats" id="scStats"></div>
</div>

<script>
(function () {
    var D = <?= json_encode($__payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var FULL = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var sel = {};                       // branch id -> true
    var year = parseInt(D.thisYm.slice(0, 4), 10);
    var minY = D.years[0], maxY = D.years[D.years.length - 1];
    var host = document.getElementById('scSvgHost'), tip = document.getElementById('scTip'), wrap = document.getElementById('scWrap');

    function fmt(n) { return D.cur + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function short(n) {
        if (n >= 1e6) return D.cur + (n / 1e6).toFixed(n % 1e6 ? 1 : 0) + 'M';
        if (n >= 1e3) return D.cur + (n / 1e3).toFixed(n % 1e3 ? 1 : 0) + 'k';
        return D.cur + Math.round(n);
    }
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function active() {
        var ids = Object.keys(sel);
        return D.branches.filter(function (b) { return !ids.length || sel[b.id]; });
    }
    function val(b, ym) { var m = D.monthly[b.id]; return (m && m[ym]) || 0; }
    function niceMax(v) {
        if (v <= 0) return 1;
        var p = Math.pow(10, Math.floor(Math.log10(v))), f = v / p;
        return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * p;
    }
    function isLocked(ym, list) {
        var L = D.locks[ym]; if (!L) return false;
        if (L.indexOf(0) !== -1) return true;
        return list.length > 0 && list.every(function (b) { return L.indexOf(b.id) !== -1; });
    }

    function render() {
        var list = active();
        document.getElementById('scYear').textContent = year;
        document.getElementById('scPrev').disabled = year <= minY;
        document.getElementById('scNext').disabled = year >= maxY;

        var showing = document.getElementById('scShowing');
        var ids = Object.keys(sel);
        showing.innerHTML = ids.length
            ? 'Showing: <b>' + list.map(function (b) { return esc(b.name); }).join(' + ') + '</b><button type="button" id="scClear">Clear filter</button>'
            : 'Showing: <b>All branches</b> (stacked by branch)';
        var clr = document.getElementById('scClear');
        if (clr) clr.onclick = function () { sel = {}; syncCards(); render(); };

        // data per month
        var rows = [], max = 0;
        for (var m = 0; m < 12; m++) {
            var ym = year + '-' + (m < 9 ? '0' : '') + (m + 1), parts = [], tot = 0;
            list.forEach(function (b) { var v = val(b, ym); if (v) parts.push({ b: b, v: v }); tot += v; });
            rows.push({ ym: ym, m: m, parts: parts, tot: tot });
            max = Math.max(max, tot);
        }
        var yTop = niceMax(max);

        var W = Math.max(320, wrap.clientWidth), H = W < 520 ? 250 : 320;
        var L = W < 520 ? 44 : 58, R = 8, T = 14, B = 46, iw = W - L - R, ih = H - T - B, slot = iw / 12;
        var bw = Math.min(46, slot * 0.62);
        var s = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Gross service charge per month">';
        for (var g = 0; g <= 4; g++) {
            var gv = yTop * g / 4, gy = T + ih - ih * g / 4;
            s += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + gy + '" y2="' + gy + '" stroke="' + (g ? '#e2e6e3' : '#cfd6d1') + '"' + (g ? ' stroke-dasharray="3 4"' : '') + '/>' +
                 '<text x="' + (L - 8) + '" y="' + (gy + 4) + '" text-anchor="end" font-size="11" fill="#667169">' + short(gv) + '</text>';
        }
        rows.forEach(function (r) {
            var cx = L + slot * r.m + slot / 2, x = cx - bw / 2, y = T + ih, isNow = r.ym === D.thisYm;
            if (isNow) s += '<rect x="' + (cx - slot / 2 + 2) + '" y="' + T + '" width="' + (slot - 4) + '" height="' + ih + '" rx="8" fill="#146c5b" opacity=".06"/>';
            r.parts.forEach(function (p, i) {
                var h = Math.max(1.5, ih * p.v / yTop); y -= h;
                var top = i === r.parts.length - 1;
                s += top
                    ? '<path d="M' + x + ' ' + (y + h) + 'V' + (y + 4) + 'Q' + x + ' ' + y + ' ' + (x + 4) + ' ' + y + 'H' + (x + bw - 4) + 'Q' + (x + bw) + ' ' + y + ' ' + (x + bw) + ' ' + (y + 4) + 'V' + (y + h) + 'Z" fill="' + p.b.color + '"/>'
                    : '<rect x="' + x + '" y="' + y + '" width="' + bw + '" height="' + h + '" fill="' + p.b.color + '"/>';
                if (!top) s += '<line x1="' + x + '" x2="' + (x + bw) + '" y1="' + y + '" y2="' + y + '" stroke="#fff" stroke-width="1.5"/>';
            });
            s += '<text x="' + cx + '" y="' + (T + ih + 17) + '" text-anchor="middle" font-size="11.5" fill="' + (isNow ? '#0c4a3e' : '#667169') + '" font-weight="' + (isNow ? 800 : 600) + '">' + MONTHS[r.m] + '</text>';
            if (isLocked(r.ym, list)) s += '<text x="' + cx + '" y="' + (T + ih + 34) + '" text-anchor="middle" font-size="10">🔒</text>';
            s += '<rect class="hit" data-m="' + r.m + '" x="' + (cx - slot / 2) + '" y="' + T + '" width="' + slot + '" height="' + (ih + B) + '" fill="transparent"/>';
        });
        s += '</svg>';
        host.innerHTML = max > 0 ? s : '<div class="sc-empty">No service charge recorded for ' + year + (ids.length ? ' with this filter' : '') + '.</div>';

        host.querySelectorAll('.hit').forEach(function (el) {
            function show(ev) {
                var r = rows[parseInt(el.getAttribute('data-m'), 10)], wr = wrap.getBoundingClientRect();
                var h = '<div class="t-title">' + FULL[r.m] + ' ' + year + (isLocked(r.ym, list) ? ' 🔒' : '') + '</div>';
                if (!r.parts.length) h += '<div class="t-row"><span>No entries</span></div>';
                r.parts.slice().reverse().forEach(function (p) {
                    h += '<div class="t-row"><span><i style="background:' + p.b.color + '"></i>' + esc(p.b.name) + '</span><span>' + fmt(p.v) + '</span></div>';
                });
                if (r.parts.length > 1) h += '<div class="t-total"><span>Total</span><span>' + fmt(r.tot) + '</span></div>';
                tip.innerHTML = h; tip.classList.add('show');
                var x = ev.clientX - wr.left + 14, y = ev.clientY - wr.top - 10;
                if (x + tip.offsetWidth > wrap.clientWidth) x = ev.clientX - wr.left - tip.offsetWidth - 14;
                tip.style.left = Math.max(0, x) + 'px'; tip.style.top = Math.max(0, y) + 'px';
            }
            el.addEventListener('mousemove', show); el.addEventListener('click', show);
            el.addEventListener('mouseleave', function () { tip.classList.remove('show'); });
        });

        // summary tiles
        var total = rows.reduce(function (a, r) { return a + r.tot; }, 0);
        var withData = rows.filter(function (r) { return r.tot > 0; });
        var best = withData.slice().sort(function (a, b) { return b.tot - a.tot; })[0];
        var h2 = '<div class="sc-stat"><div class="l">' + year + ' total</div><div class="v">' + fmt(total) + '</div><div class="s">' + withData.length + ' month' + (withData.length === 1 ? '' : 's') + ' with entries</div></div>';
        h2 += '<div class="sc-stat"><div class="l">Best month</div><div class="v">' + (best ? FULL[best.m] : '—') + '</div><div class="s">' + (best ? fmt(best.tot) : 'No data') + '</div></div>';
        h2 += '<div class="sc-stat"><div class="l">Monthly average</div><div class="v">' + fmt(withData.length ? total / withData.length : 0) + '</div><div class="s">across months with entries</div></div>';
        if (year === parseInt(D.thisYm.slice(0, 4), 10)) {
            var cur = list.reduce(function (a, b) { return a + val(b, D.thisYm); }, 0);
            var prev = list.reduce(function (a, b) { return a + val(b, D.lastYm); }, 0);
            var d = prev > 0 ? ((cur - prev) / prev * 100) : null;
            h2 += '<div class="sc-stat"><div class="l">This month vs last</div><div class="v ' + (d === null ? '' : d >= 0 ? 'sc-up' : 'sc-down') + '">' +
                  (d === null ? '—' : (d >= 0 ? '▲ ' : '▼ ') + Math.abs(d).toFixed(1) + '%') + '</div><div class="s">' + fmt(cur) + ' vs ' + fmt(prev) + '</div></div>';
        }
        document.getElementById('scStats').innerHTML = h2;
    }

    // Branch cards = filter (multi-select)
    var cards = Array.prototype.slice.call(document.querySelectorAll('#scCards .branch-card'));
    function syncCards() {
        cards.forEach(function (c) {
            var on = !!sel[c.getAttribute('data-id')];
            c.classList.toggle('is-selected', on); c.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }
    cards.forEach(function (c) {
        c.addEventListener('click', function (ev) {
            if (ev.target.closest('.bc-open')) return;
            var id = c.getAttribute('data-id');
            if (sel[id]) delete sel[id]; else sel[id] = true;
            syncCards(); render();
        });
        c.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); c.click(); } });
    });
    document.getElementById('scPrev').onclick = function () { if (year > minY) { year--; render(); } };
    document.getElementById('scNext').onclick = function () { if (year < maxY) { year++; render(); } };
    var rt; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(render, 120); });
    render();
})();
</script>