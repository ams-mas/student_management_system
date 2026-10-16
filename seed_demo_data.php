<?php
declare(strict_types=1);

/*
 * Local Linux-only demo data seeder.
 * Remove this file after testing the school management screens.
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
$summary = [];
$runAlreadyExists = false;
$runTableExists = false;
$requiredTables = [
    'users', 'students', 'school_years', 'teachers', 'class_sections',
    'student_enrollments', 'student_enrollment_history', 'tuition_fee_rules',
    'student_fee_charges', 'student_fee_payments'
];

function demo_h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function demo_pick(array $items): string {
    return $items[random_int(0, count($items) - 1)];
}
function demo_persian_digits(string $value): string {
    return strtr($value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
}
function demo_grade_name(int $grade): string {
    $names = [1=>'اول',2=>'دوم',3=>'سوم',4=>'چهارم',5=>'پنجم',6=>'ششم',7=>'هفتم',8=>'هشتم',9=>'نهم',10=>'دهم',11=>'یازدهم',12=>'دوازدهم'];
    return $names[$grade] ?? demo_persian_digits((string)$grade);
}
function demo_phone(): string {
    return demo_persian_digits('07' . random_int(100000000, 999999999));
}
function demo_birth_date(int $grade): string {
    $year = (int)date('Y') - ($grade + 5) - random_int(0, 1);
    $month = random_int(1, 12);
    $day = random_int(1, 28);
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

$boys = ['احمد', 'محمد', 'محمود', 'علی', 'حسین', 'حسن', 'عمر', 'عثمان', 'بلال', 'یوسف', 'ابراهیم', 'اسماعیل', 'فرهاد', 'فرید', 'وحید', 'جاوید', 'سهراب', 'نعیم', 'شکیب', 'حامد', 'سعید', 'عبدالله', 'مصطفی', 'ذبیح‌الله', 'خالد', 'یاسر', 'امین', 'نصیر', 'بشیر', 'تمیم'];
$girls = ['فاطمه', 'مریم', 'زهرا', 'عایشه', 'خدیجه', 'زینب', 'سارا', 'لیلا', 'فرشته', 'شکیلا', 'نازیه', 'رویا', 'مژگان', 'نگینه', 'سحر', 'ثریا', 'نرگس', 'حمیرا', 'لاله', 'مهتاب', 'سمیه', 'ریحانه', 'هدیه', 'ملیکه', 'شکیبا', 'مبینا', 'مرسل', 'فرزانه', 'تمنا', 'پریسا'];
$lastNames = ['احمدی', 'محمدی', 'حسینی', 'رحیمی', 'کریمی', 'عزیزی', 'حیدری', 'نوری', 'صادقی', 'قاسمی', 'موسوی', 'جعفری', 'فراهی', 'کاظمی', 'سادات', 'وفایی', 'محمودی', 'نظری', 'اکبری', 'اسحاقی', 'یوسفی', 'صفری', 'امیری', 'حکیمی', 'روستایی', 'پیمانی', 'شریفی', 'باقری'];
$teacherNames = ['عبدالرحمن احمدی', 'فریبا محمدی', 'حبیب‌الله حسینی', 'مژگان رحیمی', 'عبدالکریم کریمی', 'زلیخا عزیزی', 'احسان حیدری', 'لیلا نوری', 'عبدالواحد صادقی', 'شکیبا قاسمی', 'نعمت‌الله موسوی', 'فرزانه جعفری', 'سیدجمال فراهی', 'مریم کاظمی', 'حکمت‌الله سادات', 'رویا وفایی', 'عبدالناصر محمودی', 'نرگس نظری', 'بشیر اکبری', 'مهتاب اسحاقی', 'وحید یوسفی', 'ثریا صفری', 'جاوید امیری', 'پریسا حکیمی'];

try {
    $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff($requiredTables, $existing));
    if ($missing) {
        throw new RuntimeException('جدول‌های لازم وجود ندارند: ' . implode(', ', $missing) . '. ابتدا database_updates.php را باز کنید و تمام به‌روزرسانی‌ها را اجرا کنید.');
    }

    $runTableExists = in_array('demo_seed_runs', $existing, true);
    if ($runTableExists) {
        $runAlreadyExists = (int)$pdo->query('SELECT COUNT(*) FROM demo_seed_runs WHERE run_key = "persian_school_demo_v1"')->fetchColumn() > 0;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            throw new RuntimeException('درخواست معتبر نیست. صفحه را تازه‌سازی کنید.');
        }
        if (($_POST['confirm_demo'] ?? '') !== 'yes') {
            throw new RuntimeException('برای ادامه، تأیید اجرای اطلاعات آزمایشی را علامت بزنید.');
        }
        if ($runAlreadyExists) {
            throw new RuntimeException('اطلاعات آزمایشی قبلاً وارد شده است. برای جلوگیری از رکوردهای تکراری، اجرای دوباره غیرفعال است.');
        }

        // Create a one-time marker. It is intentionally separate from real school records.
        $pdo->exec("CREATE TABLE IF NOT EXISTS demo_seed_runs (
            run_key VARCHAR(80) NOT NULL PRIMARY KEY,
            school_year_id BIGINT UNSIGNED NULL,
            student_count INT UNSIGNED NOT NULL DEFAULT 0,
            class_count INT UNSIGNED NOT NULL DEFAULT 0,
            teacher_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->beginTransaction();
        try {
            $adminId = (int)($_SESSION['user_id'] ?? 0);
            $adminCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'super_admin' AND is_active = 1 LIMIT 1");
            $adminCheck->execute([$adminId]);
            if (!$adminCheck->fetchColumn()) {
                throw new RuntimeException('حساب مدیر کل فعال پیدا نشد. دوباره وارد سیستم شوید.');
            }

            $markerCheck = $pdo->prepare('SELECT run_key FROM demo_seed_runs WHERE run_key = ? FOR UPDATE');
            $markerCheck->execute(['persian_school_demo_v1']);
            if ($markerCheck->fetchColumn()) {
                throw new RuntimeException('اطلاعات آزمایشی قبلاً وارد شده است.');
            }

            // Isolate the demo records in their own year so real classes and enrollments are not mixed.
            // The previous school year remains in the database and can be reactivated later in school.php.
            $baseYear = (int)date('Y');
            $yearName = 'سال آزمایشی ' . demo_persian_digits((string)($baseYear + 621)) . '-' . demo_persian_digits((string)($baseYear + 622));
            $suffix = 2;
            $checkYear = $pdo->prepare('SELECT id FROM school_years WHERE name = ? LIMIT 1');
            while (true) {
                $checkYear->execute([$yearName]);
                if (!$checkYear->fetchColumn()) break;
                $yearName = 'سال آزمایشی ' . demo_persian_digits((string)($baseYear + 621)) . '-' . demo_persian_digits((string)($baseYear + 622)) . ' (' . demo_persian_digits((string)$suffix++) . ')';
            }
            $pdo->exec('UPDATE school_years SET is_active = 0 WHERE is_active = 1');
            $insertYear = $pdo->prepare('INSERT INTO school_years (name, starts_on, ends_on, is_active, is_closed) VALUES (?, ?, ?, 1, 0)');
            $insertYear->execute([$yearName, date('Y') . '-08-01', ((int)date('Y') + 1) . '-06-30']);
            $yearId = (int)$pdo->lastInsertId();

            // Create 12 teachers. They are shared across the three sections per grade.
            $teacherIds = [];
            for ($i = 0; $i < 12; $i++) {
                $teacherName = $teacherNames[$i % count($teacherNames)];
                if ($i >= count($teacherNames)) $teacherName .= ' ' . ($i + 1);
                $insertTeacher = $pdo->prepare('INSERT INTO teachers (full_name, phone, email, user_id, is_active) VALUES (?, ?, ?, NULL, 1)');
                $insertTeacher->execute([$teacherName, demo_phone(), '']);
                $teacherIds[] = (int)$pdo->lastInsertId();
            }

            $sections = [];
            $sectionNames = ['الف', 'ب', 'ج'];
            $findSection = $pdo->prepare('SELECT id, teacher_id FROM class_sections WHERE school_year_id = ? AND grade = ? AND section_name = ? LIMIT 1');
            $insertSection = $pdo->prepare('INSERT INTO class_sections (school_year_id, grade, section_name, teacher_id, is_active) VALUES (?, ?, ?, ?, 1)');
            $assignTeacher = $pdo->prepare('UPDATE class_sections SET teacher_id = ? WHERE id = ? AND teacher_id IS NULL');
            for ($grade = 1; $grade <= 12; $grade++) {
                foreach ($sectionNames as $sectionIndex => $sectionName) {
                    $findSection->execute([$yearId, $grade, $sectionName]);
                    $foundSection = $findSection->fetch();
                    if ($foundSection) {
                        $sectionId = (int)$foundSection['id'];
                        if ($foundSection['teacher_id'] === null) {
                            $assignTeacher->execute([$teacherIds[$grade - 1], $sectionId]);
                        }
                    } else {
                        $insertSection->execute([$yearId, $grade, $sectionName, $teacherIds[$grade - 1]]);
                        $sectionId = (int)$pdo->lastInsertId();
                    }
                    $sections[$grade][$sectionName] = $sectionId;
                }
            }

            // Create clearly labelled sample annual fee rules. They do not overwrite existing rules.
            $periodName = 'فیس سالانه آزمایشی ' . demo_persian_digits((string)((int)date('Y') + 621));
            $feeRules = [];
            $feeRanges = [[1, 3, 5000], [4, 6, 6000], [7, 12, 7000]];
            $findFeeRule = $pdo->prepare('SELECT id, amount FROM tuition_fee_rules WHERE school_year_id = ? AND period_name = ? AND grade_from = ? AND grade_to = ? LIMIT 1');
            $insertFeeRule = $pdo->prepare('INSERT INTO tuition_fee_rules (school_year_id, period_name, grade_from, grade_to, amount, is_active, due_date, notes) VALUES (?, ?, ?, ?, ?, 1, ?, ?)');
            foreach ($feeRanges as [$from, $to, $amount]) {
                $findFeeRule->execute([$yearId, $periodName, $from, $to]);
                $existingRule = $findFeeRule->fetch();
                if ($existingRule) {
                    if ((float)$existingRule['amount'] !== (float)$amount) {
                        throw new RuntimeException('یک قانون فیس آزمایشی با همین نام ولی مبلغ متفاوت وجود دارد. آن را بررسی کنید.');
                    }
                    $ruleId = (int)$existingRule['id'];
                } else {
                    $insertFeeRule->execute([$yearId, $periodName, $from, $to, $amount, date('Y-m-d', strtotime('+30 days')), 'اطلاعات آزمایشی؛ برای بررسی صفحه فیس']);
                    $ruleId = (int)$pdo->lastInsertId();
                }
                $feeRules[] = ['id' => $ruleId, 'from' => $from, 'to' => $to, 'amount' => $amount];
            }

            $insertStudent = $pdo->prepare('INSERT INTO students (student_number, first_name, last_name, father_name, grandfather_name, gender, date_of_birth, class_name, phone, guardian_name, guardian_phone, address, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
            $setStudentNumber = $pdo->prepare('UPDATE students SET student_number = ? WHERE id = ?');
            $insertEnrollment = $pdo->prepare("INSERT INTO student_enrollments (student_id, school_year_id, class_section_id, status, is_current, notes) VALUES (?, ?, ?, 'enrolled', 1, ?)");
            $insertHistory = $pdo->prepare("INSERT INTO student_enrollment_history (enrollment_id, student_id, school_year_id, from_section_id, to_section_id, changed_by, change_type, notes) VALUES (?, ?, ?, NULL, ?, ?, 'assigned', ?)");
            $insertCharge = $pdo->prepare('INSERT INTO student_fee_charges (student_id, school_year_id, fee_rule_id, period_name, amount_due, discount_amount) VALUES (?, ?, ?, ?, ?, 0)');
            $insertPayment = $pdo->prepare('INSERT INTO student_fee_payments (charge_id, amount, receipt_number, payment_method, received_at, notes, received_by) VALUES (?, ?, ?, ?, ?, ?, ?)');

            $studentCount = 0;
            $paymentCount = 0;
            $fullPaymentCount = 0;
            $partialPaymentCount = 0;
            $unpaidCount = 0;
            $feeRuleByGrade = [];
            foreach ($feeRules as $rule) {
                for ($grade = $rule['from']; $grade <= $rule['to']; $grade++) {
                    $feeRuleByGrade[$grade] = $rule;
                }
            }

            for ($grade = 1; $grade <= 12; $grade++) {
                foreach ($sectionNames as $sectionName) {
                    $sectionId = $sections[$grade][$sectionName];
                    for ($numberInClass = 1; $numberInClass <= 25; $numberInClass++) {
                        $isFemale = random_int(0, 1) === 1;
                        $firstName = demo_pick($isFemale ? $girls : $boys);
                        $lastName = demo_pick($lastNames);
                        $fatherName = demo_pick($boys);
                        $grandfatherName = demo_pick($boys);
                        $fullName = $firstName . ' ' . $lastName;
                        $className = demo_grade_name($grade) . ' ' . $sectionName;
                        $phone = demo_phone();
                        $temporaryNumber = 'TMP-DEMO-' . strtoupper(bin2hex(random_bytes(8)));
                        $insertStudent->execute([
                            $temporaryNumber, $firstName, $lastName, $fatherName, $grandfatherName,
                            $isFemale ? 'female' : 'male', demo_birth_date($grade), $className,
                            $phone, $fatherName . ' ' . $lastName, $phone,
                            'نشانی آزمایشی - صنف ' . $className
                        ]);
                        $studentId = (int)$pdo->lastInsertId();
                        $studentNumber = 'ST-' . str_pad((string)$studentId, 4, '0', STR_PAD_LEFT);
                        $setStudentNumber->execute([$studentNumber, $studentId]);

                        $insertEnrollment->execute([$studentId, $yearId, $sectionId, 'ثبت‌نام آزمایشی']);
                        $enrollmentId = (int)$pdo->lastInsertId();
                        $insertHistory->execute([$enrollmentId, $studentId, $yearId, $sectionId, $adminId, 'ثبت‌نام آزمایشی']);

                        $rule = $feeRuleByGrade[$grade];
                        $insertCharge->execute([$studentId, $yearId, $rule['id'], $periodName, $rule['amount']]);
                        $chargeId = (int)$pdo->lastInsertId();

                        // Random mix: fully paid, partially paid, and unpaid students.
                        $paymentChoice = random_int(1, 100);
                        if ($paymentChoice <= 35) {
                            $paidAmount = (float)$rule['amount'];
                            $fullPaymentCount++;
                        } elseif ($paymentChoice <= 60) {
                            $paidAmount = (float)random_int(500, (int)$rule['amount'] - 1);
                            $partialPaymentCount++;
                        } else {
                            $paidAmount = 0.0;
                            $unpaidCount++;
                        }
                        if ($paidAmount > 0) {
                            $receipt = 'رسید-آزمایشی-' . demo_persian_digits((string)$studentId) . '-' . demo_persian_digits((string)random_int(100000, 999999));
                            $methods = ['cash', 'bank', 'other'];
                            $daysAgo = random_int(0, 120);
                            $receivedAt = date('Y-m-d H:i:s', strtotime('-' . $daysAgo . ' days'));
                            $insertPayment->execute([
                                $chargeId, $paidAmount, $receipt, demo_pick($methods), $receivedAt,
                                'پرداخت آزمایشی - ' . $fullName, $adminId
                            ]);
                            $paymentCount++;
                        }
                        $studentCount++;
                    }
                }
            }

            $saveRun = $pdo->prepare('INSERT INTO demo_seed_runs (run_key, school_year_id, student_count, class_count, teacher_count) VALUES (?, ?, ?, ?, ?)');
            $saveRun->execute(['persian_school_demo_v1', $yearId, $studentCount, 36, count($teacherIds)]);
            $pdo->commit();

            $success = 'اطلاعات آزمایشی با موفقیت وارد سیستم شد. می‌توانید صفحه صنف‌ها، شاگردان، آموزگاران و فیس را باز کنید.';
            $summary = [
                'year_id' => $yearId,
                'students' => $studentCount,
                'classes' => 36,
                'teachers' => count($teacherIds),
                'fee_rules' => count($feeRules),
                'full_paid' => $fullPaymentCount,
                'partial_paid' => $partialPaymentCount,
                'unpaid' => $unpaidCount,
                'payments' => $paymentCount
            ];
            $runAlreadyExists = true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    if ($runAlreadyExists && !$summary) {
        $q = $pdo->query("SELECT school_year_id, student_count, class_count, teacher_count, created_at FROM demo_seed_runs WHERE run_key = 'persian_school_demo_v1' LIMIT 1");
        $savedRun = $q->fetch();
        if ($savedRun) {
            $summary = [
                'year_id' => (int)$savedRun['school_year_id'],
                'students' => (int)$savedRun['student_count'],
                'classes' => (int)$savedRun['class_count'],
                'teachers' => (int)$savedRun['teacher_count'],
                'created_at' => (string)$savedRun['created_at']
            ];
        }
    }
} catch (Throwable $exception) {
    if ($exception instanceof RuntimeException) {
        $error = $exception->getMessage();
    } else {
        error_log('Demo data seeder: ' . $exception->getMessage());
        $error = 'واردکردن اطلاعات آزمایشی انجام نشد. جزئیات خطا در گزارش PHP/Apache ثبت شده است: ' . $exception->getMessage();
    }
}

?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ورود اطلاعات آزمایشی مکتب</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
.demo-wrap{max-width:1000px;margin:28px auto;padding:0 18px}.demo-card{background:#fff;border:1px solid #e1e7f0;border-radius:14px;padding:24px;margin:18px 0}.demo-alert{padding:13px 16px;border-radius:10px;margin:14px 0;line-height:1.9}.demo-alert.ok{background:#dcfce7;color:#166534}.demo-alert.bad{background:#fee2e2;color:#991b1b}.demo-alert.warn{background:#fff7ed;color:#9a3412}.demo-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.demo-stat{padding:15px;border:1px solid #e1e7f0;border-radius:10px;background:#f8fafc}.demo-stat strong{display:block;font-size:1.35rem;margin-top:5px}.demo-list{line-height:2}.demo-submit{border:0;border-radius:9px;background:#1d4ed8;color:#fff;padding:13px 18px;font:inherit;font-weight:700;cursor:pointer}.demo-check{display:flex;gap:10px;align-items:flex-start;margin:18px 0}.demo-check input{margin-top:7px}
</style>
</head>
<body class="profile-page">
<div class="admin-layout">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="admin-main">
<?php require __DIR__ . '/includes/topbar.php'; ?>
<div class="demo-wrap">
<header class="page-header"><p class="eyebrow">ابزار محلی آزمایشی</p><h1>واردکردن اطلاعات آزمایشی مکتب</h1><p class="muted">این صفحه فقط روی Linux و از همان کامپیوتر قابل اجرا است و فقط برای حساب مدیر کل نمایش داده می‌شود.</p></header>
<?php if ($success !== ''): ?><div class="demo-alert ok" role="status"><?=demo_h($success)?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="demo-alert bad" role="alert"><?=demo_h($error)?></div><?php endif; ?>

<section class="demo-card">
<h2>اطلاعاتی که ساخته می‌شود</h2>
<div class="demo-grid">
<div class="demo-stat">صنف‌ها<strong>۳۶ صنف</strong><small>پایه اول تا دوازدهم، هر پایه الف، ب و ج</small></div>
<div class="demo-stat">شاگردان<strong>۹۰۰ شاگرد</strong><small>۲۵ شاگرد در هر صنف</small></div>
<div class="demo-stat">آموزگاران<strong>۱۲ آموزگار</strong><small>هر آموزگار چند صنف را پوشش می‌دهد</small></div>
<div class="demo-stat">فیس سالانه<strong>۵۰۰۰ / ۶۰۰۰ / ۷۰۰۰</strong><small>بر اساس پایه تحصیلی</small></div>
</div>
<ul class="demo-list">
<li>نام‌های تصادفی فارسی/دری برای شاگردان دختر و پسر، همراه با نام پدر، نام پدرکلان و شماره تماس نمونه.</li>
<li>فیس پایه‌های ۱ تا ۳: ۵۰۰۰؛ پایه‌های ۴ تا ۶: ۶۰۰۰؛ پایه‌های ۷ تا ۱۲: ۷۰۰۰.</li>
<li>پرداخت‌ها به‌صورت تصادفی: تعدادی کامل، تعدادی قسمی و تعدادی بدون پرداخت. رسیدهای نمونه نیز ساخته می‌شود.</li>
<li>اطلاعات در یک سال تحصیلی جداگانه با نام فارسی «سال آزمایشی» ذخیره می‌شود؛ سال فعلی قبلی حفظ می‌شود ولی برای نمایش داده‌های نمونه، سال آزمایشی فعال خواهد شد. بعداً می‌توانید سال قبلی را از صفحه تنظیم سال تحصیلی دوباره فعال کنید.</li>
</ul>
<?php if ($runAlreadyExists): ?>
<div class="demo-alert warn"><strong>اطلاعات آزمایشی قبلاً وارد شده است.</strong> برای جلوگیری از رکوردهای تکراری، اجرای دوباره قفل شده است.</div>
<?php if ($summary): ?>
<div class="demo-grid">
<div class="demo-stat">شاگردان ثبت‌شده<strong><?=number_format((int)$summary['students'])?></strong></div>
<div class="demo-stat">صنف‌ها<strong><?=number_format((int)$summary['classes'])?></strong></div>
<div class="demo-stat">آموزگاران<strong><?=number_format((int)$summary['teachers'])?></strong></div>
<?php if (isset($summary['full_paid'])): ?>
<div class="demo-stat">پرداخت کامل<strong><?=number_format((int)$summary['full_paid'])?></strong></div>
<div class="demo-stat">پرداخت قسمی<strong><?=number_format((int)$summary['partial_paid'])?></strong></div>
<div class="demo-stat">بدون پرداخت<strong><?=number_format((int)$summary['unpaid'])?></strong></div>
<?php endif; ?>
</div>
<?php endif; ?>
<?php else: ?>
<div class="demo-alert warn"><strong>توجه:</strong> این عملیات ۹۰۰ شاگرد، ۳۶ صنف، ۱۲ آموزگار و سوابق فیس/پرداخت نمونه ایجاد می‌کند. اطلاعات فعلی حذف نمی‌شود؛ اما سال تحصیلی آزمایشی فعال می‌شود تا صفحات سیستم داده‌های نمونه را نمایش دهند. سال قبلی در سوابق باقی می‌ماند و بعداً می‌توانید آن را دوباره فعال کنید. برای جلوگیری از داده‌های تکراری، این صفحه فقط یک بار قابل اجرا است.</div>
<form method="post">
<input type="hidden" name="csrf_token" value="<?=demo_h(csrf_token())?>">
<label class="demo-check"><input type="checkbox" name="confirm_demo" value="yes" required><span>تأیید می‌کنم که می‌خواهم اطلاعات آزمایشی در پایگاه داده فعلی وارد شود.</span></label>
<button class="demo-submit" type="submit">ساخت و واردکردن اطلاعات آزمایشی</button>
</form>
<?php endif; ?>
</section>
<p class="muted">پس از بررسی صفحات سیستم، فایل <code>seed_demo_data.php</code> را حذف کنید. اطلاعات آزمایشی به‌صورت واقعی در پایگاه داده ذخیره می‌شوند.</p>
</div>
</main>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
