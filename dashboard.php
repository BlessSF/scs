<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

$user = current_user();
$pageTitle = 'Dashboard';

require __DIR__ . '/includes/header.php';

if (in_array($user['role'], ['admin', 'cashier', 'owner'], true)) {
    $isCashier = $user['role'] === 'cashier';
    $isOwner   = $user['role'] === 'owner'; // read-only company view: no branch drill-downs, no admin shortcuts
    $lockedBranchId = current_branch_id(); // null = sees every branch
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
            (SELECT COALESCE(SUM(dd.total_amount),0) FROM duty_days dd WHERE dd.branch_id = b.id AND dd.duty_date = ?) AS today_total,
            (SELECT COALESCE(SUM(dd.total_amount),0) FROM duty_days dd WHERE dd.branch_id = b.id AND dd.duty_date BETWEEN ? AND ?) AS month_total
        FROM branches b WHERE b.is_active = 1" . ($accessibleIds ? " AND b.id IN ($inPlaceholders)" : "") . " ORDER BY b.name";
    $stmt = $pdo->prepare($branchStatsSql);
    $stmt->execute($accessibleIds ? array_merge([$today, $thisMonthStart, $thisMonthEnd], $accessibleIds) : [$today, $thisMonthStart, $thisMonthEnd]);
    $branchStats = $stmt->fetchAll();

    $maxBranchToday = 0;
    foreach ($branchStats as $b) { $maxBranchToday = max($maxBranchToday, (float)$b['today_total']); }
    if ($maxBranchToday <= 0) $maxBranchToday = 1;

    $hour = (int)date('G');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    ?>

    <div class="hero-banner">
        <h1><?= h($greeting) ?>, <?= h($user['full_name']) ?></h1>
        <p class="subtitle">
            <?php if (count($branchStats) === 1): ?>
                Here's what's happening at <strong><?= h($branchStats[0]['name']) ?></strong> today.
            <?php else: ?>
                Here's what's happening across <span id="branchCountText"><?= $branchCount ?> branch<?= $branchCount === 1 ? '' : 'es' ?></span> today.
            <?php endif; ?>
        </p>
        <div class="hero-meta">
            <div class="hero-meta-item"><span class="live-dot"></span> <span id="liveStatus">Live &middot; updated <b id="updatedAt">just now</b></span></div>
            <div class="hero-meta-item"><?= h(date('l, F j, Y')) ?></div>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon">🏢</div>
            <div class="stat-value" id="statBranchCount"><?= $branchCount ?></div>
            <div class="stat-label">Active Branches</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <div class="stat-value" id="statStaffCount"><?= $staffCount ?></div>
            <div class="stat-label">Active Staff</div>
        </div>
        <?php if (!$isCashier): ?>
        <div class="stat-card">
            <div class="stat-icon">🗓️</div>
            <div class="stat-value" id="statAllTimeTotal"><?= money($allTimeTotal) ?></div>
            <div class="stat-label">Gross SC — All Time</div>
        </div>
        <?php endif; ?>
        <?php if (!$isCashier): ?>
        <div class="stat-card">
            <div class="stat-icon">💰</div>
            <div class="stat-value" id="statMonthTotal"><?= money($monthTotal) ?></div>
            <div class="stat-label">Gross SC — This Month</div>
        </div>
        <?php endif; ?>
        <?php if (!$isCashier): ?>
        <div class="stat-card">
            <div class="stat-icon">📆</div>
            <div class="stat-value" id="statOpenPeriods"><?= $openPeriods ?></div>
            <div class="stat-label">Open Periods</div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (count($branchStats) > 1): ?>
    <div class="section-heading">
        <h2>By Branch — Today</h2>
        <?php if (!$isOwner): ?><span class="hint">Tap a branch to open its page</span><?php endif; ?>
    </div>

    <div class="branch-grid" id="branchGrid">
        <?php foreach ($branchStats as $i => $b):
            $key = branch_theme_key($b['name']);
            $pct = round(((float)$b['today_total'] / $maxBranchToday) * 100);
        ?>
            <<?= $isOwner ? 'div' : 'a href="' . BASE_URL . '/branches/view.php?id=' . (int)$b['id'] . '"' ?>
                    class="branch-card branch-<?= h($key) ?>"
                    data-branch-id="<?= (int)$b['id'] ?>">
                <div class="bc-top">
                    <span class="bc-dot"></span>
                    <span class="bc-name"><?= h($b['name']) ?></span>
                    <?php if (!$isOwner): ?><span class="bc-check">→</span><?php endif; ?>
                </div>
                <div class="bc-row"><span class="k">Active staff</span><span class="v"><?= (int)$b['staff_count'] ?></span></div>
                <div class="bc-row"><span class="k">Gross SC (Today)</span><span class="v"><?= h(money($b['today_total'])) ?></span></div>
                <div class="bc-bar-track"><div class="bc-bar-fill" style="width: <?= $pct ?>%;"></div></div>
            </<?= $isOwner ? 'div' : 'a' ?>>
        <?php endforeach; ?>
        <?php if (!$branchStats): ?>
            <p class="muted">No branches yet<?= $isCashier ? '.' : '. <a href="' . BASE_URL . '/branches/form.php">Add one</a>.' ?></p>
        <?php endif; ?>
    </div>
    <?php elseif ($branchStats): ?>
    <div class="branch-spotlight branch-<?= h(branch_theme_key($branchStats[0]['name'])) ?>">
        <div>
            <div class="sp-title">Your Branch</div>
            <div class="sp-name"><?= h($branchStats[0]['name']) ?></div>
        </div>
        <div class="sp-stats">
            <div class="sp-stat"><b><?= (int)$branchStats[0]['staff_count'] ?></b><span>Active Staff</span></div>
            <?php if (!$isCashier): ?>
            <div class="sp-stat"><b><?= h(money($branchStats[0]['today_total'])) ?></b><span>Gross SC (Today)</span></div>
            <?php endif; ?>
        </div>
        <?php if (!$isOwner): ?>
        <a class="btn" href="<?= BASE_URL ?>/branches/view.php?id=<?= (int)$branchStats[0]['id'] ?>">View Branch Page →</a>
        <a class="btn" href="<?= BASE_URL ?>/duty/calendar.php">Open Duty Entry →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$isCashier && count($branchStats) > 1): ?>
    <div class="section-heading">
        <h2>By Branch — This Month</h2>
        <span class="hint">Tap a branch to spotlight it</span>
    </div>

    <div class="branch-grid" id="spotlightGrid">
        <?php foreach ($branchStats as $b): $key = branch_theme_key($b['name']); ?>
            <div class="branch-card branch-<?= h($key) ?> spotlight-card" data-amount="<?= (float)$b['month_total'] ?>" tabindex="0" role="button" aria-pressed="false">
                <div class="bc-top">
                    <span class="bc-dot"></span>
                    <span class="bc-name"><?= h($b['name']) ?></span>
                    <span class="bc-check">✓</span>
                </div>
                <div class="bc-row"><span class="k">Active staff</span><span class="v"><?= (int)$b['staff_count'] ?></span></div>
                <div class="bc-row"><span class="k">Gross SC</span><span class="v"><?= h(money($b['month_total'])) ?></span></div>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="small muted" id="spotlightTotal" style="margin-top:10px;">Select branches above to total their Gross SC for this month.</p>
    <script>
    (function () {
        var grid = document.getElementById('spotlightGrid');
        var out = document.getElementById('spotlightTotal');
        if (!grid || !out) return;
        var cards = Array.prototype.slice.call(grid.querySelectorAll('.spotlight-card'));

        function fmt(n) {
            return '<?= h(get_setting('currency_symbol', '₱')) ?>' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function recompute() {
            var selected = cards.filter(function (c) { return c.classList.contains('is-selected'); });
            if (!selected.length) {
                out.textContent = 'Select branches above to total their Gross SC for this month.';
                return;
            }
            var sum = selected.reduce(function (acc, c) { return acc + parseFloat(c.getAttribute('data-amount') || '0'); }, 0);
            out.textContent = selected.length + ' branch' + (selected.length === 1 ? '' : 'es') + ' selected — Total: ' + fmt(sum);
        }

        cards.forEach(function (card) {
            card.addEventListener('click', function () {
                card.classList.toggle('is-selected');
                card.setAttribute('aria-pressed', card.classList.contains('is-selected') ? 'true' : 'false');
                recompute();
            });
            card.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    card.click();
                }
            });
        });
    })();
    </script>
    <?php endif; ?>

    <div class="section-heading">
        <h2>Quick Actions</h2>
    </div>
    <?php if ($isOwner): ?>
    <div class="quick-grid">
        <a class="quick-tile" href="<?= BASE_URL ?>/owners/earnings.php">
            <span class="qt-icon">💼</span>
            <span class="qt-title">Owner Earnings</span>
            <span class="qt-desc">Your share across every branch and period</span>
        </a>
    </div>
    <?php else: ?>
    <div class="quick-grid">
        <a class="quick-tile" href="<?= BASE_URL ?>/duty/calendar.php">
            <span class="qt-icon">📝</span>
            <span class="qt-title">Record Today's Service Charge</span>
            <span class="qt-desc">Log the day's total and mark attendance</span>
        </a>
        <?php if (!$isCashier): ?>
        <a class="quick-tile" href="<?= BASE_URL ?>/branches/list.php">
            <span class="qt-icon">🏢</span>
            <span class="qt-title">Manage Branches</span>
            <span class="qt-desc">Add, edit, or deactivate branches</span>
        </a>
        <?php endif; ?>
        <a class="quick-tile" href="<?= BASE_URL ?>/staff/list.php">
            <span class="qt-icon">👥</span>
            <span class="qt-title">Manage Staff</span>
            <span class="qt-desc">Staff records and login accounts</span>
        </a>
        <?php if (!$isCashier): ?>
        <a class="quick-tile" href="<?= BASE_URL ?>/periods/list.php">
            <span class="qt-icon">📊</span>
            <span class="qt-title">Periods</span>
            <span class="qt-desc">Close cycles and view payouts</span>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (count($branchStats) > 1): ?>
    <script>
    (function () {
        var url = <?= json_encode(BASE_URL) ?> + '/api/dashboard_stats.php';
        var viewBase = <?= json_encode(BASE_URL . '/branches/view.php') ?>;
        var isOwner = <?= json_encode($isOwner) ?>;
        var pollMs = 8000;
        var timer = null;

        var THEME_MAP = [
            ['dois', 'dois'], ['stella', 'stella'], ['pub', 'pub'],
            ['hero', 'hero'], ['dnd', 'dnd'], ['commi', 'commi']
        ];
        function themeKey(name) {
            var n = (name || '').toLowerCase();
            for (var i = 0; i < THEME_MAP.length; i++) {
                if (n.indexOf(THEME_MAP[i][0]) !== -1) return THEME_MAP[i][1];
            }
            return 'default';
        }

        function escapeHtml(s) {
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        function render(data) {
            document.getElementById('statBranchCount').textContent = data.branch_count;
            document.getElementById('statStaffCount').textContent = data.staff_count;
            var monthEl = document.getElementById('statMonthTotal');
            if (monthEl) monthEl.textContent = data.month_total;
            var allEl = document.getElementById('statAllTimeTotal');
            if (allEl && data.all_time_total !== undefined) allEl.textContent = data.all_time_total;
            var openEl = document.getElementById('statOpenPeriods');
            if (openEl) openEl.textContent = data.open_periods;
            document.getElementById('branchCountText').textContent =
                data.branch_count + ' branch' + (data.branch_count === 1 ? '' : 'es');
            document.getElementById('updatedAt').textContent = data.updated_at;

            var grid = document.getElementById('branchGrid');
            if (!grid) return;

            if (data.branches.length === 0) {
                grid.innerHTML = '<p class="muted">No branches yet.</p>';
                return;
            }

            var maxTodayRaw = 0;
            data.branches.forEach(function (b) {
                var n = parseFloat((b.today_total_raw !== undefined ? b.today_total_raw : b.today_total).toString().replace(/[^0-9.]/g, ''));
                if (!isNaN(n)) maxTodayRaw = Math.max(maxTodayRaw, n);
            });
            if (maxTodayRaw <= 0) maxTodayRaw = 1;

            var cards = data.branches.map(function (b) {
                var key = themeKey(b.name);
                var rawTotal = parseFloat((b.today_total_raw !== undefined ? b.today_total_raw : b.today_total).toString().replace(/[^0-9.]/g, '')) || 0;
                var pct = Math.round((rawTotal / maxTodayRaw) * 100);
                var tag = isOwner ? 'div' : 'a';
                var open = isOwner ? '<div' : '<a href="' + viewBase + '?id=' + b.id + '"';
                return open + ' class="branch-card branch-' + key + '" data-branch-id="' + b.id + '">' +
                    '<div class="bc-top"><span class="bc-dot"></span><span class="bc-name">' + escapeHtml(b.name) + '</span>' + (isOwner ? '' : '<span class="bc-check">→</span>') + '</div>' +
                    '<div class="bc-row"><span class="k">Active staff</span><span class="v">' + b.staff_count + '</span></div>' +
                    '<div class="bc-row"><span class="k">Gross SC (Today)</span><span class="v">' + escapeHtml(b.today_total) + '</span></div>' +
                    '<div class="bc-bar-track"><div class="bc-bar-fill" style="width:' + pct + '%;"></div></div>' +
                    '</' + tag + '>';
            }).join('');
            grid.innerHTML = cards;
        }

        function poll() {
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
                .then(function (data) {
                    render(data);
                    var live = document.getElementById('liveStatus');
                    if (live) live.style.color = '';
                })
                .catch(function () {
                    var live = document.getElementById('liveStatus');
                    if (live) live.style.color = 'var(--danger)';
                });
        }

        function start() {
            if (timer) return;
            poll();
            timer = setInterval(poll, pollMs);
        }
        function stop() {
            clearInterval(timer);
            timer = null;
        }

        // Pause polling when the tab isn't visible, resume when it is.
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { stop(); } else { start(); }
        });

        start();
    })();
    </script>
    <?php endif; ?>
    <?php
} else {
    // Staff dashboard
    $staffId = $user['staff_id'];
    $stmt = $pdo->prepare('SELECT s.*, b.name AS branch_name FROM staff s JOIN branches b ON b.id = s.branch_id WHERE s.id = ?');
    $stmt->execute([$staffId]);
    $staff = $stmt->fetch();
    $key = branch_theme_key($staff['branch_name'] ?? '');
    ?>
    <div class="hero-banner">
        <h1>Welcome, <?= h($user['full_name']) ?></h1>
        <p class="subtitle">
            <?= h($staff['branch_name'] ?? '') ?> &middot; Status:
            <span class="badge badge-<?= h($staff['status'] ?? 'regular') ?>"><?= h(ucfirst(str_replace('_',' ',$staff['status'] ?? ''))) ?></span>
        </p>
    </div>

    <div class="branch-spotlight branch-<?= h($key) ?>">
        <div>
            <div class="sp-title">Your Branch</div>
            <div class="sp-name"><?= h($staff['branch_name'] ?? '—') ?></div>
        </div>
        <a class="btn" href="<?= BASE_URL ?>/portal/my-attendance.php">View My Attendance →</a>
    </div>

    <div class="section-heading"><h2>Quick Links</h2></div>
    <div class="quick-grid">
        <a class="quick-tile" href="<?= BASE_URL ?>/portal/my-attendance.php">
            <span class="qt-icon">📅</span>
            <span class="qt-title">My Attendance</span>
            <span class="qt-desc">Your duty history at a glance</span>
        </a>
    </div>
    <?php
}

require __DIR__ . '/includes/footer.php';