<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_login();

$user = current_user();
if ($user['role'] === 'admin') { redirect('/dashboard.php'); }
$staffId = $user['staff_id'];

$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$start = sprintf('%04d-%02d-01', $year, $month);
$end   = date('Y-m-t', strtotime($start));

$sc = staff_total_sc($pdo, $staffId, $start, $end);

$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$pageTitle = 'My Attendance';
require __DIR__ . '/../includes/header.php';
?>
<h1>My Attendance</h1>
<div class="card">
    <div class="actions" style="justify-content:space-between; align-items:center;">
        <a class="btn btn-sm btn-secondary" href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>">&larr; Prev</a>
        <h2 style="margin:0;"><?= date('F Y', strtotime($start)) ?></h2>
        <a class="btn btn-sm btn-secondary" href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>">Next &rarr;</a>
    </div>

    <table style="margin-top:16px;">
        <thead><tr><th>Date</th><th class="text-right">Status</th></tr></thead>
        <tbody>
        <?php foreach ($sc['days'] as $d): ?>
            <tr>
                <td><?= h(date('D, M j', strtotime($d['date']))) ?></td>
                <td class="text-right"><span class="badge badge-regular">On Duty</span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$sc['days']): ?>
            <tr><td colspan="2" class="muted">No duty recorded this month.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
