<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_roles(['super_admin', 'finance_manager']);
require_once __DIR__ . '/config/database.php';
$pdo = database_connection();
function financial_report_h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$year = $pdo->query('SELECT id,name FROM school_years WHERE is_active=1 AND is_closed=0 ORDER BY id DESC LIMIT 1')->fetch() ?: null;
$search = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'never_paid', 'balance', 'paid', 'no_fee'], true)) $status = 'all';
$page = max(1, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
$perPage = 50;
$offset = ($page - 1) * $perPage;
$rows = [];
$totalRows = 0;
$totalPages = 1;
$summary = ['students'=>0,'never_paid'=>0,'due'=>0,'paid'=>0,'balance'=>0];

if ($year) {
    try {
        $summarySql = 'SELECT COUNT(*) AS students,
          SUM(CASE WHEN COALESCE(allpay.paid,0)=0 THEN 1 ELSE 0 END) AS never_paid,
          COALESCE(SUM(COALESCE(ch.due,0)),0) AS due,
          COALESCE(SUM(COALESCE(pay.paid,0)),0) AS paid,
          COALESCE(SUM(GREATEST(COALESCE(ch.due,0)-COALESCE(pay.paid,0),0)),0) AS balance
          FROM student_enrollments e JOIN students s ON s.id=e.student_id
          LEFT JOIN (SELECT student_id,school_year_id,SUM(amount_due) AS due FROM student_fee_charges GROUP BY student_id,school_year_id) ch ON ch.student_id=s.id AND ch.school_year_id=e.school_year_id
          LEFT JOIN (SELECT c.student_id,c.school_year_id,SUM(p.amount) AS paid FROM student_fee_charges c JOIN student_fee_payments p ON p.charge_id=c.id GROUP BY c.student_id,c.school_year_id) pay ON pay.student_id=s.id AND pay.school_year_id=e.school_year_id
          LEFT JOIN (SELECT c.student_id,SUM(p.amount) AS paid FROM student_fee_charges c JOIN student_fee_payments p ON p.charge_id=c.id GROUP BY c.student_id) allpay ON allpay.student_id=s.id
          WHERE e.school_year_id=? AND e.is_current=1';
        $summaryStmt = $pdo->prepare($summarySql);
        $summaryStmt->execute([(int)$year['id']]);
        $summaryRow = $summaryStmt->fetch() ?: [];
        $summary = [
            'students'=>(int)($summaryRow['students'] ?? 0),
            'never_paid'=>(int)($summaryRow['never_paid'] ?? 0),
            'due'=>(float)($summaryRow['due'] ?? 0),
            'paid'=>(float)($summaryRow['paid'] ?? 0),
            'balance'=>(float)($summaryRow['balance'] ?? 0)
        ];

        $where = ['e.school_year_id=?', 'e.is_current=1'];
        $params = [(int)$year['id']];
        if ($search !== '') {
            $where[] = '(s.student_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.first_name," ",s.last_name) LIKE ? OR s.father_name LIKE ? OR s.register_book_number LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if ($status === 'never_paid') $where[] = 'COALESCE(allpay.paid,0)=0';
        elseif ($status === 'unpaid_year') $where[] = 'COALESCE(pay.paid,0)=0';
        elseif ($status === 'balance') $where[] = 'GREATEST(COALESCE(ch.due,0)-COALESCE(pay.paid,0),0)>0';
        elseif ($status === 'paid') $where[] = 'COALESCE(ch.due,0)>0 AND COALESCE(pay.paid,0)>=COALESCE(ch.due,0)';
        elseif ($status === 'no_fee') $where[] = 'COALESCE(ch.due,0)=0';
        $fromSql = ' FROM student_enrollments e JOIN students s ON s.id=e.student_id
          LEFT JOIN (SELECT student_id,school_year_id,SUM(amount_due) AS due FROM student_fee_charges GROUP BY student_id,school_year_id) ch ON ch.student_id=s.id AND ch.school_year_id=e.school_year_id
          LEFT JOIN (SELECT c.student_id,c.school_year_id,SUM(p.amount) AS paid FROM student_fee_charges c JOIN student_fee_payments p ON p.charge_id=c.id GROUP BY c.student_id,c.school_year_id) pay ON pay.student_id=s.id AND pay.school_year_id=e.school_year_id
          LEFT JOIN (SELECT c.student_id,SUM(p.amount) AS paid FROM student_fee_charges c JOIN student_fee_payments p ON p.charge_id=c.id GROUP BY c.student_id) allpay ON allpay.student_id=s.id
          WHERE ' . implode(' AND ', $where);
        $countStmt = $pdo->prepare('SELECT COUNT(*)' . $fromSql);
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

        $listSql = 'SELECT s.id,s.student_number,s.first_name,s.last_name,s.father_name,s.class_name,
          COALESCE(ch.due,0) AS due,COALESCE(pay.paid,0) AS paid,
          GREATEST(COALESCE(ch.due,0)-COALESCE(pay.paid,0),0) AS balance
          ' . $fromSql . ' ORDER BY s.last_name,s.first_name LIMIT ? OFFSET ?';
        $listStmt = $pdo->prepare($listSql);
        foreach ($params as $i => $value) $listStmt->bindValue($i + 1, $value, PDO::PARAM_STR);
        $listStmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
        $listStmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $listStmt->execute();
        $rows = $listStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Financial report query failed: ' . $e->getMessage());
    }
}
$makeUrl = static function (int $p): string {
    $query = $_GET;
    $query['page'] = $p;
    return 'financial_reports.php?' . http_build_query($query);
};
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>گزارش مالی شاگردان</title><link rel="stylesheet" href="assets/css/style.css">
<style>
.fr-wrap{max-width:1450px;margin:24px auto;padding:0 18px}.fr-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:14px;margin:18px 0}.fr-card,.fr-panel{background:#fff;border:1px solid #e1e7f0;border-radius:14px;padding:18px}.fr-card span{display:block;color:#64748b;font-size:13px}.fr-card strong{display:block;font-size:24px;margin-top:8px}.fr-filter{display:grid;grid-template-columns:minmax(240px,1.4fr) minmax(200px,.8fr) auto auto auto;gap:12px;align-items:end;padding:16px;margin:12px 0 18px;border:1px solid #e1e7f0;border-radius:12px;background:#fbfcff}.fr-filter label{display:flex;flex-direction:column;gap:5px;min-width:0}.fr-filter input,.fr-filter select{min-height:48px;width:100%;border:1px solid #ccd5e2;border-radius:9px;padding:10px 12px;font:inherit}.fr-filter input[type=search]{min-width:0}.fr-table-wrap{overflow-x:auto;margin-top:16px}.fr-table{width:100%;border-collapse:collapse}.fr-table th,.fr-table td{padding:11px;border-bottom:1px solid #e5e7eb;text-align:right;white-space:nowrap}.fr-table th{background:#f5f7fb}.fr-pill{display:inline-block;padding:4px 9px;border-radius:20px;background:#fee2e2;color:#991b1b}.fr-pill.paid{background:#dcfce7;color:#166534}.fr-pill.none{background:#e2e8f0;color:#475569}.fr-pages{display:flex;justify-content:center;align-items:center;gap:8px;margin:18px;flex-wrap:wrap}.fr-pages a,.fr-pages span{padding:7px 11px;border:1px solid #d7dfeb;border-radius:7px;text-decoration:none}.fr-pages .current{background:#1e293b;color:#fff}.fr-note{color:#64748b;font-size:13px}@media(max-width:1000px){.fr-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.fr-filter label:first-child{grid-column:1/-1}}@media(max-width:560px){.fr-filter{grid-template-columns:1fr}.fr-filter label:first-child{grid-column:auto}.fr-filter .button{width:100%}}@media print{.admin-sidebar,.admin-topbar,.fr-filter,.fr-pages,.button{display:none!important}.admin-main{margin:0!important}.fr-wrap{max-width:none;margin:0;padding:0}.fr-card,.fr-panel{break-inside:avoid}}
</style></head><body class="profile-page"><div class="admin-layout"><?php require __DIR__.'/includes/sidebar.php';?><main class="admin-main"><?php require __DIR__.'/includes/topbar.php';?><div class="fr-wrap">
<header class="page-header"><p class="eyebrow">امور مالی</p><h1>گزارش مالی شاگردان</h1><p class="fr-note">این گزارش همه شاگردان ثبت‌نام‌شده در سال تعلیمی فعال را نشان می‌دهد. فیلتر «تا حالا هیچ پرداختی نکرده‌اند» پرداخت‌های تمام سال‌های تعلیمی را بررسی می‌کند.</p></header>
<?php if(!$year):?><section class="fr-panel">ابتدا سال تعلیمی فعلی را فعال کنید.</section><?php else:?>
<div class="fr-cards"><div class="fr-card"><span>شاگردان ثبت‌نام‌شده</span><strong><?=number_format($summary['students'])?></strong></div><div class="fr-card"><span>شاگردان بدون هیچ پرداخت</span><strong><?=number_format($summary['never_paid'])?></strong></div><div class="fr-card"><span>مجموع فیس تعیین‌شده</span><strong><?=number_format($summary['due'],2)?></strong></div><div class="fr-card"><span>مجموع دریافت‌شده</span><strong><?=number_format($summary['paid'],2)?></strong></div><div class="fr-card"><span>مجموع باقی‌داری</span><strong><?=number_format($summary['balance'],2)?></strong></div></div>
<section class="fr-panel"><h2>فهرست شاگردان — <?=financial_report_h($year['name'])?></h2><form class="fr-filter" method="get"><label>جست‌وجوی شاگرد<input type="search" name="q" value="<?=financial_report_h($search)?>" placeholder="شماره شاگرد، نام، تخلص، نام پدر، شماره اساس"></label><label>فیلتر پرداخت<select name="status"><option value="all" <?=$status==='all'?'selected':''?>>همه شاگردان</option><option value="never_paid" <?=$status==='never_paid'?'selected':''?>>تا حالا هیچ پرداختی نکرده‌اند</option><option value="unpaid_year" <?=$status==='unpaid_year'?'selected':''?>>در این سال پرداخت نکرده‌اند</option><option value="balance" <?=$status==='balance'?'selected':''?>>دارای باقی‌داری</option><option value="paid" <?=$status==='paid'?'selected':''?>>فیس تکمیل‌شده</option><option value="no_fee" <?=$status==='no_fee'?'selected':''?>>فیس تعیین نشده</option></select></label><button class="button button-primary" type="submit">اعمال فیلتر</button><a class="button button-secondary" href="financial_reports.php">پاک‌کردن</a><button class="button button-secondary" type="button" onclick="window.print()">چاپ گزارش</button></form>
<p class="fr-note">تعداد نتایج: <?=number_format($totalRows)?> — هر صفحه حداکثر <?=number_format($perPage)?> شاگرد</p>
<?php if(!$rows):?><p>شاگردی با این فیلتر پیدا نشد.</p><?php else:?><div class="fr-table-wrap"><table class="fr-table"><thead><tr><th>شماره شاگرد</th><th>نام و تخلص</th><th>نام پدر</th><th>صنف</th><th>فیس تعیین‌شده</th><th>مجموع پرداخت</th><th>باقی‌داری</th><th>وضعیت</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=financial_report_h($r['student_number'])?></td><td><?=financial_report_h($r['first_name'].' '.$r['last_name'])?></td><td><?=financial_report_h($r['father_name']?:'—')?></td><td><?=financial_report_h($r['class_name'])?></td><td><?=number_format((float)$r['due'],2)?></td><td><?=number_format((float)$r['paid'],2)?></td><td><?=number_format((float)$r['balance'],2)?></td><td><?php if((float)$r['paid']<=0):?><span class="fr-pill">بدون پرداخت</span><?php elseif((float)$r['balance']<=0 && (float)$r['due']>0):?><span class="fr-pill paid">تکمیل‌شده</span><?php elseif((float)$r['due']<=0):?><span class="fr-pill none">فیس تعیین نشده</span><?php else:?><span class="fr-pill">باقی‌داری</span><?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
<?php if($totalPages>1):?><nav class="fr-pages" aria-label="صفحه‌های گزارش"><?php if($page>1):?><a href="<?=financial_report_h($makeUrl($page-1))?>">قبلی</a><?php endif;?><?php for($p=max(1,$page-2);$p<=min($totalPages,$page+2);$p++):?><?php if($p===$page):?><span class="current"><?=$p?></span><?php else:?><a href="<?=financial_report_h($makeUrl($p))?>"><?=$p?></a><?php endif;?><?php endfor;?><?php if($page<$totalPages):?><a href="<?=financial_report_h($makeUrl($page+1))?>">بعدی</a><?php endif;?></nav><?php endif;?>
<?php endif;?></section><?php endif;?>
</div></main></div><script src="assets/js/sidebar-clock.js" defer></script><script src="assets/js/sidebar-submenus.js" defer></script><script src="assets/js/form-validation.js" defer></script></body></html>