<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_roles(['super_admin', 'head_teacher']);
require_once __DIR__ . '/config/database.php';
$pdo = database_connection();
$error = '';
$success = '';
$mode = in_array((string)($_GET['mode'] ?? 'add'), ['add','edit','delete'], true) ? (string)($_GET['mode'] ?? 'add') : 'add';
$editing = null;
function teachers_h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(400); exit('درخواست معتبر نیست. صفحه را تازه‌سازی کنید.'); }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save') {
            $id = filter_var($_POST['teacher_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            $name = trim((string)($_POST['full_name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            if ($userId !== null) {
                $accountCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher' AND is_active = 1 LIMIT 1");
                $accountCheck->execute([$userId]);
                if (!$accountCheck->fetchColumn()) throw new RuntimeException('حساب انتخاب‌شده فعال نیست یا نقش آن معلم نمی‌باشد.');
            }
            if ($name === '' || mb_strlen($name) > 200) throw new RuntimeException('نام کامل آموزگار الزامی است و باید کمتر از ۲۰۰ نویسه باشد.');
            if (mb_strlen($phone) > 40) throw new RuntimeException('شماره تماس نباید بیشتر از ۴۰ نویسه باشد.');
            if (mb_strlen($email) > 190 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) throw new RuntimeException('آدرس ایمیل معتبر نیست.');
            if ($id > 0) {
                $q = $pdo->prepare('UPDATE teachers SET full_name = :name, phone = :phone, email = :email, user_id = :user_id WHERE id = :id');
                $q->execute(['name'=>$name, 'phone'=>$phone, 'email'=>$email, 'user_id'=>$userId, 'id'=>$id]);
                if ($q->rowCount() === 0) {
                    $check = $pdo->prepare('SELECT id FROM teachers WHERE id = ?'); $check->execute([$id]);
                    if (!$check->fetchColumn()) throw new RuntimeException('آموزگار پیدا نشد.');
                }
                $success = 'اطلاعات آموزگار با موفقیت به‌روزرسانی شد.';
                $mode = 'edit';
            } else {
                $q = $pdo->prepare('INSERT INTO teachers (full_name, phone, email, user_id, is_active) VALUES (:name, :phone, :email, :user_id, 1)');
                $q->execute(['name'=>$name, 'phone'=>$phone, 'email'=>$email, 'user_id'=>$userId]);
                $success = 'آموزگار با موفقیت ثبت شد.';
                $mode = 'add';
            }
        } elseif ($action === 'delete') {
            $id = filter_var($_POST['teacher_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) throw new RuntimeException('آموزگار انتخاب‌شده معتبر نیست.');
            $q = $pdo->prepare('DELETE FROM teachers WHERE id = ?');
            $q->execute([$id]);
            if ($q->rowCount() < 1) throw new RuntimeException('آموزگار پیدا نشد.');
            $success = 'آموزگار حذف شد. حساب کاربری جداگانه او حذف نشده است و صنف‌های مرتبط دیگر آموزگار مسئول ندارند.';
            $mode = 'delete';
        } else throw new RuntimeException('عملیات معتبر نیست.');
    }
} catch (PDOException $e) {
    error_log('Teacher management: ' . $e->getMessage());
    $error = $e->getCode() === '23000' ? 'این آموزگار به یک حساب کاربری مرتبط است یا اطلاعات تکراری است؛ ارتباط‌ها را بررسی کنید.' : 'عملیات انجام نشد. جدول آموزگاران را بررسی کنید.';
} catch (Throwable $e) { $error = $e->getMessage(); }
$editId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
if ($mode === 'edit' && $editId > 0) {
    $q = $pdo->prepare('SELECT id, full_name, phone, email, user_id FROM teachers WHERE id = ? LIMIT 1'); $q->execute([$editId]); $editing = $q->fetch() ?: null;
    if (!$editing && $error === '') $error = 'آموزگار مورد نظر پیدا نشد.';
}
$teachers = $pdo->query('SELECT t.id, t.full_name, t.phone, t.email, t.user_id, u.username AS linked_username, t.is_active, (SELECT COUNT(*) FROM class_sections cs WHERE cs.teacher_id = t.id) AS class_count FROM teachers t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.is_active DESC, t.full_name')->fetchAll();
$teacherAccounts = $pdo->query("SELECT id, full_name, username FROM users WHERE role = 'teacher' AND is_active = 1 ORDER BY full_name, username")->fetchAll();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>مدیریت آموزگاران</title><link rel="stylesheet" href="assets/css/style.css">
<style>
.teacher-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:20px;align-items:start}.teacher-grid>.panel:first-child{width:100%}.teacher-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 20px}.teacher-form label{display:grid;gap:7px;font-weight:700;min-width:0}.teacher-form .teacher-form-section{grid-column:1/-1;padding:18px;border:1px solid var(--line);border-radius:12px;background:#fbfcff}.teacher-form .teacher-form-section h3{margin:0 0 14px;padding-bottom:8px;border-bottom:1px solid var(--line);font-size:1rem}.teacher-form .teacher-form-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px}.teacher-form .teacher-form-actions{grid-column:1/-1;display:flex;gap:10px;flex-wrap:wrap}.teacher-form input,.teacher-form select{min-height:48px;font-size:16px}.teacher-form label small{line-height:1.7;color:var(--muted)}.teacher-form input,.teacher-form select{box-sizing:border-box;width:100%;padding:11px 12px;border:1px solid var(--line);border-radius:9px;background:#fff;font:inherit}.teacher-table-wrap{overflow-x:auto}.teacher-table{width:100%;border-collapse:collapse}.teacher-table th,.teacher-table td{padding:11px;text-align:right;border-bottom:1px solid var(--line);white-space:nowrap}.teacher-table th{background:#e8eef8}.teacher-actions{display:flex;gap:6px;align-items:center}.teacher-actions form{margin:0}.notice{padding:12px 14px;border-radius:9px;margin-bottom:16px}.notice.ok{background:#dcfce7;color:#166534}.notice.bad{background:#fee2e2;color:#991b1b}.button-danger{background:#b42332;color:white;border:0}@media(max-width:650px){.teacher-form,.teacher-form .teacher-form-fields{grid-template-columns:1fr}.teacher-form .teacher-form-section,.teacher-form .teacher-form-actions{grid-column:auto}}
</style></head><body class="profile-page"><div class="admin-layout">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
        <main class="admin-main">
            <?php require __DIR__ . '/includes/topbar.php'; ?><header class="page-header"><p class="eyebrow">مدیریت مدرسه</p><h1>مدیریت آموزگاران</h1><p class="muted">ثبت، ویرایش و حذف آموزگاران مدرسه.</p></header>
<?php if ($success !== ''): ?><div class="notice ok"><?= teachers_h($success) ?></div><?php endif; ?><?php if ($error !== ''): ?><div class="notice bad"><?= teachers_h($error) ?></div><?php endif; ?>
<div class="teacher-grid">
<section class="panel"><h2><?= $mode === 'edit' ? 'ویرایش آموزگار' : ($mode === 'delete' ? 'حذف آموزگار' : 'ثبت آموزگار') ?></h2>
<?php if ($mode === 'delete'): ?><p>برای حذف آموزگار، دکمه حذف را در فهرست روبه‌رو بزنید. صنف‌های مرتبط بدون آموزگار مسئول باقی می‌مانند.</p>
<?php else: ?><form class="teacher-form" method="post"><input type="hidden" name="csrf_token" value="<?= teachers_h(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="teacher_id" value="<?= (int)($editing['id'] ?? 0) ?>">
<div class="teacher-form-section"><h3>مشخصات آموزگار</h3><div class="teacher-form-fields">
<label>نام کامل آموزگار<input name="full_name" maxlength="200" required value="<?= teachers_h($editing['full_name'] ?? '') ?>" autocomplete="name"></label>
<label>شماره تماس<input name="phone" maxlength="40" value="<?= teachers_h($editing['phone'] ?? '') ?>" autocomplete="tel"></label>
<label>ایمیل (اختیاری)<input type="email" name="email" maxlength="190" value="<?= teachers_h($editing['email'] ?? '') ?>" autocomplete="email"></label>
</div></div><div class="teacher-form-section"><h3>حساب کاربری و دسترسی</h3><div class="teacher-form-fields">
<label>حساب ورود معلم (اختیاری)<select name="user_id"><option value="">بدون حساب مرتبط</option><?php foreach ($teacherAccounts as $account): ?><option value="<?= (int)$account['id'] ?>" <?= (int)($editing['user_id'] ?? 0) === (int)$account['id'] ? 'selected' : '' ?>><?= teachers_h(trim((string)$account['full_name']) ?: $account['username']) ?> (<?= teachers_h($account['username']) ?>)</option><?php endforeach; ?></select><small>ابتدا در مدیریت کاربران یک حساب با نقش «معلم» بسازید. هر حساب فقط به یک آموزگار مرتبط می‌شود.</small></label>
</div></div><div class="teacher-form-actions"><button class="button button-primary" type="submit"><?= $mode === 'edit' ? 'ذخیره تغییرات' : 'ثبت آموزگار' ?></button><?php if ($mode === 'edit'): ?><a class="button button-secondary" href="teachers.php?mode=edit">لغو ویرایش</a><?php endif; ?></div></form><?php endif; ?>
</section>
<section class="panel"><h2>فهرست آموزگاران</h2><?php if (!$teachers): ?><p class="muted">هنوز آموزگاری ثبت نشده است.</p><?php else: ?><div class="teacher-table-wrap"><table class="teacher-table"><thead><tr><th>نام</th><th>شماره تماس</th><th>ایمیل</th><th>حساب ورود</th><th>صنف‌های مرتبط</th><th>عملیات</th></tr></thead><tbody><?php foreach ($teachers as $teacher): ?><tr><td><?= teachers_h($teacher['full_name']) ?></td><td><?= teachers_h($teacher['phone'] ?: '—') ?></td><td><?= teachers_h($teacher['email'] ?: '—') ?></td><td><?= teachers_h($teacher['linked_username'] ?: 'مرتبط نیست') ?></td><td><?= (int)$teacher['class_count'] ?></td><td><div class="teacher-actions"><a class="button button-secondary" href="teachers.php?mode=edit&amp;id=<?= (int)$teacher['id'] ?>">ویرایش</a><form method="post" onsubmit="return confirm('آیا از حذف این آموزگار مطمئن هستید؟');"><input type="hidden" name="csrf_token" value="<?= teachers_h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="teacher_id" value="<?= (int)$teacher['id'] ?>"><button class="button button-danger" type="submit">حذف</button></form></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</div><footer class="page-footer">سامانه مدیریت دانش‌آموزان · مدیریت آموزگاران</footer></main></div><script src="assets/js/form-validation.js" defer></script></body></html>