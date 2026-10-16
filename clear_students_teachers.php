<?php
declare(strict_types=1);

/*
 * Destructive local maintenance tool.
 * Deletes student records and teacher records only. Never deletes users/admin accounts,
 * school years, class sections, or tuition fee rules.
 */
if (PHP_OS_FAMILY !== 'Linux') {
    http_response_code(404);
    exit('Not found.');
}
$remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/auth.php';
require_roles(['super_admin']);
require_once __DIR__ . '/config/database.php';

$pdo = database_connection();
$error = '';
$success = '';
$counts = [];
$deleted = [];

function cleanup_h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function cleanup_table_exists(PDO $pdo, string $table): bool {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $statement->execute([$table]);
    return (int)$statement->fetchColumn() > 0;
}
function cleanup_count(PDO $pdo, string $table): int {
    if (!cleanup_table_exists($pdo, $table)) return 0;
    // Table names passed here are hard-coded below, never user-provided.
    return (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}
function cleanup_delete_if_exists(PDO $pdo, string $table): int {
    if (!cleanup_table_exists($pdo, $table)) return 0;
    // Table names passed here are hard-coded below, never user-provided.
    return $pdo->exec('DELETE FROM `' . $table . '`');
}

try {
    $counts = [
        'students' => cleanup_count($pdo, 'students'),
        'teachers' => cleanup_count($pdo, 'teachers'),
        'users' => cleanup_count($pdo, 'users'),
        'enrollments' => cleanup_count($pdo, 'student_enrollments'),
        'history' => cleanup_count($pdo, 'student_enrollment_history'),
        'attendance' => cleanup_count($pdo, 'student_attendance'),
        'marks' => cleanup_count($pdo, 'student_marks'),
        'discounts' => cleanup_count($pdo, 'student_year_discounts'),
        'charges' => cleanup_count($pdo, 'student_fee_charges'),
        'payments' => cleanup_count($pdo, 'student_fee_payments'),
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            throw new RuntimeException('درخواست معتبر نیست. صفحه را تازه‌سازی کنید.');
        }
        if (($_POST['confirm_phrase'] ?? '') !== 'حذف شاگردان و آموزگاران') {
            throw new RuntimeException('برای تأیید، عبارت خواسته‌شده را دقیق وارد کنید.');
        }
        if (($_POST['confirm_backup'] ?? '') !== 'yes') {
            throw new RuntimeException('ابتدا تأیید کنید که از پایگاه داده پشتیبان گرفته‌اید.');
        }

        $pdo->beginTransaction();
        try {
            // Delete dependent student records first, in foreign-key-safe order.
            $deleted['student_enrollment_history'] = cleanup_delete_if_exists($pdo, 'student_enrollment_history');
            $deleted['student_attendance'] = cleanup_delete_if_exists($pdo, 'student_attendance');
            $deleted['student_marks'] = cleanup_delete_if_exists($pdo, 'student_marks');
            $deleted['student_year_discounts'] = cleanup_delete_if_exists($pdo, 'student_year_discounts');

            if (cleanup_table_exists($pdo, 'student_fee_payments') && cleanup_table_exists($pdo, 'student_fee_charges')) {
                $deleted['student_fee_payments'] = $pdo->exec(
                    'DELETE p FROM student_fee_payments p INNER JOIN student_fee_charges c ON c.id = p.charge_id'
                );
            } else {
                $deleted['student_fee_payments'] = cleanup_delete_if_exists($pdo, 'student_fee_payments');
            }

            $deleted['student_fee_charges'] = cleanup_delete_if_exists($pdo, 'student_fee_charges');
            $deleted['student_enrollments'] = cleanup_delete_if_exists($pdo, 'student_enrollments');
            $deleted['students'] = cleanup_delete_if_exists($pdo, 'students');

            // Deleting teacher records leaves users/login accounts intact. Existing sections
            // are retained; their teacher_id is set to NULL by the foreign-key definition.
            $deleted['teachers'] = cleanup_delete_if_exists($pdo, 'teachers');

            $pdo->commit();
            $success = 'اطلاعات شاگردان و آموزگاران حذف شد. حساب‌های کاربری مدیران و سایر کاربران حذف نشده‌اند؛ صنف‌ها، سال‌های تحصیلی و قوانین فیس نیز حفظ شده‌اند.';
            $counts = [
                'students' => cleanup_count($pdo, 'students'),
                'teachers' => cleanup_count($pdo, 'teachers'),
                'users' => cleanup_count($pdo, 'users'),
                'enrollments' => cleanup_count($pdo, 'student_enrollments'),
                'history' => cleanup_count($pdo, 'student_enrollment_history'),
                'attendance' => cleanup_count($pdo, 'student_attendance'),
                'marks' => cleanup_count($pdo, 'student_marks'),
                'discounts' => cleanup_count($pdo, 'student_year_discounts'),
                'charges' => cleanup_count($pdo, 'student_fee_charges'),
                'payments' => cleanup_count($pdo, 'student_fee_payments'),
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
} catch (Throwable $exception) {
    if ($exception instanceof RuntimeException) {
        $error = $exception->getMessage();
    } else {
        error_log('Student/teacher cleanup failed: ' . $exception->getMessage());
        $error = 'حذف اطلاعات انجام نشد و عملیات لغو شد. جزئیات در گزارش PHP/Apache ثبت شده است: ' . $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>حذف اطلاعات شاگردان و آموزگاران</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
.cleanup-wrap{max-width:950px;margin:28px auto;padding:0 18px}.cleanup-card{background:#fff;border:1px solid #e1e7f0;border-radius:14px;padding:24px;margin:18px 0}.cleanup-alert{padding:13px 16px;border-radius:10px;margin:14px 0;line-height:1.9}.cleanup-alert.ok{background:#dcfce7;color:#166534}.cleanup-alert.bad{background:#fee2e2;color:#991b1b}.cleanup-alert.warn{background:#fff7ed;color:#9a3412}.cleanup-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px}.cleanup-stat{padding:14px;border:1px solid #e1e7f0;border-radius:10px;background:#f8fafc}.cleanup-stat strong{display:block;font-size:1.3rem;margin-top:5px}.cleanup-submit{border:0;border-radius:9px;background:#b91c1c;color:#fff;padding:13px 18px;font:inherit;font-weight:700;cursor:pointer}.cleanup-field{display:block;margin:15px 0}.cleanup-field input[type=text]{display:block;width:100%;max-width:460px;padding:11px;margin-top:7px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}.cleanup-check{display:flex;gap:10px;align-items:flex-start;margin:18px 0}.cleanup-check input{margin-top:7px}
</style>
</head>
<body class="profile-page">
<div class="admin-layout">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="admin-main">
<?php require __DIR__ . '/includes/topbar.php'; ?>
<div class="cleanup-wrap">
<header class="page-header"><p class="eyebrow">ابزار نگهداری پایگاه داده</p><h1>حذف اطلاعات شاگردان و آموزگاران</h1><p class="muted">این صفحه فقط در Linux، از همان کامپیوتر و برای مدیر کل قابل دسترسی است.</p></header>
<?php if ($success !== ''): ?><div class="cleanup-alert ok" role="status"><?=cleanup_h($success)?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="cleanup-alert bad" role="alert"><?=cleanup_h($error)?></div><?php endif; ?>

<section class="cleanup-card">
<h2>وضعیت فعلی پایگاه داده</h2>
<div class="cleanup-grid">
<div class="cleanup-stat">شاگردان<strong><?=number_format((int)($counts['students'] ?? 0))?></strong></div>
<div class="cleanup-stat">آموزگاران<strong><?=number_format((int)($counts['teachers'] ?? 0))?></strong></div>
<div class="cleanup-stat">حساب‌های کاربری<strong><?=number_format((int)($counts['users'] ?? 0))?></strong><small>حذف نخواهند شد</small></div>
<div class="cleanup-stat">ثبت‌نام‌ها<strong><?=number_format((int)($counts['enrollments'] ?? 0))?></strong></div>
<div class="cleanup-stat">سوابق فیس<strong><?=number_format((int)($counts['charges'] ?? 0))?></strong></div>
<div class="cleanup-stat">پرداخت‌ها<strong><?=number_format((int)($counts['payments'] ?? 0))?></strong></div>
</div>
<div class="cleanup-alert warn"><strong>هشدار: این کار برگشت‌پذیر نیست.</strong> قبل از ادامه از صفحهٔ پشتیبان پایگاه داده یک فایل SQL ذخیره کنید.</div>
<h3>با اجرای این عملیات چه چیزی حذف می‌شود؟</h3>
<ul>
<li>تمام رکوردهای شاگردان و اطلاعات وابسته به آن‌ها: ثبت‌نام‌ها، تاریخچهٔ ثبت‌نام، حاضری، نمرات، تخفیف‌های سالانه، فیس‌های ثبت‌شده و پرداخت‌های وابسته.</li>
<li>تمام رکوردهای آموزگاران. صنف‌ها و سال‌های تحصیلی باقی می‌مانند؛ ارتباط آموزگار با صنف خالی می‌شود.</li>
</ul>
<h3>چه چیزی حذف نمی‌شود؟</h3>
<ul>
<li><strong>هیچ رکوردی از جدول users حذف نمی‌شود</strong>؛ حساب‌های مدیر کل، مدیران و دیگر حساب‌های ورود حفظ می‌شوند.</li>
<li>سال‌های تحصیلی، صنف‌ها، قوانین فیس، تنظیمات مکتب و فایل‌های پروژه حفظ می‌شوند.</li>
</ul>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?=cleanup_h(csrf_token())?>">
<label class="cleanup-check"><input type="checkbox" name="confirm_backup" value="yes" required><span>تأیید می‌کنم که از پایگاه داده پشتیبان SQL گرفته‌ام.</span></label>
<label class="cleanup-field">برای تأیید، این عبارت را دقیق وارد کنید:
<input type="text" name="confirm_phrase" required autocomplete="off" placeholder="حذف شاگردان و آموزگاران"></label>
<button class="cleanup-submit" type="submit">حذف تمام شاگردان و آموزگاران</button>
</form>
</section>
</div>
</main>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
