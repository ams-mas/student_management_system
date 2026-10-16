<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="ورود به سامانه مدیریت دانش‌آموزان">
    <title>ورود | سامانه مدیریت دانش‌آموزان</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-brand" aria-label="سامانه مدیریت دانش‌آموزان">
            <div class="brand-mark" aria-hidden="true">م‌د</div>
            <p class="login-eyebrow">سامانه خدمات دانش‌آموزی</p>
            <h1>سامانه مدیریت دانش‌آموزان</h1>
            <p class="login-intro">سامانه‌ای برای ثبت، جست‌وجو و مدیریت اطلاعات دانش‌آموزان از یک پنل امن.</p>
            <div class="login-feature-list">
                <p><span aria-hidden="true">✓</span> ثبت و به‌روزرسانی پرونده دانش‌آموزان</p>
                <p><span aria-hidden="true">✓</span> جست‌وجو و مشاهده اطلاعات دانش‌آموزان</p>
                <p><span aria-hidden="true">✓</span> ورود کاربران مجاز برای دسترسی به سامانه</p>
            </div>
        </section>

        <section class="login-panel" aria-labelledby="login-title">
            <div class="login-panel-heading">
                <p class="eyebrow">خوش آمدید</p>
                <h2 id="login-title">ورود به حساب کاربری</h2>
                <p class="muted">برای ادامه، نام کاربری و رمز عبور خود را وارد کنید.</p>
            </div>

            <?php if (isset($_GET['invalid'])): ?>
                <div class="login-alert" role="alert">نام کاربری یا رمز عبور نادرست است.</div>
            <?php endif; ?>
            <?php if (isset($_GET['inactive'])): ?>
                <div class="login-alert" role="alert">این حساب غیرفعال است. برای دسترسی با مدیر سامانه تماس بگیرید.</div>
            <?php endif; ?>
            <?php if (isset($_GET['throttled'])): ?>
                <div class="login-alert" role="alert">تلاش‌های ورود از این شبکه بیش از حد مجاز است. چند دقیقه صبر کنید و دوباره تلاش کنید.</div>
            <?php endif; ?>
            <?php if (isset($_GET['account_locked'])): ?>
                <div class="login-alert" role="alert">این حساب به دلیل تلاش‌های ناموفق ورود برای ۲۴ ساعت قفل شده است. برای بازگشایی با مدیر سامانه تماس بگیرید.</div>
            <?php endif; ?>
            <?php if (isset($_GET['database'])): ?>
                <div class="login-alert" role="alert">سرویس ورود در دسترس نیست. اجرای MySQL، وارد کردن فایل database/schema.sql و ایجاد حساب کاربری را بررسی کنید.</div>
            <?php endif; ?>

            <form action="login.php" method="post" class="login-form">
                <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">

                <label for="username">نام کاربری</label>
                <input id="username" name="username" type="text" autocomplete="username"
                       maxlength="80" required autofocus>

                <label for="password">رمز عبور</label>
                <input id="password" name="password" type="password" autocomplete="current-password"
                       maxlength="4096" required>

                <button class="button button-primary login-submit" type="submit">ورود به سامانه <span aria-hidden="true">←</span></button>
            </form>

            <p class="login-footnote">دسترسی فقط برای کاربران مجاز است. از رمز عبور قوی و اختصاصی استفاده کنید.</p>
        </section>
        <footer class="login-footer">سامانه مدیریت دانش‌آموزان · حفظ حریم خصوصی اطلاعات دانش‌آموزان</footer>
    </main>
</body>
</html>
