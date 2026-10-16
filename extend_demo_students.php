<?php
declare(strict_types=1);

/* Local-only tool to extend the existing demo school year to 5,000 students. */
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
$summary = null;
$runTableExists = false;
$yearId = 0;
$existingStudentCount = 0;
$targetStudentCount = 5000;

function extend_demo_h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function extend_demo_pick(array $items): string {
    return $items[random_int(0, count($items) - 1)];
}
function extend_demo_digits(string $value): string {
    return strtr($value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
}
function extend_demo_grade_name(int $grade): string {
    $names = [1=>'اول',2=>'دوم',3=>'سوم',4=>'چهارم',5=>'پنجم',6=>'ششم',7=>'هفتم',8=>'هشتم',9=>'نهم',10=>'دهم',11=>'یازدهم',12=>'دوازدهم'];
    return $names[$grade] ?? extend_demo_digits((string)$grade);
}
function extend_demo_phone(): string {
    return extend_demo_digits('07' . random_int(100000000, 999999999));
}
function extend_demo_birth_date(int $grade): string {
    $year = (int)date('Y') - ($grade + 5) - random_int(0, 1);
    return sprintf('%04d-%02d-%02d', $year, random_int(1, 12), random_int(1, 28));
}

$boys = ['احمد', 'محمد', 'محمود', 'علی', 'حسین', 'حسن', 'عمر', 'عثمان', 'بلال', 'یوسف', 'ابراهیم', 'اسماعیل', 'فرهاد', 'فرید', 'وحید', 'جاوید', 'سهراب', 'نعیم', 'شکیب', 'حامد', 'سعید', 'عبدالله', 'مصطفی', 'ذبیح‌الله', 'خالد', 'یاسر', 'امین', 'نصیر', 'بشیر', 'تمیم'];
$girls = ['فاطمه', 'مریم', 'زهرا', 'عایشه', 'خدیجه', 'زینب', 'سارا', 'لیلا', 'فرشته', 'شکیلا', 'نازیه', 'رویا', 'مژگان', 'نگینه', 'سحر', 'ثریا', 'نرگس', 'حمیرا', 'لاله', 'مهتاب', 'سمیه', 'ریحانه', 'هدیه', 'ملیکه', 'شکیبا', 'مبینا', 'مرسل', 'فرزانه', 'تمنا', 'پریسا'];
$lastNames = ['احمدی', 'محمدی', 'حسینی', 'رحیمی', 'کریمی', 'عزیزی', 'حیدری', 'نوری', 'صادقی', 'قاسمی', 'موسوی', 'جعفری', 'فراهی', 'کاظمی', 'سادات', 'وفایی', 'محمودی', 'نظری', 'اکبری', 'اسحاقی', 'یوسفی', 'صفری', 'امیری', 'حکیمی', 'روستایی', 'پیمانی', 'شریفی', 'باقری'];

try {
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $required = ['users', 'students', 'school_years', 'class_sections', 'student_enrollments', 'student_enrollment_history', 'tuition_fee_rules', 'student_fee_charges', 'student_fee_payments', 'demo_seed_runs'];
    $missing = array_values(array_diff($required, $tables));
    if ($missing) {
        throw new RuntimeException('جدول‌های لازم موجود نیستند: ' . implode(', ', $missing) . '. ابتدا صفحه به‌روزرسانی پایگاه داده را بررسی کنید.');
    }

    $marker = $pdo->query("SELECT school_year_id FROM demo_seed_runs WHERE run_key = 'persian_school_demo_v1' LIMIT 1")->fetchColumn();
    if (!$marker) {
        throw new RuntimeException('نشان اجرای قبلی اطلاعات آزمایشی پیدا نشد. این صفحه فقط برای تکمیل مجموعه آزمایشی قبلی است.');
    }
    $yearId = (int)$marker;

    $countQuery = $pdo->prepare('SELECT COUNT(DISTINCT student_id) FROM student_enrollments WHERE school_year_id = ?');
    $countQuery->execute([$yearId]);
    $existingStudentCount = (int)$countQuery->fetchColumn();

    $yearQuery = $pdo->prepare('SELECT name FROM school_years WHERE id = ? LIMIT 1');
    $yearQuery->execute([$yearId]);
    $yearName = (string)($yearQuery->fetchColumn() ?: '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            throw new RuntimeException('درخواست معتبر نیست. صفحه را تازه‌سازی کنید.');
        }
        if (($_POST['confirm_extend'] ?? '') !== 'yes') {
            throw new RuntimeException('برای ادامه، تأیید تکمیل اطلاعات آزمایشی را علامت بزنید.');
        }
        if ($existingStudentCount >= $targetStudentCount) {
            throw new RuntimeException('سال آزمایشی هم‌اکنون حداقل ۵۰۰۰ شاگرد دارد؛ هیچ داده‌ای اضافه نشد.');
        }

        $pdo->beginTransaction();
        try {
            $adminId = (int)($_SESSION['user_id'] ?? 0);
            $adminCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'super_admin' AND is_active = 1 LIMIT 1");
            $adminCheck->execute([$adminId]);
            if (!$adminCheck->fetchColumn()) {
                throw new RuntimeException('حساب مدیر کل فعال پیدا نشد. دوباره وارد سیستم شوید.');
            }

            $sectionQuery = $pdo->prepare('SELECT id, grade, section_name FROM class_sections WHERE school_year_id = ? AND is_active = 1 ORDER BY grade, FIELD(section_name, "الف", "ب", "ج"), id');
            $sectionQuery->execute([$yearId]);
            $sections = $sectionQuery->fetchAll();
            if (count($sections) !== 36) {
                throw new RuntimeException('برای سال آزمایشی دقیقاً ۳۶ صنف فعال پیدا نشد؛ برای جلوگیری از ثبت اشتباه، عملیات لغو شد.');
            }

            $countBySection = $pdo->prepare('SELECT COUNT(DISTINCT student_id) FROM student_enrollments WHERE school_year_id = ? AND class_section_id = ?');
            $targets = [];
            $sectionCounts = [];
            foreach ($sections as $index => $section) {
                $countBySection->execute([$yearId, (int)$section['id']]);
                $current = (int)$countBySection->fetchColumn();
                $desired = $index < 32 ? 139 : 138;
                $sectionId = (int)$section['id'];
                $targets[$sectionId] = ['grade' => (int)$section['grade'], 'name' => (string)$section['section_name'], 'desired' => $desired, 'current' => $current];
                $sectionCounts[$sectionId] = 0;
            }

            $feeQuery = $pdo->prepare('SELECT id, grade_from, grade_to, amount, period_name FROM tuition_fee_rules WHERE school_year_id = ? ORDER BY grade_from');
            $feeQuery->execute([$yearId]);
            $feeRules = [];
            foreach ($feeQuery->fetchAll() as $rule) {
                for ($g = (int)$rule['grade_from']; $g <= (int)$rule['grade_to']; $g++) {
                    $feeRules[$g] = $rule;
                }
            }
            for ($grade = 1; $grade <= 12; $grade++) {
                if (!isset($feeRules[$grade])) {
                    throw new RuntimeException('قانون فیس پایه ' . $grade . ' برای سال آزمایشی پیدا نشد؛ عملیات لغو شد.');
                }
            }

            $insertStudent = $pdo->prepare('INSERT INTO students (student_number, first_name, last_name, father_name, grandfather_name, gender, date_of_birth, class_name, phone, guardian_name, guardian_phone, address, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
            $setStudentNumber = $pdo->prepare('UPDATE students SET student_number = ? WHERE id = ?');
            $insertEnrollment = $pdo->prepare("INSERT INTO student_enrollments (student_id, school_year_id, class_section_id, status, is_current, notes) VALUES (?, ?, ?, 'enrolled', 1, ?)");
            $insertHistory = $pdo->prepare("INSERT INTO student_enrollment_history (enrollment_id, student_id, school_year_id, from_section_id, to_section_id, changed_by, change_type, notes) VALUES (?, ?, ?, NULL, ?, ?, 'assigned', ?)");
            $insertCharge = $pdo->prepare('INSERT INTO student_fee_charges (student_id, school_year_id, fee_rule_id, period_name, amount_due, discount_amount) VALUES (?, ?, ?, ?, ?, 0)');
            $insertPayment = $pdo->prepare('INSERT INTO student_fee_payments (charge_id, amount, receipt_number, payment_method, received_at, notes, received_by) VALUES (?, ?, ?, ?, ?, ?, ?)');

            $added = 0;
            $paymentCount = 0;
            $fullPaymentCount = 0;
            $partialPaymentCount = 0;
            $unpaidCount = 0;

            foreach ($sections as $section) {
                $sectionId = (int)$section['id'];
                $grade = (int)$section['grade'];
                $className = extend_demo_grade_name($grade) . ' ' . (string)$section['section_name'];
                $need = max(0, $targets[$sectionId]['desired'] - $targets[$sectionId]['current']);
                $rule = $feeRules[$grade];

                for ($i = 0; $i < $need; $i++) {
                    $isFemale = random_int(0, 1) === 1;
                    $firstName = extend_demo_pick($isFemale ? $girls : $boys);
                    $lastName = extend_demo_pick($lastNames);
                    $fatherName = extend_demo_pick($boys);
                    $grandfatherName = extend_demo_pick($boys);
                    $phone = extend_demo_phone();
                    $insertStudent->execute([
                        'TMP-DEMO-' . strtoupper(bin2hex(random_bytes(8))),
                        $firstName, $lastName, $fatherName, $grandfatherName,
                        $isFemale ? 'female' : 'male', extend_demo_birth_date($grade), $className,
                        $phone, $fatherName . ' ' . $lastName, $phone,
                        'نشانی آزمایشی - صنف ' . $className
                    ]);
                    $studentId = (int)$pdo->lastInsertId();
                    $setStudentNumber->execute(['ST-' . str_pad((string)$studentId, 4, '0', STR_PAD_LEFT), $studentId]);

                    $notes = 'ثبت‌نام آزمایشی تکمیلی';
                    $insertEnrollment->execute([$studentId, $yearId, $sectionId, $notes]);
                    $enrollmentId = (int)$pdo->lastInsertId();
                    $insertHistory->execute([$enrollmentId, $studentId, $yearId, $sectionId, $adminId, $notes]);

                    $insertCharge->execute([$studentId, $yearId, (int)$rule['id'], (string)$rule['period_name'], (float)$rule['amount']]);
                    $chargeId = (int)$pdo->lastInsertId();

                    $choice = random_int(1, 100);
                    if ($choice <= 35) {
                        $paidAmount = (float)$rule['amount'];
                        $fullPaymentCount++;
                    } elseif ($choice <= 60) {
                        $paidAmount = (float)random_int(500, max(501, (int)$rule['amount'] - 1));
                        $partialPaymentCount++;
                    } else {
                        $paidAmount = 0.0;
                        $unpaidCount++;
                    }

                    if ($paidAmount > 0) {
                        $receipt = 'رسید-آزمایشی-' . extend_demo_digits((string)$studentId) . '-' . extend_demo_digits((string)random_int(100000, 999999));
                        $insertPayment->execute([
                            $chargeId, $paidAmount, $receipt, extend_demo_pick(['cash', 'bank', 'other']),
                            date('Y-m-d H:i:s', strtotime('-' . random_int(0, 120) . ' days')),
                            'پرداخت آزمایشی - ' . $firstName . ' ' . $lastName, $adminId
                        ]);
                        $paymentCount++;
                    }
                    $added++;
                }
                $sectionCounts[$sectionId] = $targets[$sectionId]['desired'];
            }

            $totalAfter = $existingStudentCount + $added;
            $pdo->commit();
            $success = 'اطلاعات تکمیلی با موفقیت وارد شد. اطلاعات قبلی حفظ شد و شاگردان جدید به همان سال آزمایشی اضافه شدند.';
            $summary = [
                'before' => $existingStudentCount,
                'added' => $added,
                'total' => $totalAfter,
                'payments' => $paymentCount,
                'full' => $fullPaymentCount,
                'partial' => $partialPaymentCount,
                'unpaid' => $unpaidCount,
                'year' => $yearName
            ];
            $existingStudentCount = $totalAfter;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
} catch (Throwable $exception) {
    if ($exception instanceof RuntimeException) {
        $error = $exception->getMessage();
    } else {
        error_log('Extend demo students: ' . $exception->getMessage());
        $error = 'تکمیل اطلاعات آزمایشی انجام نشد. خطا: ' . $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تکمیل شاگردان آزمایشی تا ۵۰۰۰ نفر</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
.extend-wrap{max-width:900px;margin:28px auto;padding:0 18px}.extend-card{background:#fff;border:1px solid #e1e7f0;border-radius:14px;padding:24px;margin:18px 0}.extend-alert{padding:13px 16px;border-radius:10px;margin:14px 0;line-height:1.9}.extend-alert.ok{background:#dcfce7;color:#166534}.extend-alert.bad{background:#fee2e2;color:#991b1b}.extend-alert.warn{background:#fff7ed;color:#9a3412}.extend-button{border:0;border-radius:9px;background:#1d4ed8;color:#fff;padding:13px 18px;font:inherit;font-weight:700;cursor:pointer}.extend-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}.extend-stat{padding:15px;border:1px solid #e1e7f0;border-radius:10px;background:#f8fafc}.extend-stat strong{display:block;font-size:1.3rem;margin-top:5px}.extend-check{display:flex;gap:10px;align-items:flex-start;margin:18px 0}.extend-check input{margin-top:7px}
</style>
</head>
<body class="profile-page">
<div class="admin-layout">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="admin-main">
<?php require __DIR__ . '/includes/topbar.php'; ?>
<div class="extend-wrap">
<header class="page-header"><p class="eyebrow">ابزار محلی آزمایشی</p><h1>تکمیل شاگردان آزمایشی تا ۵۰۰۰ نفر</h1><p class="muted">این ابزار فقط در Linux، از همین کامپیوتر و برای مدیر کل فعال است.</p></header>
<?php if ($success !== ''): ?><div class="extend-alert ok" role="status"><?=extend_demo_h($success)?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="extend-alert bad" role="alert"><?=extend_demo_h($error)?></div><?php endif; ?>
<section class="extend-card">
<h2>وضعیت سال آزمایشی</h2>
<div class="extend-grid">
<div class="extend-stat">شاگردان فعلی<strong><?=number_format($existingStudentCount)?></strong></div>
<div class="extend-stat">هدف<strong>۵۰۰۰</strong></div>
<div class="extend-stat">شاگردان جدید مورد نیاز<strong><?=number_format(max(0, $targetStudentCount - $existingStudentCount))?></strong></div>
</div>
<p>این عملیات اطلاعات قبلی را حذف نمی‌کند. فقط شاگردان کمبود را در همان سال آزمایشی اضافه می‌کند و برای هر شاگرد ثبت‌نام، فیس و وضعیت پرداخت نمونه می‌سازد.</p>
<?php if ($existingStudentCount >= $targetStudentCount): ?>
<div class="extend-alert ok">سال آزمایشی به ۵۰۰۰ شاگرد رسیده است. اطلاعات دیگری اضافه نمی‌شود.</div>
<?php else: ?>
<div class="extend-alert warn">پیش از ادامه، از پایگاه داده پشتیبان بگیرید. این عملیات فقط یک بار لازم است تا شمار شاگردان به ۵۰۰۰ برسد؛ اطلاعات واقعی حذف نمی‌شود.</div>
<form method="post">
<input type="hidden" name="csrf_token" value="<?=extend_demo_h(csrf_token())?>">
<label class="extend-check"><input type="checkbox" name="confirm_extend" value="yes" required><span>تأیید می‌کنم که می‌خواهم شاگردان باقی‌مانده را به سال آزمایشی موجود اضافه کنم.</span></label>
<button class="extend-button" type="submit">تکمیل تا ۵۰۰۰ شاگرد</button>
</form>
<?php endif; ?>
<?php if ($summary): ?>
<hr>
<h2>نتیجهٔ اجرا</h2>
<div class="extend-grid">
<div class="extend-stat">قبل از اجرا<strong><?=number_format((int)$summary['before'])?></strong></div>
<div class="extend-stat">شاگردان اضافه‌شده<strong><?=number_format((int)$summary['added'])?></strong></div>
<div class="extend-stat">مجموع نهایی<strong><?=number_format((int)$summary['total'])?></strong></div>
<div class="extend-stat">پرداخت‌های نمونه<strong><?=number_format((int)$summary['payments'])?></strong></div>
</div>
<?php endif; ?>
</section>
</div>
</main>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
