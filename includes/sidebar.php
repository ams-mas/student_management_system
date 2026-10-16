<?php
declare(strict_types=1);

$sidebarUsername = (string) ($_SESSION['username'] ?? '');
$sidebarFullName = (string) ($_SESSION['full_name'] ?? $sidebarUsername);
$sidebarRole = current_role();
$sidebarRoleLabels = ['super_admin' => 'مدیر کل', 'head_teacher' => 'سرمعلم', 'finance_manager' => 'مدیر مالی', 'administrative_staff' => 'کارمند اداری', 'teacher' => 'معلم'];
$sidebarCurrentPage = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
$sidebarSchoolLogo = '';
$sidebarSchoolName = '';
try {
    require_once __DIR__ . '/../config/database.php';
    $sidebarSettingsStmt = database_connection()->query('SELECT school_name, logo_path FROM school_configuration WHERE id = 1 LIMIT 1');
    $sidebarSchoolSettings = $sidebarSettingsStmt->fetch() ?: [];
    $sidebarSchoolLogo = (string) ($sidebarSchoolSettings['logo_path'] ?? '');
    $sidebarSchoolName = trim((string) ($sidebarSchoolSettings['school_name'] ?? ''));
} catch (Throwable $sidebarSettingsError) {
    $sidebarSchoolLogo = '';
    $sidebarSchoolName = '';
}
$sidebarEsc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<aside class="admin-sidebar" id="admin-sidebar" aria-label="منوی اصلی">
    <a class="sidebar-brand" href="dashboard.php">
        <?php if ($sidebarSchoolLogo !== ''): ?>
            <img class="sidebar-school-logo" src="<?= $sidebarEsc($sidebarSchoolLogo) ?>" alt="لوگوی مدرسه">
        <?php else: ?>
            <span class="sidebar-brand-mark" aria-hidden="true">م‌د</span>
        <?php endif; ?>
        <span><strong><?= $sidebarEsc($sidebarSchoolName !== '' ? $sidebarSchoolName : 'پنل مدیریت') ?></strong><small>سامانه مدیریت دانش‌آموزان</small></span>
    </a>
    <div class="sidebar-system-clock" aria-label="تاریخ و ساعت فعلی سیستم"><span class="sidebar-clock-date" data-sidebar-date></span><span class="sidebar-clock-time" data-sidebar-time></span></div>
    <p class="sidebar-section-label">منوی اصلی</p>
    <nav class="sidebar-nav">
        <a class="sidebar-link <?= $sidebarCurrentPage === 'dashboard.php' ? 'is-current' : '' ?>" href="dashboard.php"><span class="sidebar-icon" aria-hidden="true">⌂</span><span>داشبورد</span></a>
        <?php if (in_array($sidebarRole, ['super_admin', 'head_teacher', 'administrative_staff', 'teacher'], true)): ?>
        <div class="sidebar-group">
            <button type="button" class="sidebar-group-title <?= in_array($sidebarCurrentPage, ['students.php', 'edit_student.php', 'generate.php', 'attendance.php', 'marks.php'], true) ? 'is-current' : '' ?>" data-submenu-toggle aria-expanded="false"><span class="sidebar-icon" aria-hidden="true">♙</span><span>مدیریت دانش‌آموزان</span><span class="submenu-chevron" aria-hidden="true">⌄</span></button>
            <div class="sidebar-submenu">
                <?php if (in_array($sidebarRole, ['super_admin', 'head_teacher', 'administrative_staff'], true)): ?><a class="sidebar-link" href="students.php?new=1">۱. افزودن دانش‌آموز جدید</a><?php endif; ?>
                <a class="sidebar-link" href="students.php">۲. مشاهده همه دانش‌آموزان</a>
                <?php if (in_array($sidebarRole, ['super_admin', 'head_teacher', 'administrative_staff'], true)): ?><a class="sidebar-link <?= $sidebarCurrentPage === 'edit_student.php' ? 'is-current' : '' ?>" href="edit_student.php">۳. ویرایش اطلاعات دانش‌آموز</a><?php endif; ?>
                <span class="sidebar-link sidebar-link-disabled" aria-disabled="true">۴. پروفایل دانش‌آموز <small>به‌زودی</small></span>
                <a class="sidebar-link" href="students.php">۵. جست‌وجوی دانش‌آموزان</a>
                <a class="sidebar-link <?= $sidebarCurrentPage === 'attendance.php' ? 'is-current' : '' ?>" href="attendance.php">مدیریت حاضری شاگردان</a>
                <?php if (in_array($sidebarRole, ['super_admin', 'head_teacher', 'teacher'], true)): ?><a class="sidebar-link <?= $sidebarCurrentPage === 'marks.php' ? 'is-current' : '' ?>" href="marks.php">ثبت نمرات و امتحانات</a><?php endif; ?>
                <span class="sidebar-link sidebar-link-disabled" aria-disabled="true">۶. ارتقای دانش‌آموزان به صنف بالاتر <small>به‌زودی</small></span>
                <span class="sidebar-link sidebar-link-disabled" aria-disabled="true">۷. انتقال دانش‌آموزان <small>به‌زودی</small></span>
                <span class="sidebar-link sidebar-link-disabled" aria-disabled="true">۸. مدیریت ثبت‌نام دانش‌آموزان <small>به‌زودی</small></span>
                <span class="sidebar-link sidebar-link-disabled" aria-disabled="true">۹. مدیریت حاضری دانش‌آموزان <small>به‌زودی</small></span>
                <span class="sidebar-link sidebar-link-disabled" aria-disabled="true">۱۰. مدیریت اسناد دانش‌آموزان <small>به‌زودی</small></span>
                <?php if (in_array($sidebarRole, ['super_admin', 'head_teacher', 'administrative_staff'], true)): ?><a class="sidebar-link <?= $sidebarCurrentPage === 'generate.php' ? 'is-current' : '' ?>" href="generate.php">۱۱. کارت هویت دانش‌آموز</a><?php endif; ?>
                <span class="sidebar-link sidebar-link-disabled" aria-disabled="true">۱۲. سوابق تحصیلی دانش‌آموز <small>به‌زودی</small></span>
                <span class="sidebar-link sidebar-link-disabled" aria-disabled="true">۱۳. مدیریت اولیا و سرپرستان <small>به‌زودی</small></span>
                <span class="sidebar-link sidebar-link-disabled" aria-disabled="true">۱۴. تعلیق یا انصراف دانش‌آموز <small>به‌زودی</small></span>
                <span class="sidebar-link sidebar-link-disabled" aria-disabled="true">۱۵. فارغ‌التحصیلان <small>به‌زودی</small></span>
                <span class="sidebar-link sidebar-link-disabled" aria-disabled="true">۱۶. واردکردن دانش‌آموزان از Excel/CSV <small>به‌زودی</small></span>
                <span class="sidebar-link sidebar-link-disabled" aria-disabled="true">۱۷. صادرکردن پرونده‌های دانش‌آموزان <small>به‌زودی</small></span>
                <span class="sidebar-link sidebar-link-disabled" aria-disabled="true">۱۸. دانش‌آموزان حذف‌شده / بایگانی‌شده <small>به‌زودی</small></span>
            </div>
        </div>
        <?php endif; ?>
        <?php if (in_array($sidebarRole, ['super_admin', 'head_teacher'], true)): ?>
        <div class="sidebar-group">
            <button type="button" class="sidebar-group-title <?= $sidebarCurrentPage === 'teachers.php' ? 'is-current' : '' ?>" data-submenu-toggle aria-expanded="false"><span class="sidebar-icon" aria-hidden="true">♟</span><span>مدیریت آموزگاران</span><span class="submenu-chevron" aria-hidden="true">⌄</span></button>
            <div class="sidebar-submenu">
                <a class="sidebar-link <?= $sidebarCurrentPage === 'teachers.php' && (!isset($_GET['mode']) || $_GET['mode'] === 'add') ? 'is-current' : '' ?>" href="teachers.php?mode=add">ثبت آموزگار</a>
                <a class="sidebar-link <?= $sidebarCurrentPage === 'teachers.php' && ($_GET['mode'] ?? '') === 'edit' ? 'is-current' : '' ?>" href="teachers.php?mode=edit">ویرایش آموزگار</a>
                <a class="sidebar-link <?= $sidebarCurrentPage === 'teachers.php' && ($_GET['mode'] ?? '') === 'delete' ? 'is-current' : '' ?>" href="teachers.php?mode=delete">حذف آموزگار</a>
            </div>
        </div>
        <div class="sidebar-group">
            <button type="button" class="sidebar-group-title <?= $sidebarCurrentPage === 'classes.php' ? 'is-current' : '' ?>" data-submenu-toggle aria-expanded="false"><span class="sidebar-icon" aria-hidden="true">▦</span><span>مدیریت صنف‌ها</span><span class="submenu-chevron" aria-hidden="true">⌄</span></button>
            <div class="sidebar-submenu">
                <a class="sidebar-link <?= $sidebarCurrentPage === 'classes.php' && (!isset($_GET['mode']) || $_GET['mode'] === 'add') ? 'is-current' : '' ?>" href="classes.php?mode=add">اضافه کردن صنف</a>
                <a class="sidebar-link <?= $sidebarCurrentPage === 'classes.php' && ($_GET['mode'] ?? '') === 'edit' ? 'is-current' : '' ?>" href="classes.php?mode=edit">ویرایش صنف</a>
                <a class="sidebar-link <?= $sidebarCurrentPage === 'classes.php' && ($_GET['mode'] ?? '') === 'delete' ? 'is-current' : '' ?>" href="classes.php?mode=delete">حذف صنف</a>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($sidebarRole === 'super_admin'): ?>
        <div class="sidebar-group">
            <button type="button" class="sidebar-group-title <?= in_array($sidebarCurrentPage, ['school.php', 'school_configuration.php'], true) ? 'is-current' : '' ?>" data-submenu-toggle aria-expanded="false"><span class="sidebar-icon" aria-hidden="true">🏫</span><span>تنظیمات مدرسه</span><span class="submenu-chevron" aria-hidden="true">⌄</span></button>
            <div class="sidebar-submenu"><a class="sidebar-link <?= $sidebarCurrentPage === 'school_configuration.php' ? 'is-current' : '' ?>" href="school_configuration.php">مشخصات مدرسه</a><a class="sidebar-link <?= $sidebarCurrentPage === 'school.php' ? 'is-current' : '' ?>" href="school.php">سال تحصیلی</a></div>
        </div>
        <?php endif; ?>
        <?php if ($sidebarRole === 'super_admin'): ?>
        <div class="sidebar-group">
            <button type="button" class="sidebar-group-title <?= in_array($sidebarCurrentPage, ['users.php', 'add_user.php'], true) ? 'is-current' : '' ?>" data-submenu-toggle aria-expanded="false"><span class="sidebar-icon" aria-hidden="true">⚙</span><span>مدیریت کاربران</span><span class="submenu-chevron" aria-hidden="true">⌄</span></button>
            <div class="sidebar-submenu"><a class="sidebar-link <?= $sidebarCurrentPage === 'users.php' ? 'is-current' : '' ?>" href="users.php">لیست کاربران</a><a class="sidebar-link <?= $sidebarCurrentPage === 'add_user.php' ? 'is-current' : '' ?>" href="add_user.php">افزودن کاربر</a></div>
        </div>
        <?php endif; ?>
        <?php if (in_array($sidebarRole, ['super_admin', 'finance_manager'], true)): ?>
        <a class="sidebar-link <?= $sidebarCurrentPage === 'financial_reports.php' ? 'is-current' : '' ?>" href="financial_reports.php"><span class="sidebar-icon" aria-hidden="true">▤</span><span>گزارش مالی شاگردان</span></a>
        <div class="sidebar-group">
            <button type="button" class="sidebar-group-title <?= in_array($sidebarCurrentPage, ['fees.php', 'fee_payments.php', 'payment_history.php'], true) ? 'is-current' : '' ?>" data-submenu-toggle aria-expanded="false"><span class="sidebar-icon" aria-hidden="true">◈</span><span>امور مالی</span><span class="submenu-chevron" aria-hidden="true">⌄</span></button>
            <div class="sidebar-submenu">
                <a class="sidebar-link <?= $sidebarCurrentPage === 'fees.php' ? 'is-current' : '' ?>" href="fees.php">تعیین فیس دوره‌ها</a>
                <a class="sidebar-link <?= $sidebarCurrentPage === 'fee_payments.php' ? 'is-current' : '' ?>" href="fee_payments.php">دریافت فیس شاگردان</a>
                <a class="sidebar-link <?= $sidebarCurrentPage === 'payment_history.php' ? 'is-current' : '' ?>" href="payment_history.php">تاریخچه پرداخت‌ها و رسیدها</a>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($sidebarRole === 'super_admin'): ?>
        <a class="sidebar-link <?= $sidebarCurrentPage === 'dashboard_configuration.php' ? 'is-current' : '' ?>" href="dashboard_configuration.php"><span class="sidebar-icon" aria-hidden="true">▦</span><span>تنظیمات داشبورد</span></a>
        <a class="sidebar-link <?= $sidebarCurrentPage === 'database_updates.php' ? 'is-current' : '' ?>" href="database_updates.php"><span class="sidebar-icon" aria-hidden="true">↻</span><span>به‌روزرسانی پایگاه داده</span></a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-bottom">
        <span class="sidebar-user-avatar" aria-hidden="true"><?= $sidebarEsc(mb_substr($sidebarFullName, 0, 1)) ?></span>
        <div class="sidebar-user-copy"><strong><?= $sidebarEsc($sidebarFullName) ?></strong><small><?= $sidebarEsc($sidebarRoleLabels[$sidebarRole] ?? $sidebarRole) ?></small></div>
        <form action="logout.php" method="post"><input type="hidden" name="csrf_token" value="<?= $sidebarEsc(csrf_token()) ?>"><button class="sidebar-logout" type="submit" aria-label="خروج از حساب" title="خروج از حساب">↪</button></form>
    </div>
</aside>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('admin-sidebar');
    if (!sidebar) return;
    sidebar.querySelectorAll('[data-submenu-toggle]').forEach(function (button) {
        const group = button.closest('.sidebar-group');
        if (!group) return;
        if (button.classList.contains('is-current') || group.querySelector('.sidebar-submenu .is-current')) {
            group.classList.add('is-open');
            button.setAttribute('aria-expanded', 'true');
        }
        button.addEventListener('click', function () {
            const open = group.classList.toggle('is-open');
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });
});
</script>
<style>
/* Keep the shared school logo and sidebar layout consistent on every page. */
.admin-sidebar .sidebar-brand{display:flex;align-items:center;min-width:0;gap:12px}
.admin-sidebar .sidebar-school-logo{display:block;flex:0 0 43px;width:43px!important;height:43px!important;min-width:43px;max-width:43px!important;min-height:43px;max-height:43px!important;object-fit:contain;box-sizing:border-box}
.admin-sidebar .sidebar-brand>span:last-child{min-width:0}
.admin-sidebar .sidebar-brand strong,.admin-sidebar .sidebar-brand small{overflow-wrap:anywhere}
</style>

