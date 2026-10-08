<?php
/**
 * Shared helper + business logic functions.
 * Include AFTER config/config.php.
 */

/**
 * Format a raw number as a currency string using the configured symbol.
 *
 * @param float|int|string $n
 * @return string
 */
function money($n) {
    $symbol = get_setting('currency_symbol', '₱');
    return $symbol . number_format((float)$n, 2);
}

/**
 * HTML-escape a value for safe output.
 *
 * @param string $s
 * @return string
 */
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect to a path relative to BASE_URL and stop execution.
 *
 * @param string $path
 * @return void
 */
function redirect($path) {
    header('Location: ' . BASE_URL . $path);
    exit;
}

/**
 * Queue a one-time flash message for the next page load.
 *
 * @param string $type    'success' | 'error' | 'info'
 * @param string $message
 * @return void
 */
function flash($type, $message) {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Pop and return all queued flash messages.
 *
 * @return array<int, array{type: string, message: string}>
 */
function get_flashes() {
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/**
 * Read one setting from the settings table (cached per request).
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function get_setting($key, $default = null) {
    global $pdo;
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

/**
 * Create or update one setting.
 *
 * @param string $key
 * @param string $value
 * @return void
 */
function set_setting($key, $value) {
    global $pdo;
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

/**
 * Map a branch name to its design identity key (dois, stella, pub, hero,
 * dnd, commi, or default). Matches are case-insensitive substring matches,
 * so "Dois Bar", "DOIS", etc. all resolve correctly.
 *
 * @param string $name
 * @return string
 */
function branch_theme_key($name) {
    $n = strtolower(trim((string)$name));
    $map = [
        'dois'   => 'dois',
        'stella' => 'stella',
        'pub'    => 'pub',
        'hero'   => 'hero',
        'dnd'    => 'dnd',
        'commi'  => 'commi',
    ];
    foreach ($map as $needle => $key) {
        if (strpos($n, $needle) !== false) return $key;
    }
    return 'default';
}

/**
 * Hex colors for a branch theme key — used for exports (Excel/print/PDF)
 * where CSS variables aren't available. Keep in sync with the --br-*
 * variables in assets/css/style.css.
 *
 * @param string $key
 * @return array{solid: string, dark: string, text: string}
 */
function branch_theme_colors($key) {
    $map = [
        'dois'    => ['solid' => '#ff8a1e', 'dark' => '#c9600a', 'text' => '#ffffff'],
        'stella'  => ['solid' => '#1fa24d', 'dark' => '#0f6e33', 'text' => '#ffffff'],
        'pub'     => ['solid' => '#ff4d2e', 'dark' => '#b32c14', 'text' => '#ffffff'],
        'hero'    => ['solid' => '#101114', 'dark' => '#c2201c', 'text' => '#ffffff'],
        'dnd'     => ['solid' => '#2e7d32', 'dark' => '#1b5e20', 'text' => '#ffffff'],
        'commi'   => ['solid' => '#9aa1a0', 'dark' => '#6b706f', 'text' => '#2a2f2c'],
        'default' => ['solid' => '#146c5b', 'dark' => '#0c4a3e', 'text' => '#ffffff'],
    ];
    return $map[$key] ?? $map['default'];
}

/**
 * All branches, optionally limited to active ones.
 * Ordered so a sub-branch always appears immediately after its parent
 * (top-level branches sorted by name, sub-branches sorted by name within
 * their parent), which keeps dropdowns and lists grouped sensibly.
 *
 * @param bool $activeOnly
 * @return array
 */
function get_branches($activeOnly = true) {
    global $pdo;
    $sql = 'SELECT b.*, p.name AS parent_name
            FROM branches b
            LEFT JOIN branches p ON p.id = b.parent_branch_id'
        . ($activeOnly ? ' WHERE b.is_active = 1' : '')
        . ' ORDER BY COALESCE(p.name, b.name), (b.parent_branch_id IS NOT NULL), b.name';
    return $pdo->query($sql)->fetchAll();
}

/**
 * Top-level branches only (not sub-branches) — used to decide which
 * branches are allowed to have a sub-branch created under them.
 *
 * @param bool $activeOnly
 * @return array
 */
function get_top_level_branches($activeOnly = true) {
    global $pdo;
    $sql = 'SELECT * FROM branches WHERE parent_branch_id IS NULL'
        . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY name';
    return $pdo->query($sql)->fetchAll();
}

/**
 * Sub-branches of one branch.
 *
 * @param int  $parentId
 * @param bool $activeOnly
 * @return array
 */
function get_sub_branches($parentId, $activeOnly = true) {
    global $pdo;
    $sql = 'SELECT * FROM branches WHERE parent_branch_id = ?'
        . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$parentId]);
    return $stmt->fetchAll();
}

/**
 * Display label for a branch in a flat dropdown — indents sub-branches
 * so their relationship to the parent branch is visible at a glance.
 *
 * @param array $branch  a row from get_branches()/visible_branches()
 * @return string
 */
function branch_option_label($branch) {
    return (!empty($branch['parent_branch_id']) ? '— ' : '') . $branch['name'];
}

/**
 * Fetch a single branch by id.
 *
 * @param int $id
 * @return array|false
 */
/**
 * Backfill a hidden staff member onto every already-existing duty day
 * (every branch, past and present) that they're not already attached to.
 *
 * New duty days are already handled automatically going forward (see
 * duty/day.php's auto-add), but a duty day saved BEFORE this staff
 * member existed or was marked hidden never got them inserted. This
 * closes that gap the moment a staff member is saved as hidden+active,
 * so nobody has to remember to run a manual SQL backfill again.
 *
 * Safe to call repeatedly -- INSERT IGNORE + the uniq_day_staff unique
 * key means already-attached days are silently skipped.
 *
 * @param PDO $pdo
 * @param int $staffId
 * @return void
 */
function backfill_hidden_staff_duty_attendance($pdo, $staffId) {
    $s = $pdo->prepare('SELECT branch_id, excluded_days, counted_months FROM staff WHERE id = ?');
    $s->execute([$staffId]);
    $row = $s->fetch();
    if (!$row) return;
    $excluded = array_filter(array_map('trim', explode(',', $row['excluded_days'] ?? '')), 'strlen');
    // Optional month limit, e.g. "2026-08,2026-09". Empty = count every month.
    $months = array_values(array_filter(array_map('trim', explode(',', $row['counted_months'] ?? '')), 'strlen'));

    // Branches this owner is assigned to: home branch + "Also assign to these branches".
    $bs = $pdo->prepare('SELECT branch_id FROM staff_branches WHERE staff_id = ?');
    $bs->execute([$staffId]);
    $branchIds = array_map('intval', $bs->fetchAll(PDO::FETCH_COLUMN));
    $branchIds[] = (int)$row['branch_id'];
    $branchIds = array_values(array_unique($branchIds));
    $phB = implode(',', array_fill(0, count($branchIds), '?'));

    // 1) Remove stored rows on branches they are no longer assigned to.
    $pdo->prepare("DELETE da FROM duty_attendance da
        JOIN duty_days dd ON dd.id = da.duty_day_id
        WHERE da.staff_id = ? AND dd.branch_id NOT IN ($phB)")
        ->execute(array_merge([$staffId], $branchIds));

    // 2) Remove stored rows on excluded weekdays (DAYOFWEEK: 1=Sun ... 7=Sat; we store 0=Sun).
    if ($excluded) {
        $mysqlDays = array_map(function($d) { return (int)$d + 1; }, $excluded);
        $phD = implode(',', array_fill(0, count($mysqlDays), '?'));
        $pdo->prepare("DELETE da FROM duty_attendance da
            JOIN duty_days dd ON dd.id = da.duty_day_id
            WHERE da.staff_id = ? AND DAYOFWEEK(dd.duty_date) IN ($phD)")
            ->execute(array_merge([$staffId], $mysqlDays));
    }

    // 2b) Remove stored rows in months this owner is NOT counted for.
    if ($months) {
        $phM = implode(',', array_fill(0, count($months), '?'));
        $pdo->prepare("DELETE da FROM duty_attendance da
            JOIN duty_days dd ON dd.id = da.duty_day_id
            WHERE da.staff_id = ? AND DATE_FORMAT(dd.duty_date, '%Y-%m') NOT IN ($phM)")
            ->execute(array_merge([$staffId], $months));
    }

    // 3) Add rows for every duty day in assigned branches that isn't an excluded weekday.
    $sql = "INSERT IGNORE INTO duty_attendance (duty_day_id, staff_id)
            SELECT dd.id, ? FROM duty_days dd WHERE dd.branch_id IN ($phB)";
    $params = array_merge([$staffId], $branchIds);
    if ($excluded) {
        $sql .= " AND DAYOFWEEK(dd.duty_date) NOT IN ($phD)";
        $params = array_merge($params, $mysqlDays);
    }
    if ($months) {
        $sql .= " AND DATE_FORMAT(dd.duty_date, '%Y-%m') IN ($phM)";
        $params = array_merge($params, $months);
    }
    $pdo->prepare($sql)->execute($params);
}

function get_branch($id) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM branches WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * The id of the "Shared" branch (owners / other admins), used to hold
 * hidden staff who aren't tied to one specific operating branch. Their
 * cut still counts in every branch's periods (see period_summary()) --
 * this branch just groups them for management on the Staff page.
 *
 * Returns 0 if the "Shared" branch hasn't been created yet (run
 * database/add_shared_branch.sql).
 *
 * @return int
 */
function shared_branch_id() {
    global $pdo;
    static $id = null;
    if ($id === null) {
        $stmt = $pdo->query("SELECT id FROM branches WHERE LOWER(name) = 'shared' LIMIT 1");
        $id = (int)($stmt->fetchColumn() ?: 0);
    }
    return $id;
}

/**
 * Branches the current user is allowed to see/pick from.
 * Admins (and unrestricted cashier accounts) get every branch;
 * a branch-locked account gets its own branch plus any of its
 * sub-branches (e.g. an "H" login also sees "H Bar").
 *
 * @param bool $activeOnly
 * @return array
 */
function visible_branches($activeOnly = true) {
    global $pdo;
    $ids = accessible_branch_ids();
    if ($ids === null) return get_branches($activeOnly);
    if (!$ids) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT b.*, p.name AS parent_name
            FROM branches b
            LEFT JOIN branches p ON p.id = b.parent_branch_id
            WHERE b.id IN ($placeholders)" . ($activeOnly ? ' AND b.is_active = 1' : '')
        . ' ORDER BY (b.parent_branch_id IS NOT NULL), b.name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

/**
 * The login account tied to a branch (role = cashier, branch_id = $id),
 * or null if that branch doesn't have one yet.
 *
 * @param int $branchId
 * @return array|null
 */
function get_branch_account($branchId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE branch_id = ? AND role = 'cashier'");
    $stmt->execute([$branchId]);
    $u = $stmt->fetch();
    return $u ?: null;
}

/**
 * Turn a branch name into a unique, URL-safe login username
 * like "branch-dois-bar", avoiding collisions with existing usernames.
 *
 * @param string $branchName
 * @param int    $excludeUserId  a user id to ignore when checking for collisions (for renames)
 * @return string
 */
function generate_branch_username($branchName, $excludeUserId = 0) {
    global $pdo;
    $slug = strtolower(trim($branchName));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    if ($slug === '') $slug = 'branch';
    $base = 'branch-' . $slug;

    $username = $base;
    $n = 2;
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $stmt->execute([$username, $excludeUserId]);
        if (!$stmt->fetch()) break;
        $username = $base . '-' . $n;
        $n++;
    }
    return $username;
}

/**
 * Create the login account for a newly-created branch.
 * Returns the plaintext password (shown once) alongside the username.
 *
 * @param int    $branchId
 * @param string $branchName
 * @return array{username: string, password: string}
 */
function create_branch_account($branchId, $branchName) {
    global $pdo;
    $username = generate_branch_username($branchName);
    $password = substr(bin2hex(random_bytes(5)), 0, 10);

    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password, role, branch_id, staff_id, full_name, is_active)
         VALUES (?, ?, ?, ?, NULL, ?, 1)'
    );
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'cashier', $branchId, $branchName . ' Account']);

    return ['username' => $username, 'password' => $password];
}

/**
 * Extra branch ids (beyond staff.branch_id) a staff member is assigned to
 * via the staff_branches table — e.g. someone whose home branch is "Hero
 * Breakfast To Bar" but who also works shifts at "H-Bar".
 *
 * @param int $staffId
 * @return int[]
 */
function get_staff_branch_ids($staffId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT branch_id FROM staff_branches WHERE staff_id = ?');
    $stmt->execute([$staffId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Replace a staff member's extra branch assignments. Their home branch
 * (staff.branch_id) is always included even if it isn't passed in, so a
 * staff member is never "missing" from their own home branch's roster.
 *
 * @param int   $staffId
 * @param int[] $branchIds       every branch this staff should appear in
 * @param int   $homeBranchId    their staff.branch_id — always kept
 * @return void
 */
function set_staff_branches($staffId, array $branchIds, $homeBranchId = null) {
    global $pdo;
    $branchIds = array_values(array_unique(array_map('intval', array_filter($branchIds))));
    if ($homeBranchId && !in_array((int)$homeBranchId, $branchIds, true)) {
        $branchIds[] = (int)$homeBranchId;
    }
    $pdo->prepare('DELETE FROM staff_branches WHERE staff_id = ?')->execute([$staffId]);
    if ($branchIds) {
        $ins = $pdo->prepare('INSERT IGNORE INTO staff_branches (staff_id, branch_id) VALUES (?, ?)');
        foreach ($branchIds as $bid) {
            $ins->execute([$staffId, $bid]);
        }
    }
}

/**
 * Every branch name a staff member is visible under (home branch first,
 * then any extra branches from staff_branches), for display as pills.
 *
 * @param int      $staffId
 * @param int|null $homeBranchId
 * @return string[]
 */
function staff_branch_names($staffId, $homeBranchId = null) {
    global $pdo;
    $ids = get_staff_branch_ids($staffId);
    if ($homeBranchId && !in_array((int)$homeBranchId, $ids, true)) {
        array_unshift($ids, (int)$homeBranchId);
    }
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name FROM branches WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $byId = [];
    foreach ($stmt->fetchAll() as $r) { $byId[(int)$r['id']] = $r['name']; }
    $names = [];
    foreach ($ids as $bid) { if (isset($byId[$bid])) $names[] = $byId[$bid]; }
    return $names;
}

/**
 * Active staff visible in a branch — anyone whose home branch is this
 * branch, OR who was additionally assigned to it via staff_branches
 * (e.g. a Hero staff member also picked up shifts at H-Bar).
 *
 * @param int  $branchId
 * @param bool $activeOnly
 * @return array
 */
function staff_for_branch($branchId, $activeOnly = true) {
    global $pdo;
    $sql = "SELECT DISTINCT s.* FROM staff s
            LEFT JOIN staff_branches sb ON sb.staff_id = s.id
            WHERE (s.branch_id = ? OR sb.branch_id = ?)
            AND s.is_hidden = 0"
        . ($activeOnly ? ' AND s.is_active = 1' : '')
        . ' ORDER BY s.full_name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$branchId, $branchId]);
    return $stmt->fetchAll();
}

/**
 * Count of distinct active staff visible in a branch (home branch or
 * shared in via staff_branches) — used on the dashboard branch cards.
 *
 * @param int $branchId
 * @return int
 */
function staff_active_count_for_branch($branchId) {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT s.id) FROM staff s
         LEFT JOIN staff_branches sb ON sb.staff_id = s.id
         WHERE s.is_active = 1 AND (s.branch_id = ? OR sb.branch_id = ?)"
    );
    $stmt->execute([$branchId, $branchId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Active staff from OTHER branches who can be ticked as "visiting" on this
 * branch's duty entry (e.g. a Stella staff member sent to help at Hero for
 * the day). Excludes anyone already on this branch's own roster (home branch
 * or assigned via staff_branches) and hidden owner/admin staff.
 *
 * @param int $branchId
 * @return array rows: staff columns + home_branch_name
 */
function staff_visiting_candidates($branchId) {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT s.*, b.name AS home_branch_name
         FROM staff s
         JOIN branches b ON b.id = s.branch_id
         WHERE s.is_active = 1 AND s.is_hidden = 0
           AND s.branch_id <> ?
           AND NOT EXISTS (SELECT 1 FROM staff_branches sb WHERE sb.staff_id = s.id AND sb.branch_id = ?)
         ORDER BY b.name, s.full_name"
    );
    $stmt->execute([$branchId, $branchId]);
    return $stmt->fetchAll();
}

/**
 * Which of THIS branch's own staff are already on duty at a DIFFERENT branch
 * on the same date and shift? Used on the duty entry page to flag
 * "At Hero today" next to a staff member's name and to pre-tick them, so a
 * staff member sent elsewhere is still credited at their home branch.
 *
 * @param int    $branchId
 * @param string $date   Y-m-d
 * @param string $shift  'regular' | 'bar_night'
 * @return array<int,string>  staff_id => "Hero, H Bar" (names of the other branches)
 */
function staff_on_duty_elsewhere($branchId, $date, $shift) {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT da.staff_id, GROUP_CONCAT(DISTINCT b.name ORDER BY b.name SEPARATOR ', ') AS at_branches
         FROM duty_attendance da
         JOIN duty_days dd ON dd.id = da.duty_day_id
         JOIN branches b   ON b.id = dd.branch_id
         JOIN staff s      ON s.id = da.staff_id
         WHERE dd.duty_date = ? AND dd.shift = ? AND dd.branch_id <> ?
           AND s.is_hidden = 0
           AND (s.branch_id = ? OR EXISTS (SELECT 1 FROM staff_branches sb WHERE sb.staff_id = s.id AND sb.branch_id = ?))
         GROUP BY da.staff_id"
    );
    $stmt->execute([$date, $shift, $branchId, $branchId, $branchId]);
    $map = [];
    foreach ($stmt->fetchAll() as $r) { $map[(int)$r['staff_id']] = $r['at_branches']; }
    return $map;
}

/**
 * Number of staff on duty for a given duty_day id.
 *
 * @param PDO $pdo
 * @param int $dutyDayId
 * @return int
 */
function staff_count_for_day($pdo, $dutyDayId) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM (' . effective_attendance_sql() . ') e WHERE duty_day_id = ?');
    $stmt->execute([$dutyDayId]);
    return (int)$stmt->fetchColumn();
}

/**
 * SQL for the "effective" attendance list: every stored duty_attendance row,
 * PLUS every active hidden (owner/admin) staff member on the duty days of the
 * branches they are ASSIGNED to (home branch + "Also assign to these
 * branches"), except weekdays listed in their staff.excluded_days
 * (0=Sun ... 6=Sat).
 *
 * Deriving this here (instead of depending on rows inserted earlier) means
 * owners are always computed like any other staff -- even for days saved
 * before the owner was added -- and are always counted in the divisor that
 * splits each day's service charge. Stored rows for hidden staff are also
 * filtered by the same branch/weekday rules, so stale rows can never count.
 */
function effective_attendance_sql() {
    return "SELECT da0.duty_day_id, da0.staff_id
            FROM duty_attendance da0
            JOIN duty_days dd0 ON dd0.id = da0.duty_day_id
            JOIN staff st0 ON st0.id = da0.staff_id
            WHERE st0.is_hidden = 0
               OR (
                    (st0.branch_id = dd0.branch_id
                     OR EXISTS (SELECT 1 FROM staff_branches sb0 WHERE sb0.staff_id = st0.id AND sb0.branch_id = dd0.branch_id))
                AND (st0.excluded_days IS NULL
                     OR st0.excluded_days = ''
                     OR FIND_IN_SET(CAST(DAYOFWEEK(dd0.duty_date) - 1 AS BINARY), CAST(REPLACE(st0.excluded_days, ' ', '') AS BINARY)) = 0)
                AND (st0.counted_months IS NULL
                     OR st0.counted_months = ''
                     OR FIND_IN_SET(CAST(DATE_FORMAT(dd0.duty_date, '%Y-%m') AS BINARY), CAST(REPLACE(st0.counted_months, ' ', '') AS BINARY)) > 0)
               )
            UNION
            SELECT dd2.id AS duty_day_id, hs.id AS staff_id
            FROM duty_days dd2
            JOIN staff hs ON hs.is_hidden = 1 AND hs.is_active = 1
            WHERE (hs.branch_id = dd2.branch_id
                   OR EXISTS (SELECT 1 FROM staff_branches sb2 WHERE sb2.staff_id = hs.id AND sb2.branch_id = dd2.branch_id))
              AND (hs.excluded_days IS NULL
                   OR hs.excluded_days = ''
                   OR FIND_IN_SET(CAST(DAYOFWEEK(dd2.duty_date) - 1 AS BINARY), CAST(REPLACE(hs.excluded_days, ' ', '') AS BINARY)) = 0)
              AND (hs.counted_months IS NULL
                   OR hs.counted_months = ''
                   OR FIND_IN_SET(CAST(DATE_FORMAT(dd2.duty_date, '%Y-%m') AS BINARY), CAST(REPLACE(hs.counted_months, ' ', '') AS BINARY)) > 0)";
}

function staff_total_sc($pdo, $staffId, $startDate, $endDate, $branchId = null, $shift = null) {
    $eff = effective_attendance_sql();
    $sql = "SELECT dd.duty_date, dd.total_amount, cnt.staff_count
            FROM ($eff) da
            JOIN duty_days dd ON dd.id = da.duty_day_id
            JOIN (
                SELECT duty_day_id, COUNT(*) AS staff_count
                FROM ($eff) e
                GROUP BY duty_day_id
            ) cnt ON cnt.duty_day_id = dd.id
            WHERE da.staff_id = ?
              AND dd.duty_date BETWEEN ? AND ?" . ($branchId ? " AND dd.branch_id = ?" : "") . ($shift ? " AND dd.shift = ?" : "");
    $stmt = $pdo->prepare($sql);
    $params = [$staffId, $startDate, $endDate];
    if ($branchId) $params[] = $branchId;
    if ($shift) $params[] = $shift;
    $stmt->execute($params);

    $total = 0.0;
    $days = [];
    foreach ($stmt->fetchAll() as $row) {
        $perStaff = $row['staff_count'] > 0 ? ((float)$row['total_amount'] / (int)$row['staff_count']) : 0.0;
        $total += $perStaff;
        $days[] = [
            'date'       => $row['duty_date'],
            'day_total'  => (float)$row['total_amount'],
            'staff_count'=> (int)$row['staff_count'],
            'per_staff'  => $perStaff,
        ];
    }
    return ['total' => $total, 'days' => $days];
}

/**
 * Overcost per employee = Employee Gross × rate
 *
 * Rate is computed per-period as: Budget ÷ Total Gross
 *
 * @param float $employeeGross
 * @param float $rate  Budget ÷ Total Gross for this period
 * @return float
 */
function period_overcost_per_employee($employeeGross, $rate) {
    return (float)$employeeGross * (float)$rate;
}

/**
 * Overcost summary for a period.
 *
 * Rate = Budget ÷ Total Gross (per-period, not from Settings).
 * The Overcost Reference card shows Total Gross ÷ Total Overcost
 * so the admin knows what the next period's rate could be.
 *
 * @param float $budget        Admin-entered budget for this period.
 * @param float $grossTotal    Total gross SC across all staff.
 * @param float $overcostTotal Sum of all per-employee overcost amounts.
 * @return array
 */
function period_overcost($budget, $grossTotal, $overcostTotal) {
    $budget        = (float)$budget;
    $grossTotal    = (float)$grossTotal;
    $overcostTotal = (float)$overcostTotal;
    $rate          = $budget > 0 ? $grossTotal / $budget : 0.0;
    $nextRate      = $overcostTotal > 0 ? $grossTotal / $overcostTotal : 0.0;
    return [
        'budget'        => $budget,
        'actual'        => $grossTotal,
        'overcost'      => $overcostTotal,
        'rate'          => $rate,
        'overcost_percent' => $nextRate,
    ];
}

/**
 * Full computed summary (gross, management share, deductions, net)
 * for every staff member who has at least one duty day within the period,
 * PLUS any staff who already have a deductions row for the period.
 *
 * @param PDO $pdo
 * @param int $periodId
 * @return array{period: array, rows: array, totals: array}|null
 */
function period_summary($pdo, $periodId, $shift = null) {
    $stmt = $pdo->prepare('SELECT * FROM periods WHERE id = ?');
    $stmt->execute([$periodId]);
    $period = $stmt->fetch();
    if (!$period) return null;

    // Staff of this period's branch who worked in this range OR already have a deduction row recorded.
    // When a shift filter is active, only staff with duty days on that shift are listed
    // (deduction-only rows are still included so nothing gets silently dropped).
    $dutySql = "SELECT da.staff_id FROM duty_attendance da
                JOIN duty_days dd ON dd.id = da.duty_day_id
                WHERE dd.duty_date BETWEEN ? AND ? AND dd.branch_id = ?" . ($shift ? " AND dd.shift = ?" : "");
    // All staff (including hidden) for SC total calculations.
    // Hidden staff (owners / other admins, typically homed in the "Shared"
    // branch) are auto-added to EVERY branch's duty days regardless of their
    // own home branch (see duty/day.php), so they're pulled into every
    // period's totals here too -- not just periods of their own home branch
    // -- as long as they're active. Everyone else stays scoped to this
    // period's own branch, as before.
    //
    // VISITING STAFF: someone whose home branch is a different branch but who
    // was ticked on THIS branch's duty days (e.g. a Stella staff member sent
    // to Hero for the day). They are counted in that day's divisor (see
    // effective_attendance_sql()), so their share must be paid out from THIS
    // branch's period -- otherwise it would silently disappear, since their
    // home branch's period only looks at its own duty days.
    $sql = "SELECT DISTINCT s.id, s.full_name, s.status, s.is_hidden,
                   s.branch_id AS home_branch_id, hb.name AS home_branch_name
            FROM staff s
            LEFT JOIN branches hb ON hb.id = s.branch_id
            WHERE (
                s.branch_id = ?
                AND s.id IN (
                    $dutySql
                    UNION
                    SELECT staff_id FROM deductions WHERE period_id = ?
                )
              )
              OR (
                s.is_hidden = 1 AND s.is_active = 1
                AND (s.branch_id = ?
                     OR EXISTS (SELECT 1 FROM staff_branches sbp WHERE sbp.staff_id = s.id AND sbp.branch_id = ?))
              )
              OR (
                s.is_hidden = 0 AND s.branch_id <> ?
                AND s.id IN ($dutySql)
              )
            ORDER BY s.full_name";
    $stmt = $pdo->prepare($sql);
    $params = [$period['branch_id'], $period['start_date'], $period['end_date'], $period['branch_id']];
    if ($shift) $params[] = $shift;
    $params[] = $periodId;
    $params[] = $period['branch_id']; // hidden staff: home branch match
    $params[] = $period['branch_id']; // hidden staff: staff_branches match
    $params[] = $period['branch_id']; // visiting staff: home branch is NOT this branch
    $params[] = $period['start_date']; $params[] = $period['end_date']; $params[] = $period['branch_id'];
    if ($shift) $params[] = $shift;
    $stmt->execute($params);
    $allStaffList  = $stmt->fetchAll();

    // Preload deductions for this period
    $stmt = $pdo->prepare('SELECT * FROM deductions WHERE period_id = ?');
    $stmt->execute([$periodId]);
    $deductionsByStaff = [];
    foreach ($stmt->fetchAll() as $d) {
        $deductionsByStaff[$d['staff_id']] = $d;
    }

    // Pass 1: each staff's Total SC / Gross, purely from duty entries -- this
    // also gives us the period's Gross total, which doubles as the automatic
    // "Actual/Gross Cost" for the Overcost calculation below.
    $staffData = [];
    $grossTotal = 0.0;
    foreach ($allStaffList as $s) {
        $sc = staff_total_sc($pdo, $s['id'], $period['start_date'], $period['end_date'], $period['branch_id'], $shift);
        $totalSc = $sc['total'];
        $mgmtShare = $totalSc * ((float)$period['management_share_percent'] / 100);
        $gross = $totalSc - $mgmtShare;
        $grossTotal += $gross;

        // Per-shift breakdown, always computed regardless of the active $shift filter,
        // so slips can show Regular Day SC / Bar Night SC / combined Total SC side by side.
        $scRegular  = staff_total_sc($pdo, $s['id'], $period['start_date'], $period['end_date'], $period['branch_id'], 'regular');
        $scBarNight = staff_total_sc($pdo, $s['id'], $period['start_date'], $period['end_date'], $period['branch_id'], 'bar_night');

        $staffData[] = [
            's' => $s, 'sc' => $sc, 'totalSc' => $totalSc, 'mgmtShare' => $mgmtShare, 'gross' => $gross,
            'scRegular' => $scRegular, 'scBarNight' => $scBarNight,
        ];
    }

    // Budget is stored in the period_budgets table (one row per period).
    $budgetRow = $pdo->prepare('SELECT budget FROM period_budgets WHERE period_id = ?');
    $budgetRow->execute([$period['id']]);
    $budget = (float)($budgetRow->fetchColumn() ?: 0);
    $rate = $budget > 0 ? $grossTotal / $budget : 0.0;

    $overcostTotalTemp = 0.0;
    foreach ($staffData as &$sd) {
        $sd['overcostCogs'] = period_overcost_per_employee($sd['gross'], $rate);
        $overcostTotalTemp += $sd['overcostCogs'];
    }
    unset($sd);
    $periodOvercost = period_overcost($budget, $grossTotal, $overcostTotalTemp);

    $rows = [];
    $totals = [
        'total_sc' => 0, 'management_share' => 0, 'gross' => 0,
        'damages_charges' => 0, 'cash_advance' => 0, 'overcost_cogs' => 0, 'net' => 0,
    ];

    foreach ($staffData as $sd) {
        $s = $sd['s'];
        // Hidden staff (Shared branch owners/admins) are computed and included
        // here exactly like everyone else -- full net + slip -- they're only
        // "hidden" from branch-facing views (duty entry checklists, branch
        // staff lists), not from this admin-only Periods summary.
        $totalSc = $sd['totalSc'];
        $mgmtShare = $sd['mgmtShare'];
        $gross = $sd['gross'];
        $sc = $sd['sc'];
        $scRegular = $sd['scRegular'];
        $scBarNight = $sd['scBarNight'];

        $d = $deductionsByStaff[$s['id']] ?? ['damages_charges' => 0, 'cash_advance' => 0, 'overcost_cogs' => 0, 'remarks' => '', 'received_by' => ''];
        $overcostCogs = $sd['overcostCogs'];
        $net = $gross - (float)$d['damages_charges'] - (float)$d['cash_advance'] - $overcostCogs;

        $rows[] = [
            'staff_id'         => $s['id'],
            'full_name'        => $s['full_name'],
            'status'           => $s['status'],
            'is_hidden'        => !empty($s['is_hidden']),
            'home_branch_id'   => (int)($s['home_branch_id'] ?? 0),
            'home_branch_name' => $s['home_branch_name'] ?? '',
            // Set only for a staff member whose home branch differs from this
            // period's branch (a visitor); null for everyone else.
            'visiting_from'    => (empty($s['is_hidden']) && (int)($s['home_branch_id'] ?? 0) !== (int)$period['branch_id'])
                                    ? ($s['home_branch_name'] ?? '') : null,
            'total_sc'         => $totalSc,
            'management_share' => $mgmtShare,
            'gross'            => $gross,
            'damages_charges'  => (float)$d['damages_charges'],
            'cash_advance'     => (float)$d['cash_advance'],
            'overcost_cogs'    => $overcostCogs,
            'remarks'          => $d['remarks'] ?? '',
            'received_by'      => $d['received_by'] ?? '',
            'net'              => $net,
            'days_worked'      => count($sc['days']),
            'regular_sc'          => $scRegular['total'],
            'regular_days'        => count($scRegular['days']),
            'bar_night_sc'        => $scBarNight['total'],
            'bar_night_days'      => count($scBarNight['days']),
            'combined_total_sc'   => $scRegular['total'] + $scBarNight['total'],
        ];

        $totals['total_sc']         += $totalSc;
        $totals['management_share'] += $mgmtShare;
        $totals['gross']            += $gross;
        $totals['damages_charges']  += (float)$d['damages_charges'];
        $totals['cash_advance']     += (float)$d['cash_advance'];
        $totals['overcost_cogs']    += $overcostCogs;
        $totals['net']              += $net;
    }

    return ['period' => $period, 'rows' => $rows, 'totals' => $totals, 'shift' => $shift, 'overcost' => $periodOvercost];
}

/**
 * Computed summary for a single staff member in a single period (used for the slip).
 *
 * @param PDO $pdo
 * @param int $periodId
 * @param int $staffId
 * @return array{period: array, row: array}|null
 */
function staff_period_slip($pdo, $periodId, $staffId, $shift = null) {
    $summary = period_summary($pdo, $periodId, $shift);
    if (!$summary) return null;
    foreach ($summary['rows'] as $row) {
        if ($row['staff_id'] == $staffId) {
            return ['period' => $summary['period'], 'row' => $row];
        }
    }
    return null;
}