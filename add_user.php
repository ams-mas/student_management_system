<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_roles(['super_admin']);
require_once __DIR__ . '/config/database.php';

$pdo = database_connection();
$actorId = (int) $_SESSION['user_id'];
$actorRole = current_role();
$isSuperAdmin = $actorRole === 'super_admin';

$roleLabels = [
    'super_admin' => 'مدیر کل',
    'head_teacher' => 'سرمعلم',
    'finance_manager' => 'مدیر مالی',
    'administrative_staff' => 'کارمند اداری',
    'teacher' => 'معلم',
];
$error = '';
$success = '';

if (isset($_SESSION['users_flash']) && is_array($_SESSION['users_flash'])) {
    $success = (string) ($_SESSION['users_flash']['success'] ?? '');
    $error = (string) ($_SESSION['users_flash']['error'] ?? '');
    unset($_SESSION['users_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid form token. Refresh the page and try again.');
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $username = strtolower(trim((string) ($_POST['username'] ?? '')));
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $confirmation = (string) ($_POST['password_confirm'] ?? '');
            $requestedRole = (string) ($_POST['role'] ?? 'administrative_staff');
            $allowedRoles = array_keys($roleLabels);

            if (!preg_match('/^[a-z0-9_.-]{3,80}$/', $username)) {
                throw new RuntimeException('نام کاربری باید ۳ تا ۸۰ حرف انگلیسی کوچک، عدد، نقطه، خط تیره یا زیرخط باشد.');
            }
            if ($fullName === '' || mb_strlen($fullName) > 160) throw new RuntimeException('نام کامل الزامی است و نباید بیشتر از ۱۶۰ نویسه باشد.');
            if (!in_array($requestedRole, $allowedRoles, true)) {
                throw new RuntimeException('شما اجازه ساخت این نوع حساب را ندارید.');
            }
            if (strlen($password) < 8 || strlen($password) > 4096) {
                throw new RuntimeException('رمز عبور باید حداقل ۸ کاراکتر داشته باشد.');
            }
            if (!hash_equals($password, $confirmation)) {
                throw new RuntimeException('رمز عبور و تکرار آن یکسان نیستند.');
            }

            $statement = $pdo->prepare(
                'INSERT INTO users (username, full_name, password_hash, role, is_active) VALUES (:username, :full_name, :password_hash, :role, 1)'
            );
            $statement->execute([
                'username' => $username,
                'full_name' => $fullName,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $requestedRole,
            ]);
            $_SESSION['users_flash'] = ['success' => 'حساب کاربری با موفقیت ساخته شد.'];
        } elseif ($action === 'toggle') {
            $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$targetId || $targetId === $actorId) {
                throw new RuntimeException('نمی‌توانید حساب خودتان را از این بخش غیرفعال کنید.');
            }
            $statement = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $targetId]);
            $target = $statement->fetch();
            if (!$target) {
                throw new RuntimeException('حساب کاربری پیدا نشد.');
            }
            if (!$isSuperAdmin && $target['role'] === 'super_admin') {
                throw new RuntimeException('فقط سوپر ادمین می‌تواند این حساب را مدیریت کند.');
            }
            $newStatus = (int) $target['is_active'] === 1 ? 0 : 1;
            if ($newStatus === 0 && $target['role'] === 'super_admin') {
                $count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND is_active = 1")->fetchColumn();
                if ($count <= 1) {
                    throw new RuntimeException('نمی‌توان آخرین سوپر ادمین فعال را غیرفعال کرد.');
                }
            }
            $statement = $pdo->prepare('UPDATE users SET is_active = :active WHERE id = :id');
            $statement->execute(['active' => $newStatus, 'id' => $targetId]);
            $_SESSION['users_flash'] = ['success' => $newStatus ? 'حساب فعال شد.' : 'حساب غیرفعال شد.'];
        } elseif ($action === 'delete') {
            $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$targetId || $targetId === $actorId) {
                throw new RuntimeException('نمی‌توانید حساب خودتان را حذف کنید.');
            }
            $statement = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $targetId]);
            $target = $statement->fetch();
            if (!$target) {
                throw new RuntimeException('حساب کاربری پیدا نشد.');
            }
            if (!$isSuperAdmin && $target['role'] === 'super_admin') {
                throw new RuntimeException('فقط سوپر ادمین می‌تواند این حساب را مدیریت کند.');
            }
            if ($target['role'] === 'super_admin') {
                $count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn();
                if ($count <= 1) {
                    throw new RuntimeException('نمی‌توان آخرین سوپر ادمین را حذف کرد.');
                }
            }
            $statement = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $statement->execute(['id' => $targetId]);
            $_SESSION['users_flash'] = ['success' => 'حساب کاربری حذف شد.'];
        } else {
            throw new RuntimeException('درخواست نامعتبر است.');
        }
    } catch (RuntimeException $exception) {
        $_SESSION['users_flash'] = ['error' => $exception->getMessage()];
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            $_SESSION['users_flash'] = ['error' => 'این نام کاربری قبلاً استفاده شده است.'];
        } else {
            error_log('Student ID Generator user management error: ' . $exception->getMessage());
            $_SESSION['users_flash'] = ['error' => 'ذخیره تغییرات انجام نشد. تنظیمات پایگاه داده را بررسی کنید.'];
        }
    }

    header('Location: users.php');
    exit;
}

$usersQuery = $pdo->query('SELECT id, username, role, is_active, created_at FROM users ORDER BY id ASC');
$allUsers = $usersQuery->fetchAll();

$username = (string) $_SESSION['username'];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>افزودن کاربر | سامانه مدیریت دانش‌آموزان</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="profile-page">
<div class="admin-layout">
    

    <?php require __DIR__ . '/includes/sidebar.php'; ?>
        <main class="admin-main">
            <?php require __DIR__ . '/includes/topbar.php'; ?>
        <header class="page-header dashboard-header">
            <div><p class="eyebrow">پنل مدیریت</p><h1>افزودن کاربر</h1><p class="muted">ساخت حساب جدید و تعیین سطح دسترسی.</p></div>
            <div class="account-actions"><span class="signed-in-as">نقش شما: <strong><?= escape($roleLabels[$actorRole] ?? $actorRole) ?></strong></span><a class="button button-secondary" href="dashboard.php">بازگشت به پروفایل</a></div>
        </header>

        <?php if ($success !== ''): ?><div class="user-alert user-alert-success" role="status"><?= escape($success) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="user-alert user-alert-error" role="alert"><?= escape($error) ?></div><?php endif; ?>

        <section class="panel user-create-panel" aria-labelledby="create-user-title">
            <h2 id="create-user-title">افزودن حساب جدید</h2>
            <p class="muted">نام کامل برای نمایش در سیستم، نام کاربری برای ورود و رمز عبور را تعیین کنید.</p>
            <form method="post" action="users.php" class="user-create-form">
                <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                <input type="hidden" name="action" value="add">
                <label for="new-full-name">نام کامل</label>
                <input id="new-full-name" name="full_name" required maxlength="160" autocomplete="name" placeholder="مثال: محمد عاصف بیات">
                <label for="new-username">نام کاربری (برای ورود)</label>
                <input id="new-username" name="username" required minlength="3" maxlength="80" pattern="[a-zA-Z0-9_.-]+" autocomplete="off" placeholder="مثال: school.user">
                <label for="new-role">نوع حساب</label>
                <select id="new-role" name="role" required>
                    <?php foreach ($roleLabels as $roleValue => $roleLabel): ?>
                    <option value="<?= escape($roleValue) ?>" <?= $roleValue === 'administrative_staff' ? 'selected' : '' ?>><?= escape($roleLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="help-text"><?= $isSuperAdmin ? 'شما می‌توانید هر پنج نوع حساب را ایجاد کنید.' : 'تنها مدیر کل می‌تواند حساب کاربری ایجاد کند.' ?></p>
                <label for="new-password">رمز عبور (حداقل ۸ کاراکتر)</label>
                <input id="new-password" name="password" type="password" required minlength="8" maxlength="4096" autocomplete="new-password">
                <label for="new-password-confirm">تکرار رمز عبور</label>
                <input id="new-password-confirm" name="password_confirm" type="password" required minlength="8" maxlength="4096" autocomplete="new-password">
                <button class="button button-primary" type="submit">ساخت حساب</button>
            </form>
        </section>

        <footer class="page-footer">سامانه مدیریت دانش‌آموزان · مدیریت کاربران</footer>
    </main>
</div>

    </body>
</html>
