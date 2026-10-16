<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_roles(['super_admin', 'finance_manager']);
require_once __DIR__ . '/config/database.php';
$pdo = database_connection();
function payment_history_h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$search = trim((string)($_GET['q'] ?? ''));
$period = (string)($_GET['period'] ?? 'all');
if (!in_array($period, ['all', 'day', 'week', 'month', 'between'], true)) $period = 'all';
$selectedDate = trim((string)($_GET['date'] ?? date('Y-m-d')));
$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? date('Y-m-d')));
$receivedBy = filter_var($_GET['received_by'] ?? '', FILTER_VALIDATE_INT);
if ($receivedBy === false) $receivedBy = '';
$page = max(1, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
$perPage = 50;
$offset = ($page - 1) * $perPage;
$rows = [];
$totalRows = 0;
$totalPages = 1;
$totalAmount = 0.0;
$error = '';

$isValidDate = static function (string $date): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
};
$where = ['1=1'];
$params = [];
try {
    if ($period === 'day') {
        if (!$isValidDate($selectedDate)) throw new RuntimeException('تاریخ انتخاب‌شده معتبر نیست.');
        $where[] = 'p.received_at >= ? AND p.received_at < ?';
        $params[] = $selectedDate . ' 00:00:00';
        $params[] = (new DateTimeImmutable($selectedDate))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    } elseif ($period === 'week') {
        $start = (new DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d');
        $end = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $where[] = 'p.received_at >= ? AND p.received_at < ?';
        $params[] = $start . ' 00:00:00';
        $params[] = $end . ' 00:00:00';
    } elseif ($period === 'month') {
        $start = date('Y-m-01');
        $end = (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        $where[] = 'p.received_at >= ? AND p.received_at < ?';
        $params[] = $start . ' 00:00:00';
        $params[] = $end . ' 00:00:00';
    } elseif ($period === 'between') {
        if (!$isValidDate($dateFrom) || !$isValidDate($dateTo) || $dateFrom > $dateTo) {
            throw new RuntimeException('لطفاً تاریخ شروع و پایان معتبر انتخاب کنید.');
        }
        $where[] = 'p.received_at >= ? AND p.received_at < ?';
        $params[] = $dateFrom . ' 00:00:00';
        $params[] = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }
    if ($search !== '') {
        $where[] = '(s.student_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.first_name, " ", s.last_name) LIKE ? OR s.father_name LIKE ? OR p.receipt_number LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    if ($receivedBy !== '' && (int)$receivedBy > 0) {
        $where[] = 'p.received_by = ?';
        $params[] = (int)$receivedBy;
    }
    $whereSql = implode(' AND ', $where);
    $fromSql = ' FROM student_fee_payments p JOIN student_fee_charges c ON c.id=p.charge_id JOIN school_years y ON y.id=c.school_year_id JOIN students s ON s.id=c.student_id LEFT JOIN users u ON u.id=p.received_by WHERE ' . $whereSql;

    $count = $pdo->prepare('SELECT COUNT(*)' . $fromSql);
    $count->execute($params);
    $totalRows = (int)$count->fetchColumn();
    $summary = $pdo->prepare('SELECT COALESCE(SUM(p.amount),0)' . $fromSql);
    $summary->execute($params);
    $totalAmount = (float)$summary->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

    $sql = 'SELECT p.id,p.receipt_number,p.amount,p.payment_method,p.notes,p.received_at,c.period_name,y.name AS year_name,s.student_number,s.first_name,s.last_name,s.father_name,u.full_name AS received_by_full_name,u.username AS received_by_username' . $fromSql . ' ORDER BY p.received_at DESC,p.id DESC LIMIT ? OFFSET ?';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $i => $value) $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $receivers = $pdo->query('SELECT DISTINCT u.id,u.username,u.full_name FROM users u JOIN student_fee_payments p ON p.received_by=u.id ORDER BY u.full_name,u.username')->fetchAll();
} catch (Throwable $e) {
    if ($e instanceof RuntimeException) $error = $e->getMessage();
    else {
        error_log('Payment history listing error: ' . $e->getMessage());
        $error = 'تاریخچه پرداخت‌ها بارگذاری نشد. لطفاً ساختار پایگاه داده و به‌روزرسانی‌ها را بررسی کنید.';
    }
    $receivers = [];
}
$methods = ['cash' => 'نقدی', 'bank' => 'بانکی', 'other' => 'دیگر'];
$pageUrl = static function (int $p): string {
    $query = $_GET;
    $query['page'] = $p;
    return 'payment_history.php?' . http_build_query($query);
};
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>تاریخچه پرداخت‌ها و رسیدها</title><link rel="stylesheet" href="assets/css/style.css">
<style>
.ph-wrap{max-width:1450px;margin:24px auto;padding:0 18px}.ph-panel{background:#fff;border:1px solid #e1e7f0;border-radius:14px;padding:20px;margin:18px 0}.ph-muted{color:#64748b}.ph-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.ph-filters label{display:flex;flex-direction:column;gap:5px}.ph-filters input,.ph-filters select{min-height:39px;border:1px solid #ccd5e2;border-radius:7px;padding:7px;font:inherit;max-width:100%}.ph-filters input[type=search]{min-width:260px}.ph-stats{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}.ph-stat{background:#f5f7fb;border-radius:10px;padding:12px 16px}.ph-stat strong{display:block;font-size:20px;margin-top:4px}.ph-table-wrap{overflow-x:auto}.ph-table{width:100%;border-collapse:collapse}.ph-table th,.ph-table td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:right;white-space:nowrap}.ph-table th{background:#f5f7fb}.ph-alert{padding:12px 15px;border-radius:9px;margin:12px 0;background:#fff0f0;color:#a52c36}.ph-pagination{display:flex;gap:8px;align-items:center;justify-content:center;flex-wrap:wrap;margin-top:16px}.ph-pagination a,.ph-pagination span{padding:7px 11px;border:1px solid #d7dfeb;border-radius:7px;text-decoration:none}.ph-pagination .current{background:#1e293b;color:white}#ph-day-field[hidden],#ph-from-field[hidden],#ph-to-field[hidden]{display:none!important}@media(max-width:760px){.ph-filters label{flex:1;min-width:130px}.ph-filters input[type=search]{min-width:0;width:100%}}
</style>
</head>
<body class="profile-page"><div class="admin-layout"><?php require __DIR__.'/includes/sidebar.php';?><main class="admin-main"><?php require __DIR__.'/includes/topbar.php';?>
<div class="ph-wrap">
<header class="page-header"><p class="eyebrow">امور مالی</p><h1>تاریخچه پرداخت‌ها و رسیدها</h1><p class="ph-muted">پرداخت‌ها را بر اساس روز، هفته، ماه، بازهٔ تاریخ، شاگرد یا دریافت‌کنندهٔ وجه جست‌وجو کنید.</p></header>
<?php if($error!==''):?><div class="ph-alert" role="alert"><?=payment_history_h($error)?></div><?php endif;?>
<section class="ph-panel">
<form class="ph-filters" method="get">
<label>بازهٔ زمانی<select name="period" id="ph-period"><option value="all" <?=$period==='all'?'selected':''?>>تمام تاریخ‌ها</option><option value="day" <?=$period==='day'?'selected':''?>>یک روز مشخص</option><option value="week" <?=$period==='week'?'selected':''?>>هفت روز گذشته</option><option value="month" <?=$period==='month'?'selected':''?>>ماه جاری</option><option value="between" <?=$period==='between'?'selected':''?>>بین دو تاریخ</option></select></label>
<label id="ph-day-field">روز<input type="date" name="date" value="<?=payment_history_h($selectedDate)?>"></label>
<label id="ph-from-field">از تاریخ<input type="date" name="date_from" value="<?=payment_history_h($dateFrom)?>"></label>
<label id="ph-to-field">تا تاریخ<input type="date" name="date_to" value="<?=payment_history_h($dateTo)?>"></label>
<label>شاگرد / شماره رسید<input type="search" name="q" value="<?=payment_history_h($search)?>" placeholder="شماره شاگرد، نام، نام پدر یا رسید"></label>
<label>دریافت‌کنندهٔ وجه<select name="received_by"><option value="">همهٔ کاربران</option><?php foreach($receivers as $receiver):?><option value="<?=(int)$receiver['id']?>" <?=((string)$receivedBy===(string)$receiver['id'])?'selected':''?>><?=payment_history_h(trim((string)$receiver['full_name'])!==''?$receiver['full_name']:$receiver['username'])?></option><?php endforeach;?></select></label>
<button class="button button-primary" type="submit">اعمال فیلتر</button><a class="button button-secondary" href="payment_history.php">پاک‌کردن فیلترها</a>
</form>
<div class="ph-stats"><div class="ph-stat"><span>تعداد پرداخت‌ها</span><strong><?=number_format($totalRows)?></strong></div><div class="ph-stat"><span>مجموع مبلغ مطابق فیلتر</span><strong><?=number_format($totalAmount,2)?></strong></div></div>
<?php if(!$rows):?><p>پرداختی مطابق فیلترهای انتخاب‌شده پیدا نشد.</p><?php else:?><div class="ph-table-wrap"><table class="ph-table"><thead><tr><th>شماره رسید</th><th>شماره شاگرد</th><th>نام شاگرد</th><th>نام پدر</th><th>سال تعلیمی</th><th>دوره</th><th>مبلغ</th><th>روش پرداخت</th><th>دریافت‌کنندهٔ وجه</th><th>تاریخ و ساعت</th></tr></thead><tbody>
<?php foreach($rows as $row):?><tr><td><a href="fee_receipt.php?receipt=<?=rawurlencode((string)$row['receipt_number'])?>" target="_blank" rel="noopener"><?=payment_history_h($row['receipt_number'])?></a></td><td><?=payment_history_h($row['student_number'])?></td><td><?=payment_history_h($row['first_name'].' '.$row['last_name'])?></td><td><?=payment_history_h($row['father_name']?:'—')?></td><td><?=payment_history_h($row['year_name'])?></td><td><?=payment_history_h($row['period_name'])?></td><td><?=number_format((float)$row['amount'],2)?></td><td><?=payment_history_h($methods[$row['payment_method']]??'دیگر')?></td><td><?=payment_history_h($row['received_by_full_name']?:($row['received_by_username']?:'ثبت نشده (پرداخت قدیمی)'))?></td><td><?=payment_history_h($row['received_at'])?></td></tr><?php endforeach;?></tbody></table></div>
<?php if($totalPages>1):?><nav class="ph-pagination" aria-label="صفحه‌های تاریخچه"><?php if($page>1):?><a href="<?=payment_history_h($pageUrl($page-1))?>">قبلی</a><?php endif;?><?php for($p=max(1,$page-2);$p<=min($totalPages,$page+2);$p++):?><?php if($p===$page):?><span class="current"><?=$p?></span><?php else:?><a href="<?=payment_history_h($pageUrl($p))?>"><?=$p?></a><?php endif;?><?php endfor;?><?php if($page<$totalPages):?><a href="<?=payment_history_h($pageUrl($page+1))?>">بعدی</a><?php endif;?></nav><?php endif;?>
<?php endif;?>
</section></div></main></div><script src="assets/js/sidebar-clock.js" defer></script><script src="assets/js/sidebar-submenus.js" defer></script>
<script>
(function(){const p=document.getElementById('ph-period');const day=document.getElementById('ph-day-field');const from=document.getElementById('ph-from-field');const to=document.getElementById('ph-to-field');function sync(){day.hidden=p.value!=='day';from.hidden=p.value!=='between';to.hidden=p.value!=='between';}p.addEventListener('change',sync);sync();})();
</script></body></html>
