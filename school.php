<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_roles(['super_admin']);
require_once __DIR__ . '/config/database.php';

$pdo = database_connection();
$error = '';
$success = '';

function school_settings_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function school_settings_id(string $key): int
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT);
    return $value && $value > 0 ? $value : 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('درخواست معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.');
    }

    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create_year') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $startsOn = trim((string) ($_POST['starts_on'] ?? ''));
            $endsOn = trim((string) ($_POST['ends_on'] ?? ''));

            if ($name === '' || mb_strlen($name) > 40) {
                throw new RuntimeException('نام سال تحصیلی الزامی است و نباید بیشتر از ۴۰ نویسه باشد.');
            }
            if ($startsOn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn)) {
                throw new RuntimeException('تاریخ شروع معتبر نیست.');
            }
            if ($endsOn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsOn)) {
                throw new RuntimeException('تاریخ پایان معتبر نیست.');
            }
            if ($startsOn !== '' && $endsOn !== '' && $endsOn < $startsOn) {
                throw new RuntimeException('تاریخ پایان باید بعد از تاریخ شروع باشد.');
            }

            $pdo->beginTransaction();
            $pdo->exec('UPDATE school_years SET is_active = 0 WHERE is_active = 1');
            $stmt = $pdo->prepare('INSERT INTO school_years (name, starts_on, ends_on, is_active, is_closed) VALUES (?, ?, ?, 1, 0)');
            $stmt->execute([$name, $startsOn !== '' ? $startsOn : null, $endsOn !== '' ? $endsOn : null]);
            $pdo->commit();
            $success = 'سال تحصیلی ساخته شد و به‌عنوان سال تحصیلی فعلی فعال گردید.';
        } elseif ($action === 'activate_year') {
            $yearId = school_settings_id('year_id');
            if (!$yearId) {
                throw new RuntimeException('سال تحصیلی انتخاب‌شده معتبر نیست.');
            }

            $pdo->beginTransaction();
            $check = $pdo->prepare('SELECT is_closed FROM school_years WHERE id = ? FOR UPDATE');
            $check->execute([$yearId]);
            $year = $check->fetch();
            if (!$year) {
                throw new RuntimeException('سال تحصیلی پیدا نشد.');
            }
            if ((int) $year['is_closed'] === 1) {
                throw new RuntimeException('سال تحصیلی بسته‌شده را نمی‌توان فعال کرد.');
            }

            $pdo->exec('UPDATE school_years SET is_active = 0 WHERE is_active = 1');
            $activate = $pdo->prepare('UPDATE school_years SET is_active = 1 WHERE id = ?');
            $activate->execute([$yearId]);
            $pdo->commit();
            $success = 'سال تحصیلی انتخاب‌شده به‌عنوان سال فعلی فعال شد.';
        } elseif ($action === 'close_year') {
            $yearId = school_settings_id('year_id');
            if (!$yearId) {
                throw new RuntimeException('سال تحصیلی انتخاب‌شده معتبر نیست.');
            }
            $stmt = $pdo->prepare('UPDATE school_years SET is_closed = 1, is_active = 0 WHERE id = ?');
            $stmt->execute([$yearId]);
            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare('SELECT id FROM school_years WHERE id = ?');
                $check->execute([$yearId]);
                if (!$check->fetch()) {
                    throw new RuntimeException('سال تحصیلی پیدا نشد.');
                }
            }
            $success = 'سال تحصیلی بسته شد. برای ادامه کار، یک سال باز را فعال کنید.';
        } else {
            throw new RuntimeException('عملیات نامعتبر است.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof PDOException && $exception->getCode() === '23000') {
            $error = 'این نام سال تحصیلی قبلاً ثبت شده است.';
        } elseif ($exception instanceof RuntimeException) {
            $error = $exception->getMessage();
        } else {
            error_log('School year settings error: ' . $exception->getMessage());
            $error = 'ذخیره تنظیمات انجام نشد. ابتدا مطمئن شوید به‌روزرسانی پایگاه داده اجرا شده است.';
        }
    }
}

$years = $pdo->query('SELECT id, name, starts_on, ends_on, is_active, is_closed, created_at FROM school_years ORDER BY starts_on DESC, id DESC')->fetchAll();
$currentYear = null;
foreach ($years as $year) {
    if ((int) $year['is_active'] === 1) {
        $currentYear = $year;
        break;
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تنظیمات مدرسه | سال تحصیلی</title>
    <link rel="stylesheet" href="assets/css/style.css">
<style>
        .year-settings-wrap{max-width:1050px;margin:28px auto;padding:0 18px}
        .year-settings-card{background:#fff;border:1px solid #e1e7f0;border-radius:14px;padding:22px;margin:18px 0}
        .year-settings-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;align-items:end}
        .year-settings-form label{display:block;font-weight:700;margin-bottom:6px}
        .year-settings-form input{width:100%;box-sizing:border-box;padding:11px;border:1px solid #ccd5e2;border-radius:8px;font:inherit;min-height:43px}
        .year-settings-form button{min-height:43px}
        .year-current{border-right:4px solid #16a34a;background:#f0fdf4;padding:15px;border-radius:10px;margin:16px 0}
        .year-alert{padding:13px 15px;border-radius:9px;margin:15px 0;background:#dcfce7;color:#166534}
        .year-alert.error{background:#fee2e2;color:#991b1b}
        .year-table-wrap{overflow:auto}
        .year-table{width:100%;border-collapse:collapse}
        .year-table th,.year-table td{padding:12px;text-align:right;border-bottom:1px solid #e5eaf1;white-space:nowrap}
        .year-actions{display:flex;gap:8px;align-items:center}
        .year-actions form{margin:0}
        .year-muted{color:#667085;font-size:14px;line-height:1.9}
    </style>
</head>
<body class="profile-page">
<div class="admin-layout">
    

    <?php require __DIR__ . '/includes/sidebar.php'; ?>
        <main class="admin-main">
            <?php require __DIR__ . '/includes/topbar.php'; ?>
        <div class="year-settings-wrap">
            <header class="page-header">
                <div>
                    <p class="eyebrow">تنظیمات مدرسه</p>
                    <h1>تنظیم سال تحصیلی</h1>
                    <p class="muted">در این صفحه فقط سال تحصیلی را تعریف و سال فعلی را انتخاب می‌کنید. مدیریت صنف‌ها و دانش‌آموزان در این صفحه انجام نمی‌شود.</p>
                </div>
            </header>

            <?php if ($success !== ''): ?><div class="year-alert" role="status"><?= school_settings_h($success) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="year-alert error" role="alert"><?= school_settings_h($error) ?></div><?php endif; ?>

            <section class="year-settings-card">
                <h2>سال تحصیلی فعلی</h2>
                <?php if ($currentYear !== null): ?>
                    <div class="year-current">
                        <strong><?= school_settings_h($currentYear['name']) ?></strong>
                        <div class="year-muted">شروع: <?php if (!empty($currentYear['starts_on'])): ?><span data-persian-display-date="<?= school_settings_h($currentYear['starts_on']) ?>"></span><?php else: ?>تعیین نشده<?php endif; ?> · پایان: <?php if (!empty($currentYear['ends_on'])): ?><span data-persian-display-date="<?= school_settings_h($currentYear['ends_on']) ?>"></span><?php else: ?>تعیین نشده<?php endif; ?></div>
                        <div>وضعیت: فعال</div>
                    </div>
                <?php else: ?>
                    <p class="year-muted">هنوز سال تحصیلی فعالی تعیین نشده است. یک سال جدید بسازید تا به‌صورت خودکار فعال شود.</p>
                <?php endif; ?>
            </section>

            <section class="year-settings-card">
                <h2>تعریف سال تحصیلی جدید</h2>
                <p class="year-muted">پس از ثبت، سال جدید به‌صورت خودکار سال فعلی می‌شود. سال‌های قبلی حذف نمی‌شوند و برای حفظ سوابق باقی می‌مانند.</p>
                <form method="post" class="year-settings-form">
                    <input type="hidden" name="csrf_token" value="<?= school_settings_h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_year">
                    <div><label for="year-name">نام سال تحصیلی</label><input id="year-name" name="name" required maxlength="40" placeholder="مثلاً ۱۴۰۵-۱۴۰۶"></div>
                    <div><label for="year-start">تاریخ شروع (اختیاری)</label><input id="year-start" type="date" data-persian-date name="starts_on"></div>
                    <div><label for="year-end">تاریخ پایان (اختیاری)</label><input id="year-end" type="date" data-persian-date name="ends_on"></div>
                    <div><button type="submit" class="button button-primary">ساخت و فعال‌سازی سال</button></div>
                </form>
            </section>

            <section class="year-settings-card">
                <h2>سوابق سال‌های تحصیلی</h2>
                <div class="year-table-wrap">
                    <table class="year-table">
                        <thead><tr><th>نام سال</th><th>شروع</th><th>پایان</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                        <tbody>
                        <?php if ($years === []): ?>
                            <tr><td colspan="5">هنوز سال تحصیلی ثبت نشده است.</td></tr>
                        <?php else: foreach ($years as $year): ?>
                            <tr>
                                <td><?= school_settings_h($year['name']) ?></td>
                                <td><?php if (!empty($year['starts_on'])): ?><span data-persian-display-date="<?= school_settings_h($year['starts_on']) ?>"></span><?php else: ?>—<?php endif; ?></td>
                                <td><?php if (!empty($year['ends_on'])): ?><span data-persian-display-date="<?= school_settings_h($year['ends_on']) ?>"></span><?php else: ?>—<?php endif; ?></td>
                                <td><?= (int) $year['is_active'] === 1 ? 'سال فعلی' : ((int) $year['is_closed'] === 1 ? 'بسته' : 'غیرفعال') ?></td>
                                <td><div class="year-actions">
                                    <?php if ((int) $year['is_active'] !== 1 && (int) $year['is_closed'] !== 1): ?>
                                        <form method="post"><input type="hidden" name="csrf_token" value="<?= school_settings_h(csrf_token()) ?>"><input type="hidden" name="year_id" value="<?= (int) $year['id'] ?>"><button type="submit" name="action" value="activate_year" class="button button-secondary">انتخاب به‌عنوان سال فعلی</button></form>
                                    <?php endif; ?>
                                    <?php if ((int) $year['is_closed'] !== 1): ?>
                                        <form method="post" onsubmit="return confirm('سال تحصیلی بسته شود؟ این کار آن را از سال فعلی خارج می‌کند.');"><input type="hidden" name="csrf_token" value="<?= school_settings_h(csrf_token()) ?>"><input type="hidden" name="year_id" value="<?= (int) $year['id'] ?>"><button type="submit" name="action" value="close_year" class="button button-secondary">بستن سال</button></form>
                                    <?php endif; ?>
                                </div></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <p class="year-muted">این صفحه فقط مسئول تعریف و انتخاب سال تحصیلی است. صنف‌ها، آموزگاران و ثبت‌نام دانش‌آموزان در بخش‌های جداگانه مدیریت خواهند شد.</p>
        </div>
    </main>
</div>
<link rel="stylesheet" href="assets/css/persian-calendar.css?v=20261012-calendar-spacing">
<script src="assets/js/persian-calendar.js"></script>
<script src="assets/js/app.js"></script>
    </body>
</html>
