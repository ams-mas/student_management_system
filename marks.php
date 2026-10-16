<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_roles(['super_admin', 'head_teacher', 'teacher']);
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
$allowedSectionIds = array_map(static fn($s) => (int)$s['id'], $sections);
$selectedSectionId = filter_var($_POST['section_id'] ?? $_GET['section_id'] ?? null, FILTER_VALIDATE_INT) ?: (int)($sections[0]['id'] ?? 0);
$subject = trim((string)($_POST['subject_name'] ?? $_GET['subject'] ?? ''));
$assessment = trim((string)($_POST['assessment_name'] ?? $_GET['assessment'] ?? ''));
$maxScore = filter_var($_POST['max_score'] ?? 100, FILTER_VALIDATE_FLOAT);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($maxScore === false || !is_finite((float)$maxScore) || $maxScore <= 0 || $maxScore > 100000)) $maxScore = 100.0;
$success = '';
$error = '';
function marks_h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(400); exit('درخواست معتبر نیست. صفحه را تازه‌سازی کنید.'); }
    try {
        if (!$year || !in_array($selectedSectionId, $allowedSectionIds, true)) throw new RuntimeException('به این صنف دسترسی ندارید یا سال تحصیلی فعال نیست.');
        if ($subject === '' || mb_strlen($subject) > 120) throw new RuntimeException('نام مضمون الزامی است و نباید بیشتر از ۱۲۰ نویسه باشد.');
        if ($assessment === '' || mb_strlen($assessment) > 120) throw new RuntimeException('نام امتحان یا ارزیابی الزامی است و نباید بیشتر از ۱۲۰ نویسه باشد.');
        if ($maxScore === false || !is_finite((float)$maxScore) || $maxScore <= 0 || $maxScore > 100000) throw new RuntimeException('نمرهٔ کامل باید بیشتر از صفر باشد.');
        $rosterStmt = $pdo->prepare("SELECT s.id FROM student_enrollments e JOIN students s ON s.id=e.student_id WHERE e.class_section_id=? AND e.school_year_id=? AND e.is_current=1 AND e.status='enrolled' AND s.is_active=1");
        $rosterStmt->execute([$selectedSectionId,(int)$year['id']]);
        $rosterIds = array_map('intval',$rosterStmt->fetchAll(PDO::FETCH_COLUMN));
        $scores = $_POST['scores'] ?? [];
        if (!is_array($scores)) throw new RuntimeException('نمرات ارسالی معتبر نیستند.');
        $save = $pdo->prepare('INSERT INTO student_marks (school_year_id,class_section_id,student_id,subject_name,assessment_name,score,max_score,entered_by) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score),max_score=VALUES(max_score),entered_by=VALUES(entered_by),updated_at=CURRENT_TIMESTAMP');
        $pdo->beginTransaction();
        foreach ($rosterIds as $studentId) {
            $raw = trim((string)($scores[(string)$studentId] ?? ''));
            if ($raw === '') continue;
            $score = filter_var($raw,FILTER_VALIDATE_FLOAT);
            if ($score === false || !is_finite((float)$score) || $score < 0 || $score > $maxScore) throw new RuntimeException('هر نمره باید بین صفر و نمرهٔ کامل باشد.');
            $save->execute([(int)$year['id'],$selectedSectionId,$studentId,$subject,$assessment,(float)$score,(float)$maxScore,$userId]);
        }
        $pdo->commit();
        $success = 'نمرات با موفقیت ذخیره شد.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'ذخیره نمرات انجام نشد. پایگاه داده را بررسی کنید.';
        if (!$e instanceof RuntimeException) error_log('Marks save: '.$e->getMessage());
    }
}
$students = [];
$scoreMap = [];
if ($year && in_array($selectedSectionId,$allowedSectionIds,true)) {
    $rosterStmt = $pdo->prepare("SELECT s.id,s.student_number,s.first_name,s.last_name FROM student_enrollments e JOIN students s ON s.id=e.student_id WHERE e.class_section_id=? AND e.school_year_id=? AND e.is_current=1 AND e.status='enrolled' AND s.is_active=1 ORDER BY s.last_name,s.first_name");
    $rosterStmt->execute([$selectedSectionId,(int)$year['id']]);
    $students=$rosterStmt->fetchAll();
    if ($subject!=='' && $assessment!=='') {
        $saved=$pdo->prepare('SELECT student_id,score,max_score FROM student_marks WHERE class_section_id=? AND subject_name=? AND assessment_name=? AND school_year_id=?');
        $saved->execute([$selectedSectionId,$subject,$assessment,(int)$year['id']]);
        $savedRows = $saved->fetchAll();
        foreach($savedRows as $row) $scoreMap[(int)$row['student_id']]=(string)$row['score'];
        if ($savedRows) $maxScore = (float)$savedRows[0]['max_score'];
    }
}
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>نمرات شاگردان</title><link rel="stylesheet" href="assets/css/style.css"><style>.marks-table{width:100%;border-collapse:separate;border-spacing:0;min-width:540px}.marks-table th,.marks-table td{padding:10px;border-bottom:1px solid var(--line);text-align:right}.marks-table th{background:#eef2f8}.marks-table input{font:inherit;padding:9px;border:1px solid var(--line);border-radius:8px;max-width:150px}.marks-controls{display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin-bottom:18px;padding:16px;background:#f8faff;border:1px solid var(--line);border-radius:12px}.marks-controls label{flex:1;min-width:180px}.marks-controls select,.marks-controls input{min-height:46px;font-size:16px}.marks-table tbody tr:nth-child(even){background:#fafcff}.marks-table td:last-child{min-width:170px}.marks-table input{min-height:46px;width:100%;max-width:160px}.marks-controls label{display:grid;gap:6px;font-weight:700}.marks-controls select,.marks-controls input{font:inherit;padding:9px;border:1px solid var(--line);border-radius:8px}.marks-wrap{overflow-x:auto}.marks-notice{padding:12px;border-radius:9px;margin-bottom:14px}.marks-ok{background:#dcfce7;color:#166534}.marks-error{background:#fee2e2;color:#991b1b}</style></head><body class="profile-page"><div class="admin-layout"><?php require __DIR__ . '/includes/sidebar.php'; ?><main class="admin-main"><?php require __DIR__ . '/includes/topbar.php'; ?><header class="page-header"><p class="eyebrow">بخش آموزشی</p><h1>نمرات و امتحانات</h1><p class="muted">ثبت و اصلاح نمرات برای صنف‌های مجاز.</p></header>
<?php if ($success!==''): ?><div class="marks-notice marks-ok"><?=marks_h($success)?></div><?php endif; ?><?php if ($error!==''): ?><div class="marks-notice marks-error"><?=marks_h($error)?></div><?php endif; ?>
<section class="panel"><?php if (!$year): ?><p>سال تحصیلی فعال وجود ندارد.</p><?php elseif (!$sections): ?><p><?= $role==='teacher' ? 'برای حساب شما صنف تعیین نشده است. از مدیر مکتب بخواهید حساب معلم را به پروفایل آموزگار و صنف مربوط وصل کند.' : 'برای سال تحصیلی فعلی صنف فعالی ثبت نشده است.' ?></p><?php else: ?>
<form method="get" class="marks-controls"><label>صنف<select name="section_id" required><?php foreach($sections as $section): ?><option value="<?= (int)$section['id'] ?>" <?= $selectedSectionId===(int)$section['id']?'selected':'' ?>><?=marks_h($section['grade'].' '.$section['section_name'])?></option><?php endforeach; ?></select></label><label>مضمون<input name="subject" maxlength="120" value="<?=marks_h($subject)?>" required></label><label>امتحان / ارزیابی<input name="assessment" maxlength="120" value="<?=marks_h($assessment)?>" required></label><button class="button button-secondary" type="submit">نمایش نمرات</button></form>
<form method="post"><input type="hidden" name="csrf_token" value="<?=marks_h(csrf_token())?>"><input type="hidden" name="section_id" value="<?=$selectedSectionId?>"><input type="hidden" name="subject_name" value="<?=marks_h($subject)?>"><input type="hidden" name="assessment_name" value="<?=marks_h($assessment)?>"><label style="display:inline-flex;gap:8px;align-items:center;margin-bottom:14px">نمرهٔ کامل <input type="number" name="max_score" min="0.01" step="0.01" max="100000" required value="<?=marks_h($maxScore)?>"></label><div class="marks-wrap"><table class="marks-table"><thead><tr><th>شماره شاگرد</th><th>نام شاگرد</th><th>نمره</th></tr></thead><tbody><?php if (!$students): ?><tr><td colspan="3">برای این صنف ثبت‌نام فعال پیدا نشد.</td></tr><?php endif; ?><?php foreach($students as $student): $sid=(int)$student['id']; ?><tr><td dir="ltr"><?=marks_h($student['student_number'])?></td><td><?=marks_h($student['first_name'].' '.$student['last_name'])?></td><td><input type="number" name="scores[<?=$sid?>]" min="0" step="0.01" max="<?=marks_h($maxScore)?>" value="<?=marks_h($scoreMap[$sid]??'')?>" placeholder="—"></td></tr><?php endforeach; ?></tbody></table></div><div style="margin-top:16px"><button class="button button-primary" type="submit" <?=($subject===''||$assessment==='')?'disabled':''?>>ذخیره نمرات</button></div></form>
<?php endif; ?></section><footer class="page-footer">سامانه مدیریت دانش‌آموزان · نمرات</footer></main></div><script src="assets/js/form-validation.js" defer></script></body></html>
