<?php
declare(strict_types=1);

$topbarUsername = (string) ($_SESSION['username'] ?? '');
$topbarFullName = (string) ($_SESSION['full_name'] ?? $topbarUsername);
$topbarRole = current_role();
$topbarRoleLabels = ['super_admin' => 'مدیر کل', 'head_teacher' => 'سرمعلم', 'finance_manager' => 'مدیر مالی', 'administrative_staff' => 'کارمند اداری', 'teacher' => 'معلم'];
$topbarEsc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<header class="admin-topbar">
    <div class="admin-topbar-brand">
        <span class="admin-topbar-mark" aria-hidden="true">م‌د</span>
        <div class="admin-topbar-title">
            <span class="admin-topbar-caption">پنل مدیریت مدرسه</span>
            <h1>سامانه مدیریت دانش‌آموزان</h1>
        </div>
    </div>
    <div class="admin-topbar-actions">
        <form class="admin-topbar-logout-form" action="logout.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $topbarEsc(csrf_token()) ?>">
            <button class="admin-topbar-logout" type="submit"><span aria-hidden="true">↪</span> خروج</button>
        </form>
        <div class="admin-topbar-account">
            <span class="admin-topbar-avatar" aria-hidden="true"><?= $topbarEsc(function_exists('mb_substr') ? mb_substr($topbarFullName, 0, 1) : substr($topbarFullName, 0, 1)) ?></span>
            <span class="admin-topbar-user"><strong><?= $topbarEsc($topbarFullName) ?></strong><small><?= $topbarEsc($topbarRoleLabels[$topbarRole] ?? $topbarRole) ?></small></span>
        </div>
    </div>
</header>

<script src="assets/js/table-sort.js?v=20261013" defer></script>
