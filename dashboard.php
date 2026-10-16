<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();

$username = (string) $_SESSION['username'];
$userId = (int) $_SESSION['user_id'];
$role = current_role();
$roleLabels = ['super_admin' => 'مدیر کل', 'head_teacher' => 'سرمعلم', 'finance_manager' => 'مدیر مالی', 'administrative_staff' => 'کارمند اداری', 'teacher' => 'معلم'];
$canManageUsers = $role === 'super_admin';

require_once __DIR__ . '/config/database.php';
$pdo = database_connection();

$dashboardWidgetDefaults = ['stat_students'=>1,'stat_teachers'=>1,'stat_classes'=>1,'stat_users'=>1,'chart_gender'=>1,'chart_class_counts'=>1,'recent_students'=>1,'academic_year'=>1,'financial_summary'=>0,'attendance_summary'=>0,'exam_summary'=>0,'notices'=>0,'upcoming_tasks'=>0];
$dashboardWidgets = $dashboardWidgetDefaults;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dashboard_widget_settings (widget_key VARCHAR(64) NOT NULL PRIMARY KEY, is_enabled TINYINT(1) NOT NULL DEFAULT 1, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach ($pdo->query('SELECT widget_key, is_enabled FROM dashboard_widget_settings')->fetchAll(PDO::FETCH_ASSOC) as $widgetRow) {
        if (array_key_exists((string)$widgetRow['widget_key'], $dashboardWidgets)) $dashboardWidgets[(string)$widgetRow['widget_key']] = (int)$widgetRow['is_enabled'];
    }
} catch (Throwable $widgetSettingsError) { error_log('Dashboard widget preferences could not be loaded: ' . $widgetSettingsError->getMessage()); }

$dashboardStats = ['students' => 0, 'teachers' => 0, 'classes' => 0, 'users' => 0, 'male' => 0, 'female' => 0];
$recentStudents = [];
$classSummary = [];
$activeYearName = 'سال تحصیلی تعیین نشده';
try {
    $dashboardStats['students'] = (int) $pdo->query('SELECT COUNT(*) FROM students WHERE is_active = 1')->fetchColumn();
    $dashboardStats['users'] = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
    $dashboardStats['male'] = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE is_active = 1 AND gender = 'male'")->fetchColumn();
    $dashboardStats['female'] = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE is_active = 1 AND gender = 'female'")->fetchColumn();
    $recentStudents = $pdo->query('SELECT student_number, first_name, last_name, class_name, gender, created_at FROM students ORDER BY id DESC LIMIT 6')->fetchAll(PDO::FETCH_ASSOC);
    try {
        $year = $pdo->query('SELECT id, name FROM school_years WHERE is_active = 1 AND is_closed = 0 ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($year) {
            $activeYearName = (string) $year['name'];
            $classStmt = $pdo->prepare('SELECT COUNT(*) FROM class_sections WHERE school_year_id = :year_id AND is_active = 1');
            $classStmt->execute(['year_id' => (int) $year['id']]);
            $dashboardStats['classes'] = (int) $classStmt->fetchColumn();
            $classStmt = $pdo->prepare('SELECT CONCAT(grade, " ", section_name) AS class_label, COUNT(se.id) AS student_count FROM class_sections cs LEFT JOIN student_enrollments se ON se.class_section_id = cs.id AND se.is_current = 1 AND se.status = "enrolled" WHERE cs.school_year_id = :year_id AND cs.is_active = 1 GROUP BY cs.id, cs.grade, cs.section_name ORDER BY cs.grade, cs.section_name LIMIT 6');
            $classStmt->execute(['year_id' => (int) $year['id']]);
            $classSummary = $classStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $yearError) {
        $dashboardStats['classes'] = (int) $pdo->query('SELECT COUNT(*) FROM class_sections WHERE is_active = 1')->fetchColumn();
    }
    try {
        $dashboardStats['teachers'] = (int) $pdo->query('SELECT COUNT(*) FROM teachers WHERE is_active = 1')->fetchColumn();
    } catch (Throwable $teacherError) {
        $dashboardStats['teachers'] = 0;
    }
} catch (Throwable $dashboardError) {
    error_log('Dashboard statistics could not be loaded: ' . $dashboardError->getMessage());
}
$genderTotal = $dashboardStats['male'] + $dashboardStats['female'];
$malePercent = $genderTotal > 0 ? (int) round($dashboardStats['male'] * 100 / $genderTotal) : 0;
$femalePercent = $genderTotal > 0 ? 100 - $malePercent : 0;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="داشبورد سامانه مدیریت دانش‌آموزان">
    <title>داشبورد | سامانه مدیریت دانش‌آموزان</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="profile-page">
    <div class="admin-layout">
        

        <?php require __DIR__ . '/includes/sidebar.php'; ?>
        <main class="admin-main dashboard-main">
            <?php require __DIR__ . '/includes/topbar.php'; ?>
            <header class="dash-heading">
                <div></div>
                <?php if ($dashboardWidgets['academic_year']): ?><div class="dash-year"><span>سال تحصیلی فعلی</span><strong><?= htmlspecialchars($activeYearName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div><?php endif; ?>
            </header>
            <section class="dash-stat-grid" aria-label="آمار کلی">
                <?php if ($dashboardWidgets['stat_students']): ?><article class="dash-stat dash-blue"><span class="dash-stat-icon">♙</span><div><span>دانش‌آموزان فعال</span><strong><?= number_format($dashboardStats['students']) ?></strong><small>مجموع پرونده‌های فعال</small></div></article><?php endif; ?>
                <?php if ($dashboardWidgets['stat_teachers']): ?><article class="dash-stat dash-green"><span class="dash-stat-icon">♟</span><div><span>آموزگاران فعال</span><strong><?= number_format($dashboardStats['teachers']) ?></strong><small>ثبت‌شده در سامانه</small></div></article><?php endif; ?>
                <?php if ($dashboardWidgets['stat_classes']): ?><article class="dash-stat dash-violet"><span class="dash-stat-icon">▦</span><div><span>صنف‌های فعال</span><strong><?= number_format($dashboardStats['classes']) ?></strong><small>در سال تحصیلی فعلی</small></div></article><?php endif; ?>
                <?php if ($dashboardWidgets['stat_users']): ?><article class="dash-stat dash-orange"><span class="dash-stat-icon">⚙</span><div><span>حساب‌های فعال</span><strong><?= number_format($dashboardStats['users']) ?></strong><small>کاربران سامانه</small></div></article><?php endif; ?>
            </section>
            <section class="dash-middle-grid">
                <?php if ($dashboardWidgets['chart_gender']): ?><article class="dash-panel dash-gender-panel"><div class="dash-panel-title"><h2>ترکیب جنسیتی دانش‌آموزان</h2><span>فعال</span></div>
                    <div class="dash-gender-content"><div class="dash-donut" style="--male-share:<?= $malePercent ?>%"><div><strong><?= number_format($genderTotal) ?></strong><small>دانش‌آموز</small></div></div>
                        <div class="dash-legend"><p><i class="dash-dot dash-dot-blue"></i><span>پسر</span><strong><?= number_format($dashboardStats['male']) ?></strong><small><?= $malePercent ?>٪</small></p><p><i class="dash-dot dash-dot-green"></i><span>دختر</span><strong><?= number_format($dashboardStats['female']) ?></strong><small><?= $femalePercent ?>٪</small></p></div></div>
                </article><?php endif; ?>
                <?php if ($dashboardWidgets['chart_class_counts']): ?><article class="dash-panel"><div class="dash-panel-title"><h2>تعداد دانش‌آموزان هر صنف</h2><span>تا ۶ صنف</span></div>
                    <?php if ($classSummary): ?><div class="dash-bars"><?php $maxClassCount = max(1, ...array_map(static fn(array $item): int => (int) $item['student_count'], $classSummary)); foreach ($classSummary as $item): ?><div class="dash-bar-row"><span><?= htmlspecialchars((string) $item['class_label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><div class="dash-bar-track"><i style="width:<?= (int) round((int) $item['student_count'] * 100 / $maxClassCount) ?>%"></i></div><strong><?= number_format((int) $item['student_count']) ?></strong></div><?php endforeach; ?></div><?php else: ?><div class="dash-empty-note">پس از ایجاد صنف و ثبت‌نام دانش‌آموزان، آمار این بخش نمایش داده می‌شود.</div><?php endif; ?>
                </article><?php endif; ?>
            </section>
            <?php if ($dashboardWidgets['recent_students']): ?><section class="dash-panel dash-recent-panel"><div class="dash-panel-title"><h2>آخرین دانش‌آموزان ثبت‌شده</h2><a href="students.php">مشاهده فهرست <span aria-hidden="true">←</span></a></div>
                <?php if ($recentStudents): ?><div class="dash-table-wrap"><table class="dash-table"><thead><tr><th>شماره مسلسل</th><th>نام دانش‌آموز</th><th>صنف</th><th>جنسیت</th><th>تاریخ ثبت</th></tr></thead><tbody><?php foreach ($recentStudents as $student): ?><tr><td dir="ltr"><?= htmlspecialchars((string) $student['student_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td><td><?= htmlspecialchars(trim((string) $student['first_name'] . ' ' . (string) $student['last_name']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td><td><?= htmlspecialchars((string) ($student['class_name'] ?: 'تعیین نشده'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td><td><?= $student['gender'] === 'male' ? 'پسر' : ($student['gender'] === 'female' ? 'دختر' : 'تعیین نشده') ?></td><td><?= !empty($student['created_at']) ? htmlspecialchars(date('Y-m-d', strtotime((string) $student['created_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '—' ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="dash-empty-note">هنوز دانش‌آموزی ثبت نشده است. پس از ثبت اولین دانش‌آموز، فهرست او در این بخش ظاهر می‌شود.</div><?php endif; ?>
            </section><?php endif; ?>
            <footer class="dash-footer">سامانه مدیریت دانش‌آموزان</footer>
        </main>
        <style>
        .dashboard-main{min-width:0;padding:28px clamp(16px,2.5vw,34px);background:#f3f6fb;color:#1c2940;direction:rtl}
        .dash-heading{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:22px}.dash-heading h1{font-size:clamp(1.7rem,3vw,2.35rem);margin:4px 0}.dash-eyebrow{margin:0;color:#2d59b4;font-size:.8rem;font-weight:800}.dash-muted{margin:0;color:#748096;font-size:.9rem}.dash-year{padding:12px 16px;border:1px solid #e0e7f1;border-radius:12px;background:#fff;display:flex;flex-direction:column;gap:5px;min-width:160px}.dash-year span{font-size:.75rem;color:#78859a}.dash-year strong{font-size:.92rem}
        .dash-stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.dash-stat{display:flex;align-items:center;gap:13px;min-width:0;padding:18px 15px;border-radius:14px;color:#fff;box-shadow:0 8px 22px #1e293b12}.dash-stat>div{display:flex;flex-direction:column;min-width:0;gap:4px}.dash-stat span:not(.dash-stat-icon){font-size:.82rem}.dash-stat strong{font-size:clamp(1.45rem,2.4vw,2rem);line-height:1.2}.dash-stat small{font-size:.7rem;opacity:.86}.dash-stat-icon{display:grid;place-items:center;flex:0 0 43px;width:43px;height:43px;border-radius:12px;background:#ffffff25;font-size:1.55rem}.dash-blue{background:linear-gradient(135deg,#2463c9,#1686df)}.dash-green{background:linear-gradient(135deg,#13866c,#27b58c)}.dash-violet{background:linear-gradient(135deg,#5355ad,#7779d7)}.dash-orange{background:linear-gradient(135deg,#e28b2c,#f5ad48)}
        .dash-middle-grid{display:grid;grid-template-columns:minmax(260px,.85fr) minmax(0,1.5fr);gap:16px;margin-bottom:18px}.dash-panel{min-width:0;padding:20px;border:1px solid #e5eaf2;border-radius:14px;background:#fff;box-shadow:0 5px 18px #17233b08}.dash-panel-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:17px}.dash-panel-title h2{margin:0;font-size:1rem}.dash-panel-title>span{color:#8994a7;font-size:.75rem}.dash-panel-title a{color:#2459b7;text-decoration:none;font-size:.82rem;font-weight:700}.dash-gender-content{display:flex;align-items:center;justify-content:space-around;gap:18px;min-height:190px}.dash-donut{width:145px;height:145px;flex:0 0 145px;display:grid;place-items:center;border-radius:50%;background:conic-gradient(#2876d2 0 var(--male-share),#22a784 var(--male-share) 100%);position:relative}.dash-donut:before{content:"";position:absolute;inset:19px;border-radius:50%;background:white}.dash-donut>div{position:relative;display:flex;flex-direction:column;align-items:center;gap:4px}.dash-donut strong{font-size:1.25rem}.dash-donut small{color:#8490a4;font-size:.72rem}.dash-legend{width:100%;max-width:170px}.dash-legend p{display:grid;grid-template-columns:10px 1fr auto;align-items:center;gap:7px;margin:14px 0;font-size:.82rem}.dash-legend p small{grid-column:2 / 4;color:#7c879a}.dash-legend p strong{font-variant-numeric:tabular-nums}.dash-dot{width:9px;height:9px;border-radius:50%}.dash-dot-blue{background:#2876d2}.dash-dot-green{background:#22a784}
        .dash-bars{display:grid;gap:18px;padding:4px 0}.dash-bar-row{display:grid;grid-template-columns:75px minmax(40px,1fr) 35px;align-items:center;gap:10px;font-size:.8rem}.dash-bar-track{height:12px;border-radius:99px;background:#edf1f7;overflow:hidden}.dash-bar-track i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#2385d8,#2e62c2)}.dash-bar-row strong{text-align:left;font-variant-numeric:tabular-nums}.dash-empty-note{padding:25px 12px;text-align:center;color:#7a879a;line-height:1.9;font-size:.9rem;background:#f8fafc;border-radius:10px}
        .dash-recent-panel{margin-bottom:12px}.dash-table-wrap{overflow-x:auto}.dash-table{width:100%;border-collapse:collapse;white-space:nowrap;font-size:.82rem}.dash-table th{padding:12px 11px;background:#1d365e;color:#fff;text-align:right;font-weight:700}.dash-table th:first-child{border-radius:0 8px 0 0}.dash-table th:last-child{border-radius:8px 0 0 0}.dash-table td{padding:12px 11px;border-bottom:1px solid #edf0f5;color:#47546a}.dash-table tbody tr:nth-child(even){background:#f8fafd}.dash-table tbody tr:hover{background:#eef5ff}.dash-footer{text-align:center;color:#8a95a7;font-size:.75rem;padding:10px}
        @media(max-width:1100px){.dash-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.dash-middle-grid{grid-template-columns:1fr}}@media(max-width:620px){.dashboard-main{padding:18px 12px}.dash-heading{align-items:flex-start;flex-direction:column}.dash-year{width:100%}.dash-stat-grid{gap:9px}.dash-stat{padding:13px 10px;gap:9px}.dash-stat-icon{width:35px;height:35px;flex-basis:35px;font-size:1.2rem}.dash-stat small{display:none}.dash-gender-content{flex-wrap:wrap}.dash-panel{padding:15px}.dash-bar-row{grid-template-columns:60px minmax(35px,1fr) 25px;gap:7px}}
        </style>
    </div>
    
    </body>
</html>
