<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_admin();

$branches = visible_branches(false);
// Periods are never created for the Shared pseudo-branch -- hide it so it can't be picked by mistake.
$__sharedId = function_exists('shared_branch_id') ? (int)shared_branch_id() : 0;
$__editingId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($__sharedId && !$__editingId) {
    $branches = array_values(array_filter($branches, fn($b) => (int)$b['id'] !== $__sharedId));
}
if (!$branches) {
    flash('error', 'Create a branch first before creating a period.');
    redirect('/branches/form.php');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$period = [
    'branch_id' => scoped_branch_id($_GET['branch_id'] ?? $branches[0]['id']),
    'name' => '',
    'start_date' => date('Y-m-01'),
    'end_date' => date('Y-m-t'),
    'management_share_percent' => get_setting('default_management_share_percent', 20),
    'status' => 'open',
];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM periods WHERE id = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) { flash('error', 'Period not found.'); redirect('/periods/list.php'); }
    require_branch_access($found['branch_id']);
    $period = $found;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $period['branch_id'] = scoped_branch_id($_POST['branch_id'] ?? 0);
    $period['name'] = trim($_POST['name'] ?? '');
    $period['start_date'] = $_POST['start_date'] ?? '';
    $period['end_date'] = $_POST['end_date'] ?? '';
    $period['management_share_percent'] = (float)($_POST['management_share_percent'] ?? 0);
    $period['status'] = $_POST['status'] ?? 'open';

    if (!$period['branch_id']) $errors[] = 'Please select a branch.';
    if ($period['name'] === '') $errors[] = 'Name is required.';
    if (!$period['start_date'] || !$period['end_date']) $errors[] = 'Start and end dates are required.';
    if ($period['start_date'] && $period['end_date'] && $period['start_date'] > $period['end_date']) {
        $errors[] = 'Start date must be before end date.';
    }

    if (!$errors) {
        if ($id) {
            $stmt = $pdo->prepare('UPDATE periods SET branch_id=?, name=?, start_date=?, end_date=?, management_share_percent=?, status=? WHERE id=?');
            $stmt->execute([$period['branch_id'], $period['name'], $period['start_date'], $period['end_date'], $period['management_share_percent'], $period['status'], $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO periods (branch_id, name, start_date, end_date, management_share_percent, status) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$period['branch_id'], $period['name'], $period['start_date'], $period['end_date'], $period['management_share_percent'], $period['status']]);
            $id = (int)$pdo->lastInsertId();
        }
        flash('success', 'Period saved.');
        redirect('/periods/view.php?id=' . $id);
    }
}

function pf_color($name) {
    static $map = []; static $palette = ['#e08a1e', '#1fa24d', '#c2201c', '#3b5b8f', '#7a5ab0', '#2a9aa8'];
    $u = strtoupper($name);
    if (strpos($u, 'DOIS') !== false) return '#e08a1e';
    if (strpos($u, 'STELLA') !== false) return '#1fa24d';
    if (strpos($u, 'HERO') !== false) return '#c2201c';
    if (strpos($u, 'SHARED') !== false) return '#8b948d';
    if (!isset($map[$u])) $map[$u] = $palette[count($map) % count($palette)];
    return $map[$u];
}
// Existing periods, so the form can warn (not block) when dates overlap another one.
$others = $pdo->query('SELECT id, branch_id, name, start_date, end_date FROM periods')->fetchAll();
$othersJs = array_map(fn($o) => ['id' => (int)$o['id'], 'b' => (int)$o['branch_id'], 'n' => $o['name'], 's' => $o['start_date'], 'e' => $o['end_date']], $others);
$branchMeta = [];
foreach ($branches as $b) $branchMeta[(int)$b['id']] = ['name' => $b['name'], 'color' => pf_color($b['name'])];

$pageTitle = $id ? 'Edit Period' : 'New Period';
require __DIR__ . '/../includes/header.php';
?>
<style>
.pf-head { margin-bottom:16px; }
.pf-head h1 { margin:0; }
.pf-head p { margin:4px 0 0; color:var(--text-muted); }
.pf-grid { display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:18px; align-items:start; }
.pf-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); box-shadow:0 1px 2px rgba(15,35,28,.04); }
.pf-sec { padding:20px 22px; border-bottom:1px solid var(--border); }
.pf-sec:last-of-type { border-bottom:0; }
.pf-sec h2 { margin:0 0 14px; font-size:12px; letter-spacing:.06em; text-transform:uppercase; color:var(--text-muted); }
.pf-lbl { display:block; font-size:13px; font-weight:700; color:var(--text); margin:0 0 6px; }
.pf-hint { font-size:12.5px; color:var(--text-faint); margin-top:6px; }
.pf-field { margin-bottom:16px; }
.pf-field:last-child { margin-bottom:0; }
.pf-field input[type=text], .pf-field input[type=date], .pf-field input[type=number] { margin:0; height:44px; }
.pf-two { display:grid; grid-template-columns:1fr 1fr; gap:14px; }

.pf-branches { display:flex; flex-wrap:wrap; gap:8px; }
.pf-branches label { position:relative; margin:0; cursor:pointer; }
.pf-branches input { position:absolute; opacity:0; inset:0; }
.pf-branches span { display:inline-flex; align-items:center; gap:9px; height:42px; padding:0 16px; border:1.5px solid var(--border-strong); border-radius:10px; font-weight:700; font-size:14px; background:#fff; transition:border-color .12s, background .12s; }
.pf-branches i { width:10px; height:10px; border-radius:3px; }
.pf-branches input:checked + span { border-color:var(--primary); background:#eef6f3; box-shadow:0 0 0 3px rgba(20,108,91,.12); }
.pf-branches input:focus-visible + span { outline:2px solid var(--primary); outline-offset:2px; }

/* Shows a real date like "Oct 1, 2026"; the transparent native date input on top provides the calendar picker. */
.pf-date { position:relative; height:44px; border:1px solid var(--border); border-radius:var(--radius-sm); background:var(--surface-soft); transition:border-color .12s, box-shadow .12s; }
.pf-date:hover { border-color:var(--border-strong); }
.pf-date:focus-within { border-color:var(--primary-light); box-shadow:0 0 0 3px rgba(31,156,129,.16); background:#fff; }
.pf-date-txt { position:absolute; inset:0; display:flex; align-items:center; padding:0 46px 0 14px; font-size:15px; font-weight:600; pointer-events:none; }
.pf-date-ic { position:absolute; right:14px; top:50%; transform:translateY(-50%); width:19px; height:19px; color:var(--primary-dark); pointer-events:none; }
.pf-date input[type=date] { position:absolute; inset:0; width:100%; height:100%; margin:0; padding:0; opacity:0; cursor:pointer; border:0; }
.pf-date input[type=date]::-webkit-calendar-picker-indicator { position:absolute; inset:0; width:100%; height:100%; opacity:0; cursor:pointer; }
.pf-days { display:inline-block; margin-top:8px; font-size:13px; font-weight:700; color:var(--primary-dark); background:#e4efeb; padding:3px 10px; border-radius:999px; }
.pf-warn { display:none; margin-top:12px; padding:10px 14px; border-radius:10px; background:var(--warning-light); border:1px solid #f0d9a8; color:#7a5200; font-size:13.5px; }
.pf-warn b { font-weight:700; }

.pf-suffix { position:relative; }
.pf-suffix input { padding-right:44px !important; }
.pf-suffix span { position:absolute; right:14px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-weight:700; pointer-events:none; }

.pf-seg { display:inline-flex; border:1.5px solid var(--border-strong); border-radius:10px; overflow:hidden; height:44px; }
.pf-seg label { margin:0; position:relative; cursor:pointer; }
.pf-seg input { position:absolute; opacity:0; inset:0; }
.pf-seg span { display:flex; align-items:center; gap:8px; height:100%; padding:0 20px; font-weight:700; font-size:14px; background:#fff; border-right:1px solid var(--border-strong); }
.pf-seg label:last-child span { border-right:0; }
.pf-seg i { width:8px; height:8px; border-radius:50%; background:var(--success); }
.pf-seg label:last-child i { background:var(--text-faint); }
.pf-seg input:checked + span { background:var(--primary); color:#fff; }
.pf-seg input:checked + span i { background:#fff; }

.pf-actions { display:flex; gap:10px; align-items:center; padding:16px 22px; background:var(--surface-soft); border-top:1px solid var(--border); border-radius:0 0 var(--radius) var(--radius); }
.pf-actions .btn { height:42px; padding:0 24px; margin:0; }

.pf-aside { position:sticky; top:84px; padding:18px 20px; }
.pf-aside h2 { margin:0 0 12px; font-size:12px; letter-spacing:.06em; text-transform:uppercase; color:var(--text-muted); }
.pf-pv-name { font-size:18px; font-weight:800; line-height:1.25; word-break:break-word; }
.pf-pv-br { display:inline-flex; align-items:center; gap:8px; margin-top:6px; font-weight:700; font-size:13.5px; }
.pf-pv-br i { width:10px; height:10px; border-radius:3px; }
.pf-pv dl { margin:16px 0 0; display:grid; gap:10px; }
.pf-pv dl div { display:flex; justify-content:space-between; gap:10px; font-size:13.5px; border-top:1px solid var(--border); padding-top:10px; }
.pf-pv dt { color:var(--text-muted); } .pf-pv dd { margin:0; font-weight:700; text-align:right; }
.pf-note { margin-top:14px; font-size:12.5px; color:var(--text-faint); line-height:1.5; }

@media (max-width:900px) { .pf-grid { grid-template-columns:1fr; } .pf-aside { position:static; } .pf-two { grid-template-columns:1fr; } }
</style>

<div class="pf-head">
    <h1><?= $id ? 'Edit period' : 'New period' ?></h1>
    <p>One payout cycle for one branch.</p>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-error"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" action="" id="pf">
<?= csrf_field() ?>
<input type="hidden" name="id" value="<?= (int)$id ?>">
<div class="pf-grid">
    <div class="pf-card">
        <div class="pf-sec">
            <h2>Branch &amp; name</h2>
            <div class="pf-field">
                <span class="pf-lbl">Branch</span>
                <?php if (is_branch_locked()): ?>
                    <input type="hidden" name="branch_id" value="<?= (int)$period['branch_id'] ?>">
                    <div class="pf-branches"><label><input type="radio" checked disabled><span><i style="background:<?= pf_color($branches[0]['name'] ?? '') ?>"></i><?= h($branches[0]['name'] ?? '') ?></span></label></div>
                <?php else: ?>
                <div class="pf-branches" role="radiogroup">
                    <?php foreach ($branches as $b): ?>
                        <label><input type="radio" name="branch_id" value="<?= $b['id'] ?>" <?= (int)$period['branch_id'] === (int)$b['id'] ? 'checked' : '' ?> required><span><i style="background:<?= pf_color($b['name']) ?>"></i><?= h(branch_option_label($b)) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="pf-field">
                <label class="pf-lbl" for="name">Period name</label>
                <input type="text" id="name" name="name" value="<?= h($period['name']) ?>" placeholder="e.g. NOVEMBER 2026 or Q4 2026 (Oct–Dec)" required>
                <div class="pf-hint">Shown on the summary and on every slip.</div>
            </div>
        </div>

        <div class="pf-sec">
            <h2>Dates</h2>
            <div class="pf-two">
                <?php foreach (['start' => 'Start date', 'end' => 'End date'] as $pre => $lab):
                    $dv = $period[$pre . '_date'] ?: date('Y-m-d'); ?>
                <div class="pf-field">
                    <span class="pf-lbl"><?= $lab ?></span>
                    <div class="pf-date">
                        <div class="pf-date-txt" id="<?= $pre ?>_txt"><?= h(date('M j, Y', strtotime($dv))) ?></div>
                        <svg class="pf-date-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>
                        <input type="date" id="<?= $pre ?>_date" name="<?= $pre ?>_date" value="<?= h($dv) ?>" required aria-label="<?= $lab ?>">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <span class="pf-days" id="pfDays"></span>
            <div class="pf-warn" id="pfWarn"></div>
        </div>

        <div class="pf-sec">
            <h2>Payout</h2>
            <div class="pf-two">
                <div class="pf-field"><label class="pf-lbl" for="management_share_percent">Management share</label>
                    <div class="pf-suffix"><input type="number" step="0.01" min="0" max="100" id="management_share_percent" name="management_share_percent" value="<?= h($period['management_share_percent']) ?>"><span>%</span></div>
                    <div class="pf-hint">Management's percentage of the service charge for this period.</div></div>
                <div class="pf-field"><span class="pf-lbl">Status</span>
                    <div class="pf-seg">
                        <label><input type="radio" name="status" value="open" <?= $period['status']==='closed' ? '' : 'checked' ?>><span><i></i>Open</span></label>
                        <label><input type="radio" name="status" value="closed" <?= $period['status']==='closed' ? 'checked' : '' ?>><span><i></i>Closed</span></label>
                    </div>
                    <div class="pf-hint">A label only. It doesn't lock the period.</div></div>
            </div>
        </div>

        <div class="pf-actions">
            <button type="submit" class="btn">Save period</button>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/periods/list.php">Cancel</a>
        </div>
    </div>

    <aside class="pf-card pf-aside pf-pv">
        <h2>Preview</h2>
        <div class="pf-pv-name" id="pvName">—</div>
        <div class="pf-pv-br"><i id="pvDot"></i><span id="pvBranch">—</span></div>
        <dl>
            <div><dt>Dates</dt><dd id="pvDates">—</dd></div>
            <div><dt>Length</dt><dd id="pvLen">—</dd></div>
            <div><dt>Mgmt share</dt><dd id="pvShare">—</dd></div>
            <div><dt>Status</dt><dd id="pvStatus">—</dd></div>
        </dl>
        <div class="pf-note">Totals and slips are built from every duty entry for this branch inside these dates.</div>
    </aside>
</div>
</form>

<script>
(function () {
  var BR = <?= json_encode($branchMeta, JSON_UNESCAPED_UNICODE) ?>;
  var OTHERS = <?= json_encode($othersJs, JSON_UNESCAPED_UNICODE) ?>;
  var SELF = <?= (int)$id ?>;
  var f = document.getElementById('pf');
  var $ = function (id) { return document.getElementById(id); };
  var MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  var MONFULL = ['JANUARY','FEBRUARY','MARCH','APRIL','MAY','JUNE','JULY','AUGUST','SEPTEMBER','OCTOBER','NOVEMBER','DECEMBER'];
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parse(s) { var p = (s || '').split('-'); return p.length === 3 ? new Date(+p[0], +p[1] - 1, +p[2]) : null; }
  function fmt(d) { return MON[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear(); }
  function branchId() { var c = f.querySelector('input[name=branch_id]:checked') || f.querySelector('input[name=branch_id]'); return c ? +c.value : 0; }

  function showTxt(pre) { var d = parse($(pre + '_date').value); $(pre + '_txt').textContent = d ? fmt(d) : 'Pick a date'; }
  function putDMY(pre, d) { $(pre + '_date').value = iso(d); showTxt(pre); }
  ['start', 'end'].forEach(function (pre) {
    var inp = $(pre + '_date');
    inp.addEventListener('click', function () { try { if (inp.showPicker) inp.showPicker(); } catch (e) {} });
    inp.addEventListener('change', function () { showTxt(pre); update(); });
    inp.addEventListener('input', function () { showTxt(pre); });
  });

  function suggestName(s, e) {
    if (!s || !e) return '';
    var sameMonth = s.getFullYear() === e.getFullYear() && s.getMonth() === e.getMonth() && s.getDate() === 1 &&
                    e.getDate() === new Date(e.getFullYear(), e.getMonth() + 1, 0).getDate();
    if (sameMonth) return MONFULL[s.getMonth()] + ' ' + s.getFullYear();
    return '';
  }
  function setRange(s, e) {
    putDMY('start', s); putDMY('end', e);
    var n = $('name');
    if (!n.value.trim() || n.dataset.auto === '1') {
      var g = suggestName(s, e);
      if (!g) { var q = Math.floor(s.getMonth() / 3) + 1; if (s.getMonth() % 3 === 0 && e.getMonth() === s.getMonth() + 2) g = 'Q' + q + ' ' + s.getFullYear() + ' (' + MON[s.getMonth()] + '–' + MON[e.getMonth()] + ')'; }
      if (g) { n.value = g; n.dataset.auto = '1'; }
    }
    update();
  }
  $('name').addEventListener('input', function () { this.dataset.auto = '0'; update(); });

  function update() {
    var s = parse($('start_date').value), e = parse($('end_date').value), bid = branchId(), br = BR[bid] || {name: '—', color: '#ccc'};
    var days = (s && e) ? Math.round((e - s) / 86400000) + 1 : 0;
    $('pfDays').style.display = days > 0 ? 'inline-block' : 'none';
    $('pfDays').textContent = days + ' day' + (days === 1 ? '' : 's');
    $('pvName').textContent = $('name').value.trim() || 'Untitled period';
    $('pvBranch').textContent = br.name; $('pvDot').style.background = br.color;
    $('pvDates').textContent = (s && e) ? fmt(s).replace(/, \d{4}$/, '') + ' – ' + fmt(e) : '—';
    $('pvLen').textContent = days > 0 ? days + ' days' : (s && e ? 'End is before start' : '—');
    var sh = $('management_share_percent').value; $('pvShare').textContent = sh === '' ? '—' : (+sh) + '%';
    var st = f.querySelector('input[name=status]:checked'); $('pvStatus').textContent = st ? (st.value === 'open' ? 'Open' : 'Closed') : '—';

    // overlap warning (non-blocking)
    var w = $('pfWarn'), hits = [];
    if (s && e && days > 0) OTHERS.forEach(function (o) {
      if (o.id === SELF || o.b !== bid) return;
      var os = parse(o.s), oe = parse(o.e);
      if (s <= oe && e >= os) hits.push(o);
    });
    if (hits.length) {
      w.style.display = 'block';
      w.innerHTML = '<b>Overlaps another period for this branch:</b> ' + hits.map(function (o) {
        return o.n.replace(/[<>&]/g, '') + ' (' + fmt(parse(o.s)) + ' – ' + fmt(parse(o.e)) + ')';
      }).join('; ') + '. The same duty days would count in both.';
    } else w.style.display = 'none';
  }
  f.addEventListener('input', update); f.addEventListener('change', update);
  update();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>