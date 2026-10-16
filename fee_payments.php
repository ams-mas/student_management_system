<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_roles(['super_admin','finance_manager']);
require_once __DIR__ . '/config/database.php';
$pdo = database_connection();
$error = '';
$success = '';
$receiptNumber = '';
function fee_pay_h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('درخواست معتبر نیست. صفحه را تازه‌سازی کنید.');
    }
    try {
        if ((string)($_POST['action'] ?? '') !== 'receive_payment') {
            throw new RuntimeException('عملیات نامعتبر است.');
        }
        $chargeId = filter_var($_POST['charge_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $amount = filter_var(trim((string)($_POST['amount'] ?? '')), FILTER_VALIDATE_FLOAT);
        $method = (string)($_POST['payment_method'] ?? 'cash');
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($chargeId < 1 || $amount === false || !is_finite((float)$amount) || (float)$amount <= 0) {
            throw new RuntimeException('مبلغ پرداخت باید بیشتر از صفر باشد.');
        }
        if (!in_array($method, ['cash', 'bank', 'other'], true)) {
            throw new RuntimeException('روش پرداخت معتبر نیست.');
        }
        if (mb_strlen($notes) > 500) {
            throw new RuntimeException('یادداشت نباید بیشتر از ۵۰۰ نویسه باشد.');
        }
        $pdo->beginTransaction();
        $q = $pdo->prepare('SELECT c.id,c.student_id,c.school_year_id,c.amount_due,c.period_name,s.first_name,s.last_name FROM student_fee_charges c JOIN students s ON s.id=c.student_id WHERE c.id=? FOR UPDATE');
        $q->execute([$chargeId]);
        $charge = $q->fetch();
        if (!$charge) {
            throw new RuntimeException('بدهی موردنظر پیدا نشد.');
        }
        $paidQ = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM student_fee_payments WHERE charge_id=?');
        $paidQ->execute([$chargeId]);
        $paid = (float)$paidQ->fetchColumn();
        $remaining = max(0, (float)$charge['amount_due'] - $paid);
        if ((float)$amount > $remaining + 0.0001) {
            throw new RuntimeException('مبلغ پرداخت از باقی‌داری بیشتر است. باقی‌داری فعلی: ' . number_format($remaining, 2));
        }
        $receipt = 'RC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        // Store the authenticated account that actually received this payment.
        $receivedBy = (int)($_SESSION['user_id'] ?? 0);
        if ($receivedBy < 1) throw new RuntimeException('کاربر واردشده شناسایی نشد. دوباره وارد سیستم شوید.');
        $insert = $pdo->prepare('INSERT INTO student_fee_payments (charge_id,amount,receipt_number,payment_method,notes,received_by) VALUES (?,?,?,?,?,?)');
        $insert->execute([$chargeId, $amount, $receipt, $method, $notes, $receivedBy]);
        $receiptNumber = $receipt;
        $pdo->commit();
        $success = 'پرداخت ثبت شد. شماره رسید: ' . $receipt . ' — ' . $charge['first_name'] . ' ' . $charge['last_name'] . '، ' . $charge['period_name'] . '، مبلغ ' . number_format((float)$amount, 2);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) $error = $e->getMessage();
        else {
            error_log('Student fee payment error: ' . $e->getMessage());
            $error = 'پرداخت ذخیره نشد. بررسی کنید به‌روزرسانی فیس‌ها اجرا شده باشد.';
        }
    }
}

$year = $pdo->query('SELECT id,name FROM school_years WHERE is_active=1 AND is_closed=0 ORDER BY id DESC LIMIT 1')->fetch() ?: null;
$search = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'unpaid', 'paid', 'unassigned'], true)) $status = 'all';
$page = max(1, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
$perPage = 50;
$offset = ($page - 1) * $perPage;
$students = [];
$totalRows = 0;
$totalPages = 1;

if ($year) {
    try {
        $where = ['1=1'];
        // Start from the students table so older student records are not hidden
        // merely because they do not yet have a current-year enrollment row.
        // The current school year is applied to fee charges in the LEFT JOIN below.
        $yearId = (int)$year['id'];
        $params = [];
        if ($search !== '') {
            $where[] = '(s.student_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.first_name, " ", s.last_name) LIKE ? OR s.father_name LIKE ? OR s.register_book_number LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        $paidExpr = 'COALESCE((SELECT SUM(pp.amount) FROM student_fee_payments pp WHERE pp.charge_id=c.id),0)';
        if ($status === 'unassigned') $where[] = 'c.id IS NULL';
        elseif ($status === 'unpaid') $where[] = 'c.id IS NOT NULL AND ' . $paidExpr . ' < c.amount_due';
        elseif ($status === 'paid') $where[] = 'c.id IS NOT NULL AND ' . $paidExpr . ' >= c.amount_due';
        $whereSql = implode(' AND ', $where);

        $countSql = 'SELECT COUNT(*) FROM students s LEFT JOIN student_fee_charges c ON c.student_id=s.id AND c.school_year_id=? WHERE ' . $whereSql;
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute(array_merge([$yearId], $params));
        $totalRows = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $listSql = 'SELECT c.id AS charge_id,c.period_name,c.amount_due,c.discount_amount,s.id AS student_id,s.student_number,s.register_book_number,s.first_name,s.last_name,s.father_name,s.class_name,' . $paidExpr . ' AS paid FROM students s LEFT JOIN student_fee_charges c ON c.student_id=s.id AND c.school_year_id=? WHERE ' . $whereSql . ' ORDER BY s.last_name,s.first_name,c.id LIMIT ? OFFSET ?';
        $listStmt = $pdo->prepare($listSql);
        $listParams = array_merge([$yearId], $params, [$perPage, $offset]);
        foreach ($listParams as $i => $value) {
            $isIntegerParameter = ($i === 0 || $i >= count($params) + 1);
            $listStmt->bindValue($i + 1, $value, $isIntegerParameter ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $listStmt->execute();
        $students = $listStmt->fetchAll();

    } catch (PDOException $e) {
        error_log('Student fee listing error: ' . $e->getMessage());
        $error = $error ?: 'ابتدا به‌روزرسانی فیس‌ها را از بخش به‌روزرسانی پایگاه داده اجرا کنید.';
    }
}
$methods = ['cash' => 'نقدی', 'bank' => 'بانکی', 'other' => 'دیگر'];
$makePageUrl = static function (int $p, string $key = 'page'): string {
    $query = $_GET;
    $query[$key] = $p;
    return 'fee_payments.php?' . http_build_query($query);
};
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>دریافت فیس شاگردان</title><link rel="stylesheet" href="assets/css/style.css">
<style>
.pay-wrap{max-width:1450px;margin:24px auto;padding:0 18px}.pay-panel{background:#fff;border:1px solid #e1e7f0;border-radius:14px;padding:20px;margin:18px 0}.pay-alert{padding:12px 15px;border-radius:9px;margin:12px 0;background:#e8f7ef;color:#176644}.pay-error{background:#fff0f0;color:#a52c36}.pay-table-wrap{overflow-x:auto}.pay-table{width:100%;border-collapse:collapse}.pay-table th,.pay-table td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:right;white-space:nowrap}.pay-table th{background:#f5f7fb}.pay-form{display:grid;grid-template-columns:minmax(90px,110px) minmax(100px,120px);align-items:center;gap:7px;min-width:220px;padding:10px;border:1px solid #e1e7f0;border-radius:10px;background:#fbfcff}.pay-form button{grid-column:1/-1}.pay-form input,.pay-form select,.pay-filter input,.pay-filter select{min-height:46px;font-size:15px}.pay-form input[name=notes]{grid-column:1/-1;width:100%}.pay-form input,.pay-form select,.pay-filter input,.pay-filter select{min-height:38px;border:1px solid #ccd5e2;border-radius:7px;padding:7px;font:inherit}.pay-form input[type=number]{width:110px}.pay-form input[name=notes]{width:120px}.pay-status{font-weight:700}.pay-muted{color:#64748b}.pay-filter{display:grid;grid-template-columns:minmax(240px,1.4fr) minmax(180px,.7fr) auto auto;gap:12px;align-items:end;padding:16px;margin:12px 0 18px;border:1px solid #e1e7f0;border-radius:12px;background:#fbfcff}.pay-filter label{min-width:0}.pay-filter input[type=search]{min-width:0;width:100%}.pay-filter label{display:flex;flex-direction:column;gap:5px}.pay-filter input[type=search]{min-width:280px}.pay-count{font-size:13px;color:#64748b;margin:12px 0}.pay-pill{display:inline-block;padding:4px 9px;border-radius:20px;background:#fef3c7;color:#92400e}.pay-pill.paid{background:#dcfce7;color:#166534}.pay-pill.none{background:#e2e8f0;color:#475569}.pay-pagination{display:flex;gap:8px;align-items:center;justify-content:center;flex-wrap:wrap;margin-top:16px}.pay-pagination a,.pay-pagination span{padding:7px 11px;border:1px solid #d7dfeb;border-radius:7px;text-decoration:none}.pay-pagination .current{background:#1e293b;color:white}.pay-history-title{margin-top:4px}@media(max-width:900px){.pay-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.pay-filter label:first-child{grid-column:1/-1}}@media(max-width:520px){.pay-filter{grid-template-columns:1fr}.pay-filter label:first-child{grid-column:auto}.pay-filter .button{width:100%}.pay-form{min-width:180px}}
</style>
</head>
<body class="profile-page"><div class="admin-layout"><?php require __DIR__.'/includes/sidebar.php';?><main class="admin-main"><?php require __DIR__.'/includes/topbar.php';?>
<div class="pay-wrap">
<header class="page-header"><p class="eyebrow">امور مالی</p><h1>دریافت فیس شاگردان</h1><p class="pay-muted">شاگردان ثبت‌نام‌شده، پرداخت‌شده‌ها، باقی‌داری‌ها و تاریخچه تمام رسیدها در همین صفحه قابل جست‌وجو است.</p></header>
<?php if($success!==''):?><div class="pay-alert" role="status"><?=fee_pay_h($success)?><?php if($receiptNumber!==''):?> — <a href="fee_receipt.php?receipt=<?=rawurlencode($receiptNumber)?>" target="_blank" rel="noopener">چاپ رسید</a><?php endif;?></div><?php endif;?>
<?php if($error!==''):?><div class="pay-alert pay-error" role="alert"><?=fee_pay_h($error)?></div><?php endif;?>
<section class="pay-panel">
<h2>فهرست فیس سال تعلیمی <?=fee_pay_h($year['name']??'')?></h2>
<?php if(!$year):?><p>ابتدا سال تعلیمی فعلی را فعال کنید.</p><?php else:?>
<form class="pay-filter" method="get">
<label>جست‌وجوی شاگرد یا رسید<input type="search" name="q" value="<?=fee_pay_h($search)?>" placeholder="شماره شاگرد، نام، تخلص، نام پدر، شماره اساس یا رسید"></label>
<label>وضعیت فیس<select name="status"><option value="all" <?=$status==='all'?'selected':''?>>همه شاگردان</option><option value="unpaid" <?=$status==='unpaid'?'selected':''?>>دارای باقی‌داری</option><option value="paid" <?=$status==='paid'?'selected':''?>>فیس تکمیل‌شده</option><option value="unassigned" <?=$status==='unassigned'?'selected':''?>>فیس تعیین‌نشده</option></select></label>
<button class="button button-primary" type="submit">جست‌وجو</button><a class="button button-secondary" href="fee_payments.php">پاک‌کردن فیلتر</a>
</form>
<p class="pay-count">تعداد ردیف‌های مطابق جست‌وجو: <?=number_format($totalRows)?> — در هر صفحه حداکثر <?=number_format($perPage)?> ردیف</p>
<?php if(!$students):?><p>شاگردی با این مشخصات پیدا نشد. جست‌وجو یا فیلتر را تغییر دهید.</p><?php else:?>
<div class="pay-table-wrap"><table class="pay-table"><thead><tr><th>شماره شاگرد</th><th>نام و تخلص</th><th>نام پدر</th><th>صنف</th><th>دوره</th><th>فیس پس از تخفیف</th><th>پرداخت‌شده</th><th>باقی‌داری</th><th>وضعیت</th><th>ثبت پرداخت</th></tr></thead><tbody>
<?php foreach($students as $s):
$hasCharge = $s['charge_id'] !== null;
$due = $hasCharge ? (float)$s['amount_due'] : 0.0;
$paid = (float)$s['paid'];
$remaining = max(0, $due - $paid);
?>
<tr><td><?=fee_pay_h($s['student_number'])?></td><td><?=fee_pay_h($s['first_name'].' '.$s['last_name'])?></td><td><?=fee_pay_h($s['father_name']?:'—')?></td><td><?=fee_pay_h($s['class_name'])?></td><td><?=fee_pay_h($s['period_name']??'—')?></td><td><?=$hasCharge?number_format($due,2):'—'?></td><td><?=number_format($paid,2)?></td><td class="pay-status"><?=$hasCharge?number_format($remaining,2):'—'?></td><td><?php if(!$hasCharge):?><span class="pay-pill none">فیس تعیین نشده</span><?php elseif($remaining<=0):?><span class="pay-pill paid">تکمیل‌شده</span><?php else:?><span class="pay-pill">باقی‌داری</span><?php endif;?></td><td>
<?php if($hasCharge && $remaining>0):?><form class="pay-form" method="post"><input type="hidden" name="csrf_token" value="<?=fee_pay_h(csrf_token())?>"><input type="hidden" name="action" value="receive_payment"><input type="hidden" name="charge_id" value="<?=(int)$s['charge_id']?>"><input name="amount" type="number" min="0.01" max="<?=number_format($remaining,2,'.','')?>" step="0.01" required placeholder="مبلغ" aria-label="مبلغ پرداخت"><select name="payment_method" aria-label="روش پرداخت"><option value="cash">نقدی</option><option value="bank">بانکی</option><option value="other">دیگر</option></select><input name="notes" maxlength="500" placeholder="یادداشت اختیاری" aria-label="یادداشت پرداخت"><button class="button button-primary" type="submit">ثبت و صدور رسید</button></form>
<?php elseif($hasCharge):?><span>بدون باقی‌داری</span><?php else:?><span class="pay-muted">ابتدا فیس دوره را تعیین کنید</span><?php endif;?>
</td></tr>
<?php endforeach;?></tbody></table></div>
<?php if($totalPages>1):?><nav class="pay-pagination" aria-label="صفحه‌های فهرست"><?php if($page>1):?><a href="<?=fee_pay_h($makePageUrl($page-1))?>">قبلی</a><?php endif;?><?php for($p=max(1,$page-2);$p<=min($totalPages,$page+2);$p++):?><?php if($p===$page):?><span class="current"><?=$p?></span><?php else:?><a href="<?=fee_pay_h($makePageUrl($p))?>"><?=$p?></a><?php endif;?><?php endfor;?><?php if($page<$totalPages):?><a href="<?=fee_pay_h($makePageUrl($page+1))?>">بعدی</a><?php endif;?></nav><?php endif;?>
<?php endif;endif;?>
</section>

</div></main></div><script src="assets/js/sidebar-clock.js" defer></script><script src="assets/js/sidebar-submenus.js" defer></script><script src="assets/js/form-validation.js" defer></script></body></html>