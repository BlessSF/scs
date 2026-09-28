<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/staff/list.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM staff WHERE id = ?');
$stmt->execute([$id]);
$staff = $stmt->fetch();

if (!$staff) {
    flash('error', 'Staff member not found.');
    redirect('/staff/list.php');
}

// Check for anything still attached to this staff member. Attendance and
// deductions cascade-delete automatically, but we don't want to silently
// wipe historical service-charge / payroll records — block the delete and
// point the admin at Inactive status instead.
$stmt = $pdo->prepare('SELECT COUNT(*) FROM duty_attendance WHERE staff_id = ?');
$stmt->execute([$id]);
$attendanceCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM deductions WHERE staff_id = ?');
$stmt->execute([$id]);
$deductionCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT username FROM users WHERE staff_id = ?');
$stmt->execute([$id]);
$loginUser = $stmt->fetch();

if ($attendanceCount > 0 || $deductionCount > 0) {
    $parts = [];
    if ($attendanceCount) $parts[] = "$attendanceCount duty attendance record(s)";
    if ($deductionCount) $parts[] = "$deductionCount deduction record(s)";
    flash('error', 'Cannot delete "' . $staff['full_name'] . '" — it still has ' . implode(', ', $parts) .
        ' tied to it. Set the staff member to Resigned / Inactive instead to preserve their history.');
    redirect('/staff/list.php');
}

try {
    if ($loginUser) {
        // Login account has no service-charge history of its own; safe to
        // drop along with the staff record (FK is ON DELETE SET NULL anyway).
        $pdo->prepare('DELETE FROM users WHERE staff_id = ?')->execute([$id]);
    }
    $stmt = $pdo->prepare('DELETE FROM staff WHERE id = ?');
    $stmt->execute([$id]);
    flash('success', 'Staff member "' . $staff['full_name'] . '" deleted.');
} catch (PDOException $e) {
    flash('error', 'Could not delete this staff member: ' . $e->getMessage());
}

redirect('/staff/list.php');
