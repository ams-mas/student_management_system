<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_roles(['super_admin']);
require_once __DIR__ . '/config/database.php';

$pdo = database_connection();
$error = '';
$success = '';
function school_config_h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
$defaults = [
    'school_name' => 'نام مدرسه',
    'school_address' => '',
    'school_email' => '',
    'school_phone_1' => '',
    'school_phone_2' => '',
    'logo_path' => null,
];
$stmt = $pdo->query('SELECT * FROM school_configuration WHERE id = 1 LIMIT 1');
$settings = $stmt->fetch() ?: $defaults;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('درخواست معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.');
    }
    try {
        $name = trim((string)($_POST['school_name'] ?? ''));
        $address = trim((string)($_POST['school_address'] ?? ''));
        $email = trim((string)($_POST['school_email'] ?? ''));
        $phone1 = trim((string)($_POST['school_phone_1'] ?? ''));
        $phone2 = trim((string)($_POST['school_phone_2'] ?? ''));
        if ($name === '' || mb_strlen($name) > 180) {
            throw new RuntimeException('نام مدرسه الزامی است و نباید بیشتر از ۱۸۰ نویسه باشد.');
        }
        if (mb_strlen($address) > 500 || mb_strlen($phone1) > 50 || mb_strlen($phone2) > 50) {
            throw new RuntimeException('آدرس یا شماره تلفن بیش از حد طولانی است.');
        }
        if ($email !== '' && (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw new RuntimeException('آدرس ایمیل معتبر نیست.');
        }

        $oldLogo = (string)($settings['logo_path'] ?? '');
        $logoPath = $oldLogo !== '' ? $oldLogo : null;
        $upload = $_FILES['school_logo'] ?? null;
        if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int)$upload['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('بارگذاری لوگو انجام نشد. دوباره تلاش کنید.');
            }
            if ((int)$upload['size'] > 3 * 1024 * 1024) {
                throw new RuntimeException('حجم لوگو نباید بیشتر از ۳ مگابایت باشد.');
            }
            $tmp = (string)($upload['tmp_name'] ?? '');
            if (!is_uploaded_file($tmp)) {
                throw new RuntimeException('فایل بارگذاری‌شده معتبر نیست.');
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmp);
            $extensions = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
            if (!isset($extensions[$mime])) {
                throw new RuntimeException('فرمت لوگو باید JPG، PNG یا WEBP باشد.');
            }
            $uploadDir = __DIR__ . '/uploads/school-logo';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('پوشه ذخیره لوگو ساخته نشد. دسترسی پوشه uploads را بررسی کنید.');
            }
            $fileName = 'school-logo-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
            if (!move_uploaded_file($tmp, $uploadDir . '/' . $fileName)) {
                throw new RuntimeException('ذخیره لوگو انجام نشد.');
            }
            $logoPath = 'uploads/school-logo/' . $fileName;
        }

        $save = $pdo->prepare('INSERT INTO school_configuration (id, school_name, school_address, school_email, school_phone_1, school_phone_2, logo_path) VALUES (1, :name, :address, :email, :phone1, :phone2, :logo) ON DUPLICATE KEY UPDATE school_name=VALUES(school_name), school_address=VALUES(school_address), school_email=VALUES(school_email), school_phone_1=VALUES(school_phone_1), school_phone_2=VALUES(school_phone_2), logo_path=VALUES(logo_path)');
        $save->execute([
            'name'=>$name, 'address'=>$address !== '' ? $address : null,
            'email'=>$email !== '' ? $email : null, 'phone1'=>$phone1 !== '' ? $phone1 : null,
            'phone2'=>$phone2 !== '' ? $phone2 : null, 'logo'=>$logoPath,
        ]);
        if ($oldLogo !== '' && $logoPath !== $oldLogo && str_starts_with($oldLogo, 'uploads/school-logo/')) {
            $oldPath = __DIR__ . '/' . $oldLogo;
            if (is_file($oldPath)) @unlink($oldPath);
        }
        $success = 'مشخصات مدرسه با موفقیت ذخیره شد.';
        $settings = $pdo->query('SELECT * FROM school_configuration WHERE id = 1 LIMIT 1')->fetch() ?: $defaults;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('School configuration save failed: ' . $e->getMessage());
        $error = 'ذخیره تنظیمات انجام نشد. اجرای به‌روزرسانی پایگاه داده را بررسی کنید.';
    }
}
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>مشخصات مدرسه | سامانه مدیریت دانش‌آموزان</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
.school-config-wrap{max-width:1000px;margin:28px auto;padding:0 18px}
.school-config-panel{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;margin:18px 0;box-shadow:0 6px 24px rgba(15,23,42,.04)}
.school-config-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.school-config-field{min-width:0}
.school-config-field label{display:block;margin-bottom:7px;font-weight:700;font-size:14px}
.school-config-field input,.school-config-field textarea{box-sizing:border-box;width:100%;min-height:44px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font:inherit;color:#1e293b;background:#fff}
.school-config-field textarea{min-height:100px;resize:vertical}
.school-config-wide{grid-column:1/-1}
.school-config-logo{display:flex;align-items:center;gap:20px;flex-wrap:wrap}
.school-config-logo img{width:132px;height:132px;object-fit:contain;border:1px dashed #cbd5e1;border-radius:12px;padding:8px;background:#f8fafc}
.school-config-help{display:block;color:#64748b;font-size:12px;margin-top:6px;line-height:1.7}
.school-config-alert{padding:13px 16px;border-radius:10px;margin:14px 0}
.school-config-success{background:#dcfce7;color:#166534}
.school-config-error{background:#fee2e2;color:#991b1b}
.school-config-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px}
@media(max-width:650px){.school-config-grid{grid-template-columns:1fr}.school-config-wide{grid-column:auto}.school-config-panel{padding:17px}}
</style>
</head>
<body class="profile-page">
<div class="admin-layout">

<?php require __DIR__ . '/includes/sidebar.php'; ?>
        <main class="admin-main">
            <?php require __DIR__ . '/includes/topbar.php'; ?>
<div class="school-config-wrap">
<a href="dashboard.php" class="button button-secondary">بازگشت به داشبورد</a>
<section class="school-config-panel">
<p class="eyebrow">تنظیمات مدرسه</p><h1>مشخصات مدرسه</h1>
<p>نام، لوگو و اطلاعات تماس مدرسه را ثبت کنید. هر نصب جداگانه از سامانه، مشخصات مدرسه خودش را نگه می‌دارد.</p>
<?php if ($success !== ''): ?><div class="school-config-alert school-config-success" role="status"><?= school_config_h($success) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="school-config-alert school-config-error" role="alert"><?= school_config_h($error) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= school_config_h(csrf_token()) ?>">
<div class="school-config-grid">
<div class="school-config-field school-config-wide"><label for="school_name">نام مدرسه *</label><input id="school_name" name="school_name" maxlength="180" required value="<?= school_config_h($settings['school_name'] ?? '') ?>" placeholder="مثلاً لیسه معرفت"></div>
<div class="school-config-field school-config-wide"><label for="school_address">آدرس مدرسه</label><textarea id="school_address" name="school_address" maxlength="500" placeholder="ولایت، شهر، ناحیه و آدرس دقیق"><?= school_config_h($settings['school_address'] ?? '') ?></textarea></div>
<div class="school-config-field"><label for="school_email">ایمیل مدرسه</label><input type="email" id="school_email" name="school_email" maxlength="190" value="<?= school_config_h($settings['school_email'] ?? '') ?>" placeholder="info@example.com"></div>
<div class="school-config-field"><label for="school_phone_1">شماره تلفن اول</label><input type="tel" id="school_phone_1" name="school_phone_1" maxlength="50" value="<?= school_config_h($settings['school_phone_1'] ?? '') ?>" placeholder="+93 ..."></div>
<div class="school-config-field"><label for="school_phone_2">شماره تلفن دوم (اختیاری)</label><input type="tel" id="school_phone_2" name="school_phone_2" maxlength="50" value="<?= school_config_h($settings['school_phone_2'] ?? '') ?>" placeholder="+93 ..."></div>
<div class="school-config-field school-config-wide"><label for="school_logo">لوگوی مدرسه</label><div class="school-config-logo">
<?php if (!empty($settings['logo_path'])): ?><img src="<?= school_config_h($settings['logo_path']) ?>" alt="لوگوی فعلی مدرسه"><?php else: ?><img src="assets/images/school-logo-placeholder.svg" alt="جایگاه لوگوی مدرسه"><?php endif; ?>
<div><input type="file" id="school_logo" name="school_logo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"><small class="school-config-help">فرمت‌های مجاز: JPG، PNG و WEBP. حداکثر حجم: ۳ مگابایت. برای نگه‌داشتن لوگوی فعلی، فایل جدید انتخاب نکنید.</small></div>
</div></div>
</div>
<div class="school-config-actions"><button class="button button-primary" type="submit">ذخیره مشخصات مدرسه</button><a href="school.php" class="button button-secondary">تنظیم سال تحصیلی</a></div>
</form>
</section>
</div>
</main>
</div>
</body>
</html>
