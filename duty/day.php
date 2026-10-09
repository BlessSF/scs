<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role(['admin', 'cashier']);

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    flash('error', 'Invalid date.');
    redirect('/duty/calendar.php');
}

$branchId = scoped_branch_id($_GET['branch_id'] ?? ($_POST['branch_id'] ?? 0));
$branch = get_branch($branchId);
if (!$branch) {
    flash('error', 'Invalid branch.');
    redirect('/duty/calendar.php');
}
require_branch_access($branchId);

// Bar Night only applies to Friday (5) and Saturday (6); every other day
// only ever has a single Regular entry.
$dow = (int)date('w', strtotime($date));
$isBarNightDay = in_array($dow, [5, 6], true);
$shifts = $isBarNightDay ? ['regular', 'bar_night'] : ['regular'];
$shiftLabels = ['regular' => 'Regular', 'bar_night' => '🌙 Bar Night'];

$stmt = $pdo->prepare('SELECT * FROM duty_days WHERE duty_date = ? AND branch_id = ?');
$stmt->execute([$date, $branchId]);
$dutyDaysByShift = [];
foreach ($stmt->fetchAll() as $row) {
    $dutyDaysByShift[$row['shift'] ?? 'regular'] = $row;
}

$presentIdsByShift = [];
foreach ($dutyDaysByShift as $shift => $dd) {
    $s2 = $pdo->prepare('SELECT staff_id FROM duty_attendance WHERE duty_day_id = ?');
    $s2->execute([$dd['id']]);
    $presentIdsByShift[$shift] = array_column($s2->fetchAll(), 'staff_id');
}

// Includes staff whose home branch is this one, plus anyone additionally
// assigned here via staff_branches (e.g. a Hero staff member covering H-Bar).
$allStaff = staff_for_branch($branchId, true);
$allStaffIds = array_map('intval', array_column($allStaff, 'id'));

// "Staff visiting from other branches" has been removed: only this branch's
// own roster can be ticked on a duty day.
$visitingList = [];
$visitingIds  = [];
$validStaffIds = array_merge($allStaffIds, $visitingIds);

// Which of THIS branch's staff are already on duty at another branch the same
// day (per shift) -- shown as an "At Hero today" tag and pre-ticked on a new entry.
$elsewhereByShift = [];
foreach ($shifts as $sh) { $elsewhereByShift[$sh] = staff_on_duty_elsewhere($branchId, $date, $sh); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $postedAmounts = $_POST['total_amount'] ?? [];
    $postedNotes   = $_POST['notes'] ?? [];
    $postedPresent = $_POST['present'] ?? [];

    $totalsByShift = [];
    foreach ($shifts as $shift) {
        $amt = (float)($postedAmounts[$shift] ?? 0);
        if ($amt < 0) {
            $errors[] = ($shiftLabels[$shift] ?? $shift) . ': amount cannot be negative.';
        }
        $totalsByShift[$shift] = $amt;
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            foreach ($shifts as $shift) {
                $amount = $totalsByShift[$shift];
                $notes = trim($postedNotes[$shift] ?? '');
                $presentPost = array_values(array_unique(array_map('intval', $postedPresent[$shift] ?? [])));
                // Only staff actually offered on this page (this branch's roster or the visiting list).
                $presentPost = array_values(array_intersect($presentPost, $validStaffIds));

                $existing = $dutyDaysByShift[$shift] ?? null;

                // For Bar Night, skip creating a brand-new empty row if nothing
                // was actually entered (e.g. the cashier only filled Regular).
                if ($shift === 'bar_night' && !$existing && $amount <= 0 && !$presentPost && $notes === '') {
                    continue;
                }

                if ($existing) {
                    $u = $pdo->prepare('UPDATE duty_days SET total_amount=?, notes=? WHERE id=?');
                    $u->execute([$amount, $notes, $existing['id']]);
                    $dutyDayId = $existing['id'];
                    $pdo->prepare('DELETE FROM duty_attendance WHERE duty_day_id = ?')->execute([$dutyDayId]);
                } else {
                    $i = $pdo->prepare('INSERT INTO duty_days (branch_id, duty_date, shift, total_amount, notes) VALUES (?,?,?,?,?)');
                    $i->execute([$branchId, $date, $shift, $amount, $notes]);
                    $dutyDayId = (int)$pdo->lastInsertId();
                }

                $ins = $pdo->prepare('INSERT INTO duty_attendance (duty_day_id, staff_id) VALUES (?,?)');
                foreach ($presentPost as $sid) {
                    $ins->execute([$dutyDayId, $sid]);
                }

                // Auto-add hidden staff (owners/admin) to every duty day
                // Only owners assigned to THIS branch (home branch or "Also assign to these branches").
                $hsStmt = $pdo->prepare('SELECT s.id, s.excluded_days, s.counted_months, s.follow_staff_id FROM staff s
                    WHERE s.is_hidden = 1 AND s.is_active = 1
                      AND (s.branch_id = ? OR EXISTS (SELECT 1 FROM staff_branches sb WHERE sb.staff_id = s.id AND sb.branch_id = ?))');
                $hsStmt->execute([$branchId, $branchId]);
                $hiddenStaff = $hsStmt->fetchAll();
                $dutyDow = (int)date('w', strtotime($date)); // 0=Sun ... 6=Sat, same as staff.excluded_days
                foreach ($hiddenStaff as $hs) {
                    // Honor the owner's excluded weekdays (same rule as the backfill).
                    $excl = array_filter(array_map('trim', explode(',', $hs['excluded_days'] ?? '')), 'strlen');
                    if (in_array((string)$dutyDow, $excl, true)) continue;
                    // Honor the owner's month limit (e.g. only Aug & Sep 2026).
                    $cm = array_filter(array_map('trim', explode(',', $hs['counted_months'] ?? '')), 'strlen');
                    if ($cm && !in_array(date('Y-m', strtotime($date)), $cm, true)) continue;
                    // "Follow" mode: only count on days the followed staff member is ticked
                    // on THIS entry (or already worked that date at another branch).
                    if (!empty($hs['follow_staff_id'])) {
                        $fid = (int)$hs['follow_staff_id'];
                        $fol = in_array($fid, array_map('intval', $presentPost), true);
                        if (!$fol) {
                            $fq = $pdo->prepare('SELECT 1 FROM duty_attendance f JOIN duty_days fd ON fd.id = f.duty_day_id
                                                 WHERE f.staff_id = ? AND fd.duty_date = ? LIMIT 1');
                            $fq->execute([$fid, $date]);
                            $fol = (bool)$fq->fetchColumn();
                        }
                        if (!$fol) continue;
                    }
                    if (!in_array($hs['id'], $presentPost)) {
                        try { $ins->execute([$dutyDayId, $hs['id']]); } catch (PDOException $e) { /* already added */ }
                    }
                }
            }

            $pdo->commit();
            flash('success', 'Duty entry saved for ' . date('F j, Y', strtotime($date)) . '.');
            redirect('/duty/calendar.php?branch_id=' . $branchId . '&month=' . date('n', strtotime($date)) . '&year=' . date('Y', strtotime($date)));
        } catch (Exception $e) {
            $pdo->rollBack();
            if ($e instanceof PDOException && (int)$e->getCode() === 23000) {
                $errors[] = 'Could not save: it looks like this got submitted twice, or someone else saved this same day at the same time. Please review the checked staff below and try saving again.';
            } else {
                $errors[] = 'Could not save this entry. Please try again, and let an admin know if it keeps happening.';
            }
        }
    }
}

$pageTitle = 'Duty Entry — ' . date('M j, Y', strtotime($date));
require __DIR__ . '/../includes/header.php';
?>
<h1><?= date('l, F j, Y', strtotime($date)) ?></h1>
<p class="subtitle"><?= h($branch['name']) ?> &middot; Enter the total service charge collected for this day, then check off every staff member who was on duty. The amount is split evenly among everyone checked<?= $isBarNightDay ? ' &mdash; separately for Regular hours and Bar Night.' : '.' ?></p>

<?php foreach ($errors as $e): ?><div class="alert alert-error"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="branch_id" value="<?= $branchId ?>">

    <div class="<?= $isBarNightDay ? 'shift-grid' : '' ?>">
        <?php foreach ($shifts as $shift):
            $dutyDay   = $dutyDaysByShift[$shift] ?? null;
            $presentIds = $presentIdsByShift[$shift] ?? [];
            $listId    = 'staffList_' . $shift;
            $counterId = 'presentCount_' . $shift;
        ?>
        <div class="card shift-panel shift-panel-<?= h($shift) ?>">
            <?php if ($isBarNightDay): ?>
                <h2 class="shift-panel-title"><?= h($shiftLabels[$shift]) ?></h2>
            <?php endif; ?>

            <div class="form-row">
                <div>
                    <label for="total_amount_<?= $shift ?>">Total Service Charge<?= $isBarNightDay ? '' : ' for the Day' ?></label>
                    <input type="number" step="0.01" min="0" id="total_amount_<?= $shift ?>" name="total_amount[<?= $shift ?>]" value="<?= h($dutyDay['total_amount'] ?? '') ?>" <?= $shift === 'regular' ? 'required' : '' ?>>
                </div>
                <div>
                    <label for="notes_<?= $shift ?>">Notes (optional)</label>
                    <input type="text" id="notes_<?= $shift ?>" name="notes[<?= $shift ?>]" value="<?= h($dutyDay['notes'] ?? '') ?>">
                </div>
            </div>

            <div class="section-heading" style="margin-top:24px;">
                <h2 style="margin:0;">Staff On Duty</h2>
                <?php if (is_admin()): ?><span class="hint"><span id="<?= $counterId ?>"><?= count($presentIds) ?></span> of <?= count($allStaff) ?> selected</span><?php endif; ?>
            </div>

            <?php if ($allStaff): ?>
            <div class="actions" style="margin-bottom:14px;">
                <button type="button" class="btn btn-sm btn-secondary shift-select-all" data-target="<?= $listId ?>">Select All</button>
                <button type="button" class="btn btn-sm btn-secondary shift-select-none" data-target="<?= $listId ?>">Select None</button>
            </div>
            <?php endif; ?>

            <div class="staff-select-list" id="<?= $listId ?>" data-counter="<?= $counterId ?>">
                <?php foreach ($allStaff as $s):
                    $elsewhere = $elsewhereByShift[$shift][(int)$s['id']] ?? null;
                    // New regular-day entry: a staff member who is on duty at another branch
                    // today is still credited here, so pre-tick them (untick if not wanted).
                    $autoTick = ($elsewhere !== null && !$dutyDay && $shift === 'regular');
                    $checked = in_array($s['id'], $presentIds) || $autoTick; ?>
                    <label class="staff-row<?= $checked ? ' is-checked' : '' ?>">
                        <input type="checkbox" name="present[<?= $shift ?>][]" value="<?= $s['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                        <span class="sr-check" aria-hidden="true"></span>
                        <span class="sr-name"><?= h($s['full_name']) ?></span>
                        <?php if ($elsewhere !== null): ?>
                            <span class="badge badge-elsewhere" title="Also on duty at another branch today. Still credited here when ticked.">At <?= h($elsewhere) ?> today</span>
                        <?php endif; ?>
                        <span class="badge badge-<?= h($s['status']) ?>"><?= h(ucfirst(str_replace('_',' ',$s['status']))) ?></span>
                    </label>
                <?php endforeach; ?>
                <?php if (!$allStaff): ?>
                    <p class="muted">No active staff in this branch. <a href="<?= BASE_URL ?>/staff/form.php?branch_id=<?= $branchId ?>">Add staff first</a>.</p>
                <?php endif; ?>
            </div>

        </div>
        <?php endforeach; ?>
    </div>

    <div class="sticky-save-bar">
        <div class="actions">
            <button type="submit" class="btn">Save Day</button>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>/duty/calendar.php?branch_id=<?= $branchId ?>&month=<?= date('n', strtotime($date)) ?>&year=<?= date('Y', strtotime($date)) ?>">Back to Calendar</a>
        </div>
    </div>
</form>

<script>
(function () {
    document.querySelectorAll('.staff-select-list').forEach(function (list) {
        var counter = document.getElementById(list.getAttribute('data-counter'));
        var boxes = Array.prototype.slice.call(list.querySelectorAll('input[type=checkbox]'));

        function updateCount() {
            if (counter) counter.textContent = boxes.filter(function (b) { return b.checked; }).length;
        }

        boxes.forEach(function (box) {
            box.addEventListener('change', function () {
                box.closest('.staff-row').classList.toggle('is-checked', box.checked);
                updateCount();
            });
        });

        list._scsUpdateCount = updateCount;
        list._scsBoxes = boxes;
    });

    document.querySelectorAll('.shift-select-all').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var list = document.getElementById(btn.getAttribute('data-target'));
            if (!list || !list._scsBoxes) return;
            list._scsBoxes.forEach(function (b) { b.checked = true; b.closest('.staff-row').classList.add('is-checked'); });
            list._scsUpdateCount();
        });
    });
    document.querySelectorAll('.shift-select-none').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var list = document.getElementById(btn.getAttribute('data-target'));
            if (!list || !list._scsBoxes) return;
            list._scsBoxes.forEach(function (b) { b.checked = false; b.closest('.staff-row').classList.remove('is-checked'); });
            list._scsUpdateCount();
        });
    });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>