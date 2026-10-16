<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_roles(['super_admin', 'head_teacher']);
require_once __DIR__ . '/config/database.php';
$pdo = database_connection();
$error = ''; $success = '';
$mode = in_array((string)($_GET['mode'] ?? 'add'), ['add','edit','delete'], true) ? (string)($_GET['mode'] ?? 'add') : 'add';
$gradeNames = [1=>'اول',2=>'دوم',3=>'سوم',4=>'چهارم',5=>'پنجم',6=>'ششم',7=>'هفتم',8=>'هشتم',9=>'نهم',10=>'دهم',11=>'یازدهم',12=>'دوازدهم'];
$letters = ['A','B','C','الف','ب','ج','د','هـ','و','ز','ح','ط','ی','ک','ل','م','ن','س','ع','ف','ص','ق','ر','ش','ت','ث','خ','ذ','ض','ظ','غ'];
function classes_h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$year = $pdo->query('SELECT id,name,is_closed FROM school_years WHERE is_active=1 ORDER BY id DESC LIMIT 1')->fetch() ?: null;
try {
 if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(400); exit('درخواست معتبر نیست. صفحه را تازه‌سازی کنید.'); }
  $action = (string)($_POST['action'] ?? '');
  if (!$year) throw new RuntimeException('ابتدا از بخش سال تحصیلی، یک سال تحصیلی را فعال کنید.');
  if ((int)$year['is_closed'] === 1) throw new RuntimeException('سال تحصیلی فعلی بسته است؛ یک سال باز را فعال کنید.');
  if ($action === 'save') {
   $id = filter_var($_POST['section_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
   $grade = filter_var($_POST['grade'] ?? null, FILTER_VALIDATE_INT);
   $letter = trim((string)($_POST['section_name'] ?? ''));
   if (!$grade || !isset($gradeNames[$grade]) || !in_array($letter, $letters, true)) throw new RuntimeException('پایه یا حرف صنف معتبر نیست.');
   $teacherId = filter_var($_POST['teacher_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
   if ($teacherId !== null) { $teacherCheck = $pdo->prepare('SELECT id FROM teachers WHERE id = ? AND is_active = 1 LIMIT 1'); $teacherCheck->execute([$teacherId]); if (!$teacherCheck->fetchColumn()) throw new RuntimeException('آموزگار انتخاب‌شده معتبر یا فعال نیست.'); }
   if ($id > 0) {
    $q = $pdo->prepare('UPDATE class_sections SET grade=?, section_name=?, teacher_id=? WHERE id=? AND school_year_id=?');
    $q->execute([$grade,$letter,$teacherId,$id,(int)$year['id']]);
    $check = $pdo->prepare('SELECT id FROM class_sections WHERE id=? AND school_year_id=?'); $check->execute([$id,(int)$year['id']]);
    if (!$check->fetchColumn()) throw new RuntimeException('صنف مورد نظر در سال تحصیلی فعلی پیدا نشد.');
    $success = 'اطلاعات صنف به‌روزرسانی شد.'; $mode = 'edit';
   } else {
    $q = $pdo->prepare('INSERT INTO class_sections (school_year_id,grade,section_name,teacher_id,is_active) VALUES (?,?,?,?,1)');
    $q->execute([(int)$year['id'],$grade,$letter,$teacherId]);
    $success = 'صنف «'.$gradeNames[$grade].' '.$letter.'» ثبت شد.'; $mode = 'add';
   }
  } elseif ($action === 'delete') {
   $id = filter_var($_POST['section_id'] ?? null, FILTER_VALIDATE_INT);
   if (!$id) throw new RuntimeException('صنف انتخاب‌شده معتبر نیست.');
   $count = $pdo->prepare('SELECT COUNT(*) FROM student_enrollments WHERE class_section_id=?'); $count->execute([$id]); $hasEnrollments = (int)$count->fetchColumn() > 0;
   if ($hasEnrollments) {
    $q = $pdo->prepare('UPDATE class_sections SET is_active=0 WHERE id=? AND school_year_id=?'); $q->execute([$id,(int)$year['id']]);
    if ($q->rowCount() < 1) throw new RuntimeException('صنف پیدا نشد یا از قبل غیرفعال است.');
    $success = 'این صنف سابقه ثبت‌نام دارد؛ برای حفظ سوابق، به‌جای حذف دایمی غیرفعال شد.';
   } else {
    $q = $pdo->prepare('DELETE FROM class_sections WHERE id=? AND school_year_id=?'); $q->execute([$id,(int)$year['id']]);
    if ($q->rowCount() < 1) throw new RuntimeException('صنف پیدا نشد.');
    $success = 'صنف حذف شد.';
   }
   $mode = 'delete';
  } else throw new RuntimeException('عملیات معتبر نیست.');
 }
} catch (PDOException $e) {
 $error = $e->getCode() === '23000' ? 'این صنف قبلاً در سال تحصیلی فعلی ثبت شده یا سابقه وابسته دارد.' : 'عملیات انجام نشد. پایگاه داده را بررسی کنید.';
 error_log('Class management: '.$e->getMessage());
} catch (Throwable $e) { $error = $e->getMessage(); }
$editId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$editing = null;
if ($mode === 'edit' && $editId > 0 && $year) {
 $q = $pdo->prepare('SELECT id,grade,section_name,teacher_id FROM class_sections WHERE id=? AND school_year_id=? LIMIT 1'); $q->execute([$editId,(int)$year['id']]); $editing = $q->fetch() ?: null;
 if (!$editing && $error === '') $error = 'صنف مورد نظر پیدا نشد.';
}
$sections = [];
if ($year) {
 $q = $pdo->prepare('SELECT cs.id,cs.grade,cs.section_name,cs.is_active,cs.teacher_id,t.full_name AS teacher_name,(SELECT COUNT(*) FROM student_enrollments se WHERE se.class_section_id=cs.id AND se.is_current=1) AS student_count,(SELECT COUNT(*) FROM student_enrollments se2 WHERE se2.class_section_id=cs.id) AS enrollment_count FROM class_sections cs LEFT JOIN teachers t ON t.id=cs.teacher_id WHERE cs.school_year_id=? ORDER BY cs.grade,cs.section_name');
 $q->execute([(int)$year['id']]); $sections = $q->fetchAll();
}
$teachersForClass = $pdo->query('SELECT id, full_name FROM teachers WHERE is_active = 1 ORDER BY full_name')->fetchAll();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>مدیریت صنف‌ها</title><link rel="stylesheet" href="assets/css/style.css"><style>
.class-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:20px;align-items:start}.class-grid>.panel:first-child{max-width:760px;width:100%}.class-grid>.panel:last-child{min-width:0}.class-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px}.class-form label{min-width:0}.class-form .abjad-help,.class-form button,.class-form a{grid-column:1/-1}.class-form select{min-height:48px;font-size:16px}.class-form label{display:grid;gap:7px;font-weight:700}.class-form select{width:100%;padding:12px;border:1px solid var(--line);border-radius:9px;background:white;font:inherit}.class-table-wrap{overflow-x:auto}.class-table{width:100%;border-collapse:collapse}.class-table th,.class-table td{padding:11px;text-align:right;border-bottom:1px solid var(--line);white-space:nowrap}.class-table th{background:#e8eef8}.class-label{font-weight:800;color:#173579}.class-actions{display:flex;gap:6px;align-items:center}.class-actions form{margin:0}.notice{padding:12px 14px;border-radius:9px;margin-bottom:16px}.notice.ok{background:#dcfce7;color:#166534}.notice.bad{background:#fee2e2;color:#991b1b}.abjad-help{font-size:.9rem;color:var(--muted);line-height:1.9}.button-danger{background:#b42332;color:#fff;border:0}@media(max-width:850px){.class-grid{grid-template-columns:1fr}.class-grid>.panel:first-child{max-width:none}}
</style></head><body class="profile-page"><div class="admin-layout">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
        <main class="admin-main">
            <?php require __DIR__ . '/includes/topbar.php'; ?><header class="page-header"><p class="eyebrow">مدیریت مدرسه</p><h1>مدیریت صنف‌ها</h1><p class="muted">برای سال تحصیلی فعلی صنف بسازید، ویرایش کنید یا حذف نمایید.</p></header>
<?php if ($success!==''): ?><div class="notice ok"><?= classes_h($success) ?></div><?php endif; ?><?php if ($error!==''): ?><div class="notice bad"><?= classes_h($error) ?></div><?php endif; ?>
<?php if (!$year): ?><section class="panel"><h2>سال تحصیلی فعال نیست</h2><p>ابتدا یک سال تحصیلی در تنظیمات ایجاد و فعال کنید.</p><a class="button button-primary" href="school.php">رفتن به سال تحصیلی</a></section><?php else: ?>
<section class="panel" style="margin-bottom:18px"><strong>سال تحصیلی فعلی:</strong> <?= classes_h($year['name']) ?></section>
<div class="class-grid"><section class="panel"><h2><?= $mode==='edit'?'ویرایش صنف':($mode==='delete'?'حذف صنف':'اضافه کردن صنف') ?></h2>
<?php if ($mode==='delete'): ?><p>برای حذف صنف از دکمه حذف در فهرست استفاده کنید. صنف‌هایی که سابقه ثبت‌نام دارند برای حفظ تاریخچه غیرفعال می‌شوند.</p>
<?php else: ?><form class="class-form" method="post"><input type="hidden" name="csrf_token" value="<?= classes_h(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="section_id" value="<?= (int)($editing['id']??0) ?>"><label>پایه<select name="grade" required><?php foreach($gradeNames as $n=>$label): ?><option value="<?= $n ?>" <?= (int)($editing['grade']??1)===$n?'selected':'' ?>><?= classes_h($label) ?> (<?= $n ?>)</option><?php endforeach; ?></select></label><label>حرف صنف<select name="section_name" required><?php foreach($letters as $letter): ?><option value="<?= classes_h($letter) ?>" <?= (string)($editing['section_name']??'الف')===$letter?'selected':'' ?>><?= classes_h($letter) ?></option><?php endforeach; ?></select></label><label>آموزگار مسئول (اختیاری)<select name="teacher_id"><option value="">تعیین نشده</option><?php foreach ($teachersForClass as $classTeacher): ?><option value="<?= (int)$classTeacher['id'] ?>" <?= (int)($editing['teacher_id'] ?? 0) === (int)$classTeacher['id'] ? 'selected' : '' ?>><?= classes_h($classTeacher['full_name']) ?></option><?php endforeach; ?></select></label><p class="abjad-help">حروف صنف: الف، ب، ج، د، هـ، و، ز، ح، ط، ی، ک، ل، م، ن، س، ع، ف، ص، ق، ر، ش، ت، ث، خ، ذ، ض، ظ، غ</p><button class="button button-primary" type="submit" <?= (int)$year['is_closed']===1?'disabled':'' ?>><?= $mode==='edit'?'ذخیره تغییرات':'ثبت صنف' ?></button><?php if($mode==='edit'): ?><a class="button button-secondary" href="classes.php?mode=edit">لغو ویرایش</a><?php endif; ?></form><?php endif; ?>
</section>
<section class="panel"><h2>صنف‌های سال جاری</h2><?php if (!$sections): ?><p class="muted">هنوز صنفی برای این سال ثبت نشده است.</p><?php else: ?><div class="class-table-wrap"><table class="class-table"><thead><tr><th>نام صنف</th><th>آموزگار مسئول</th><th>شاگردان فعلی</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody><?php foreach($sections as $s): ?><tr><td class="class-label"><?= classes_h(($gradeNames[(int)$s['grade']]??$s['grade']).' '.$s['section_name']) ?></td><td><?= classes_h($s['teacher_name']?:'تعیین نشده') ?></td><td><?= (int)$s['student_count'] ?></td><td><?= (int)$s['is_active']===1?'فعال':'غیرفعال' ?></td><td><div class="class-actions"><a class="button button-secondary" href="classes.php?mode=edit&amp;id=<?= (int)$s['id'] ?>">ویرایش</a><form method="post" onsubmit="return confirm('آیا از حذف این صنف مطمئن هستید؟ اگر ثبت‌نام داشته باشد غیرفعال می‌شود.');"><input type="hidden" name="csrf_token" value="<?= classes_h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="section_id" value="<?= (int)$s['id'] ?>"><button class="button button-danger" type="submit">حذف</button></form></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section></div><?php endif; ?>
<footer class="page-footer">سامانه مدیریت دانش‌آموزان · مدیریت صنف‌ها</footer></main></div><script src="assets/js/form-validation.js" defer></script></body></html>