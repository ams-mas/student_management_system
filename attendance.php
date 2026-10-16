<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_roles(['super_admin', 'head_teacher', 'administrative_staff', 'teacher']);
require_once __DIR__ . '/config/database.php';
$pdo = database_connection();
$role = current_role();
$userId = (int)$_SESSION['user_id'];
$year = $pdo->query('SELECT id, name FROM school_years WHERE is_active = 1 AND is_closed = 0 ORDER BY id DESC LIMIT 1')->fetch() ?: null;
$sections = [];
if ($year) {
    if ($role === 'teacher') {
        $q = $pdo->prepare('SELECT cs.id, cs.grade, cs.section_name FROM class_sections cs JOIN teachers t ON t.id = cs.teacher_id WHERE t.user_id = ? AND cs.school_year_id = ? AND cs.is_active = 1 ORDER BY cs.grade, cs.section_name');
        $q->execute([$userId, (int)$year['id']]);
    } else {
        $q = $pdo->prepare('SELECT id, grade, section_name FROM class_sections WHERE school_year_id = ? AND is_active = 1 ORDER BY grade, section_name');
        $q->execute([(int)$year['id']]);
    }
    $sections = $q->fetchAll();
}
$selectedSectionId = filter_var($_POST['section_id'] ?? $_GET['section_id'] ?? null, FILTER_VALIDATE_INT) ?: (int)($sections[0]['id'] ?? 0);
$selectedDate = trim((string)($_POST['attendance_date'] ?? $_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) || !strtotime($selectedDate)) $selectedDate = date('Y-m-d');
$success = '';
$error = '';
function attendance_h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$allowedSectionIds = array_map(static fn($s) => (int)$s['id'], $sections);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(400); exit('درخواست معتبر نیست. صفحه را تازه‌سازی کنید.'); }
    try {
        if (!$year || !in_array($selectedSectionId, $allowedSectionIds, true)) throw new RuntimeException('به این صنف دسترسی ندارید یا سال تحصیلی فعال نیست.');
        $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDate);
        if (!$dateValue || $dateValue->format('Y-m-d') !== $selectedDate) throw new RuntimeException('تاریخ حاضری معتبر نیست.');
        $rosterStmt = $pdo->prepare("SELECT s.id, s.student_number, s.first_name, s.last_name FROM student_enrollments e JOIN students s ON s.id=e.student_id WHERE e.class_section_id=? AND e.school_year_id=? AND e.is_current=1 AND e.status='enrolled' AND s.is_active=1 ORDER BY s.last_name,s.first_name");
        $rosterStmt->execute([$selectedSectionId, (int)$year['id']]);
        $roster = $rosterStmt->fetchAll();
        $statuses = $_POST['attendance'] ?? [];
        if (!is_array($statuses)) throw new RuntimeException('اطلاعات حاضری معتبر نیست.');
        $save = $pdo->prepare("INSERT INTO student_attendance (school_year_id,class_section_id,student_id,attendance_date,status,recorded_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE class_section_id=VALUES(class_section_id),school_year_id=VALUES(school_year_id),status=VALUES(status),recorded_by=VALUES(recorded_by),updated_at=CURRENT_TIMESTAMP");
        $pdo->beginTransaction();
        foreach ($roster as $student) {
            $studentId = (int)$student['id'];
            $status = (string)($statuses[(string)$studentId] ?? 'present');
            if (!in_array($status, ['present','absent','late','excused'], true)) throw new RuntimeException('یکی از وضعیت‌های حاضری معتبر نیست.');
            $save->execute([(int)$year['id'],$selectedSectionId,$studentId,$selectedDate,$status,$userId]);
        }
        $pdo->commit();
        $success = 'حاضری صنف با موفقیت ذخیره شد.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'ذخیره حاضری انجام نشد. پایگاه داده را بررسی کنید.';
        if (!$e instanceof RuntimeException) error_log('Attendance save: '.$e->getMessage());
    }
}
$students = [];
$attendanceMap = [];
if ($year && in_array($selectedSectionId, $allowedSectionIds, true)) {
    $rosterStmt = $pdo->prepare("SELECT s.id,s.student_number,s.first_name,s.last_name FROM student_enrollments e JOIN students s ON s.id=e.student_id WHERE e.class_section_id=? AND e.school_year_id=? AND e.is_current=1 AND e.status='enrolled' AND s.is_active=1 ORDER BY s.last_name,s.first_name");
    $rosterStmt->execute([$selectedSectionId,(int)$year['id']]);
    $students = $rosterStmt->fetchAll();
    $savedStmt = $pdo->prepare('SELECT student_id,status FROM student_attendance WHERE class_section_id=? AND attendance_date=?');
    $savedStmt->execute([$selectedSectionId,$selectedDate]);
    foreach ($savedStmt->fetchAll() as $row) $attendanceMap[(int)$row['student_id']] = (string)$row['status'];
}
$statusLabels = ['present'=>'حاضر','absent'=>'غایب','late'=>'ناوقت','excused'=>'معذور'];
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>حاضری شاگردان</title><link rel="stylesheet" href="assets/css/style.css"><style>.attendance-table{width:100%;border-collapse:separate;border-spacing:0;min-width:540px}.attendance-table th,.attendance-table td{padding:10px;border-bottom:1px solid var(--line);text-align:right}.attendance-table th{background:#eef2f8}.attendance-table select,.attendance-controls select,.attendance-controls input{font:inherit;padding:9px;border:1px solid var(--line);border-radius:8px}.attendance-controls{display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin-bottom:18px;padding:16px;background:#f8faff;border:1px solid var(--line);border-radius:12px}.attendance-controls label{flex:1;min-width:180px}.attendance-controls select,.attendance-controls input,.attendance-table select{min-height:46px;font-size:16px}.attendance-table tbody tr:nth-child(even){background:#fafcff}.attendance-table td:last-child{min-width:180px}.attendance-table th{position:sticky;top:0;z-index:1}.attendance-controls label{display:grid;gap:6px;font-weight:700}.attendance-wrap{overflow-x:auto}.attendance-notice{padding:12px;border-radius:9px;margin-bottom:14px}.attendance-ok{background:#dcfce7;color:#166534}.attendance-error{background:#fee2e2;color:#991b1b}</style></head><body class="profile-page"><div class="admin-layout"><?php require __DIR__ . '/includes/sidebar.php'; ?><main class="admin-main"><?php require __DIR__ . '/includes/topbar.php'; ?><header class="page-header"><p class="eyebrow">بخش آموزشی</p><h1>حاضری شاگردان</h1><p class="muted">ثبت و ویرایش حاضری شاگردان صنف‌های مجاز.</p></header>
<?php if ($success!==''): ?><div class="attendance-notice attendance-ok"><?=attendance_h($success)?></div><?php endif; ?><?php if ($error!==''): ?><div class="attendance-notice attendance-error"><?=attendance_h($error)?></div><?php endif; ?>
<section class="panel"><?php if (!$year): ?><p>سال تحصیلی فعال وجود ندارد.</p><?php elseif (!$sections): ?><p><?= $role==='teacher' ? 'برای حساب شما صنف تعیین نشده است. از مدیر مکتب بخواهید حساب معلم را به پروفایل آموزگار و صنف مربوط وصل کند.' : 'برای سال تحصیلی فعلی صنف فعالی ثبت نشده است.' ?></p><?php else: ?>
<form method="get" class="attendance-controls"><label>صنف<select name="section_id" required><?php foreach($sections as $section): ?><option value="<?= (int)$section['id'] ?>" <?= $selectedSectionId===(int)$section['id']?'selected':'' ?>><?=attendance_h($section['grade'].' '.$section['section_name'])?></option><?php endforeach; ?></select></label><label>تاریخ<input type="date" name="date" value="<?=attendance_h($selectedDate)?>" required></label><button class="button button-secondary" type="submit">نمایش حاضری</button></form>
<form method="post"><input type="hidden" name="csrf_token" value="<?=attendance_h(csrf_token())?>"><input type="hidden" name="section_id" value="<?=$selectedSectionId?>"><input type="hidden" name="attendance_date" value="<?=attendance_h($selectedDate)?>"><div class="attendance-wrap"><table class="attendance-table"><thead><tr><th>شماره شاگرد</th><th>نام شاگرد</th><th>وضعیت حاضری</th></tr></thead><tbody>
<?php if (!$students): ?><tr><td colspan="3">برای این صنف ثبت‌نام فعال پیدا نشد.</td></tr><?php endif; ?>
<?php foreach($students as $student): $sid=(int)$student['id']; $current=$attendanceMap[$sid]??'present'; ?><tr><td dir="ltr"><?=attendance_h($student['student_number'])?></td><td><?=attendance_h($student['first_name'].' '.$student['last_name'])?></td><td><select name="attendance[<?=$sid?>]"><?php foreach($statusLabels as $value=>$label): ?><option value="<?=attendance_h($value)?>" <?=$current===$value?'selected':''?>><?=attendance_h($label)?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
</tbody></table></div><div style="margin-top:16px"><button class="button button-primary" type="submit">ذخیره حاضری</button></div></form>
<?php endif; ?></section><footer class="page-footer">سامانه مدیریت دانش‌آموزان · حاضری</footer></main></div><script src="assets/js/form-validation.js" defer></script></body></html>
