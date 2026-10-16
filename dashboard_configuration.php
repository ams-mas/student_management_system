<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_roles(['super_admin']);
require_once __DIR__ . '/config/database.php';
$pdo = database_connection();
$widgets = [
 'stat_students'=>['کارت شاگردان فعال','تعداد شاگردان فعال در مکتب','کارت‌های آماری',1,true],
 'stat_teachers'=>['کارت آموزگاران فعال','تعداد آموزگاران فعال','کارت‌های آماری',1,true],
 'stat_classes'=>['کارت صنف‌های فعال','صنف‌های فعال در سال تحصیلی فعلی','کارت‌های آماری',1,true],
 'stat_users'=>['کارت حساب‌های فعال','تعداد حساب‌های کاربری فعال','کارت‌های آماری',1,true],
 'chart_gender'=>['نمودار جنسیت شاگردان','تعداد و درصد شاگردان پسر و دختر','نمودارها و جدول‌ها',1,true],
 'chart_class_counts'=>['نمودار شاگردان هر صنف','مقایسه تعداد شاگردان صنف‌های فعال','نمودارها و جدول‌ها',1,true],
 'recent_students'=>['آخرین شاگردان ثبت‌شده','جدول آخرین پرونده‌های ثبت‌شده','نمودارها و جدول‌ها',1,true],
 'academic_year'=>['سال تحصیلی فعلی','نمایش نام سال تحصیلی در بالای داشبورد','اطلاعات عمومی',1,true],
 'financial_summary'=>['خلاصه امور مالی','پس از ساخته‌شدن ماژول مالی قابل نمایش می‌شود.','بخش‌های آینده',0,false],
 'attendance_summary'=>['خلاصه حاضری','پس از ساخته‌شدن ماژول حاضری قابل نمایش می‌شود.','بخش‌های آینده',0,false],
 'exam_summary'=>['خلاصه امتحانات','پس از ساخته‌شدن ماژول امتحانات قابل نمایش می‌شود.','بخش‌های آینده',0,false],
 'notices'=>['اطلاعیه‌های جدید','پس از ساخته‌شدن ماژول اطلاعیه‌ها قابل نمایش می‌شود.','بخش‌های آینده',0,false],
 'upcoming_tasks'=>['کارها و رویدادهای آینده','پس از ساخته‌شدن ماژول برنامه‌ریزی قابل نمایش می‌شود.','بخش‌های آینده',0,false]
];
$pdo->exec("CREATE TABLE IF NOT EXISTS dashboard_widget_settings (widget_key VARCHAR(64) NOT NULL PRIMARY KEY, is_enabled TINYINT(1) NOT NULL DEFAULT 1, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$seed=$pdo->prepare('INSERT IGNORE INTO dashboard_widget_settings (widget_key,is_enabled) VALUES (:k,:v)');
foreach($widgets as $key=>$item) $seed->execute(['k'=>$key,'v'=>$item[3]]);
$success=''; $error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!csrf_token_is_valid($_POST['csrf_token']??null)){http_response_code(400);exit('درخواست معتبر نیست. صفحه را تازه‌سازی کنید.');}
 try{
  $save=$pdo->prepare('UPDATE dashboard_widget_settings SET is_enabled=:enabled WHERE widget_key=:k');
  foreach($widgets as $key=>$item) $save->execute(['enabled'=>($item[4] && isset($_POST['widgets'][$key]))?1:0,'k'=>$key]);
  $success='تنظیمات داشبورد ذخیره شد.';
 }catch(Throwable $e){error_log('Dashboard widget settings save failed: '.$e->getMessage());$error='ذخیره تنظیمات انجام نشد. دوباره تلاش کنید.';}
}
$stored=$pdo->query('SELECT widget_key,is_enabled FROM dashboard_widget_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$groups=[]; foreach($widgets as $key=>$item){$groups[$item[2]][$key]=['label'=>$item[0],'description'=>$item[1],'enabled'=>(int)($stored[$key]??$item[3])===1,'ready'=>$item[4]];}
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>تنظیمات داشبورد</title><link rel="stylesheet" href="assets/css/style.css">
<style>
.dashboard-config{max-width:1120px;margin:auto;padding-bottom:32px}.dc-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:22px}.dc-head h1{font-size:clamp(1.6rem,3vw,2.2rem);margin:0 0 8px}.dc-intro{max-width:760px;color:#6b7890;line-height:1.9;margin:0}.dc-group{padding:20px;margin:0 0 18px;background:#fff;border:1px solid #e3e9f2;border-radius:15px;box-shadow:0 7px 22px #18305308}.dc-group h2{font-size:1.05rem;margin:0 0 14px}.dc-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.dc-item{display:flex;align-items:flex-start;gap:12px;padding:14px;border:1px solid #e3e9f2;border-radius:11px;background:#fbfcfe;cursor:pointer}.dc-item:has(input:checked){border-color:#a9caff;background:#f0f6ff}.dc-item input{width:19px;height:19px;min-height:19px;flex:0 0 19px;margin:3px 0 0;accent-color:#176ee8}.dc-copy{display:flex;flex-direction:column;gap:5px}.dc-copy strong{font-size:.92rem;color:#20304a}.dc-copy small{font-size:.78rem;color:#738097;line-height:1.7}.dc-soon{align-self:flex-start;background:#fff3d2;color:#82631e!important;border-radius:99px;padding:3px 7px}.dc-button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:9px 16px;border:0;border-radius:9px;background:#176ee8;color:white;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.dc-secondary{background:#e9eef6;color:#263850}.dc-message{padding:12px 15px;border-radius:10px;margin-bottom:15px}.dc-ok{background:#e5f8ed;color:#12653d}.dc-error{background:#fff0f0;color:#a52b35}.dc-foot{display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap}.dc-note{color:#758197;font-size:.8rem;line-height:1.8}@media(max-width:720px){.dc-head{flex-direction:column}.dc-grid{grid-template-columns:1fr}.dc-group{padding:15px}}
</style></head><body class="profile-page"><div class="admin-layout"><?php require __DIR__ . '/includes/sidebar.php'; ?><main class="admin-main"><?php require __DIR__ . '/includes/topbar.php'; ?><div class="dashboard-config">
<header class="dc-head"><div><h1>تنظیمات داشبورد</h1><p class="dc-intro">انتخاب کنید کدام کارت‌ها، نمودارها و جدول‌ها در صفحه اصلی نمایش داده شوند. این تنظیمات برای همه کاربران داشبورد مشترک است و هر زمان می‌توانید آن‌ها را تغییر دهید.</p></div><a class="dc-button dc-secondary" href="dashboard.php">مشاهده داشبورد</a></header>
<?php if($success!==''):?><div class="dc-message dc-ok"><?= $esc($success) ?></div><?php endif;?><?php if($error!==''):?><div class="dc-message dc-error"><?= $esc($error) ?></div><?php endif;?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= $esc(csrf_token()) ?>">
<?php foreach($groups as $title=>$items):?><section class="dc-group"><h2><?= $esc($title) ?></h2><div class="dc-grid"><?php foreach($items as $key=>$item):?><label class="dc-item"><input type="checkbox" name="widgets[<?= $esc($key) ?>]" value="1" <?= $item['enabled']?'checked':'' ?> <?= !$item['ready']?'disabled':'' ?>><span class="dc-copy"><strong><?= $esc($item['label']) ?></strong><small><?= $esc($item['description']) ?></small><?php if(!$item['ready']):?><small class="dc-soon">ماژول مربوطه هنوز ساخته نشده</small><?php endif;?></span></label><?php endforeach;?></div></section><?php endforeach;?>
<div class="dc-foot"><span class="dc-note">مخفی‌کردن یک کارت فقط نمایش آن را تغییر می‌دهد؛ هیچ اطلاعاتی از دیتابیس حذف نمی‌شود. بخش‌های مالی، حاضری و امتحانات پس از اضافه‌شدن ماژول‌های مربوطه فعال خواهند شد.</span><button class="dc-button" type="submit">ذخیره تنظیمات</button></div></form></div></main></div></body></html>