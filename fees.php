<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_roles(['super_admin', 'finance_manager']);
require_once __DIR__ . '/config/database.php';
$pdo = database_connection();
$error = '';
$success = '';
function fees_h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('درخواست معتبر نیست. صفحه را تازه‌سازی کنید.');
    }
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_rule' || $action === 'update_rule') {
            $yearId = filter_var($_POST['school_year_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            $period = trim((string)($_POST['period_name'] ?? ''));
            $gradeFrom = filter_var($_POST['grade_from'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            $gradeTo = filter_var($_POST['grade_to'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            $amountRaw = trim((string)($_POST['amount'] ?? ''));
            $amount = filter_var($amountRaw, FILTER_VALIDATE_FLOAT);
            $dueDate = trim((string)($_POST['due_date'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($yearId < 1 || $period === '' || mb_strlen($period) > 100) throw new RuntimeException('سال تحصیلی و نام دوره الزامی است.');
            if ($gradeFrom < 1 || $gradeFrom > 12 || $gradeTo < $gradeFrom || $gradeTo > 12) throw new RuntimeException('بازه صنف باید از ۱ تا ۱۲ و صنف پایان بزرگ‌تر یا مساوی صنف شروع باشد.');
            if ($amount === false || !is_finite((float)$amount) || $amount < 0) throw new RuntimeException('مبلغ فیس معتبر نیست.');
            if ($dueDate !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dueDate)) throw new RuntimeException('تاریخ سررسید معتبر نیست.');
            if (mb_strlen($notes) > 500) throw new RuntimeException('یادداشت نباید بیشتر از ۵۰۰ نویسه باشد.');
            $ruleId = $action === 'update_rule' ? (filter_var($_POST['rule_id'] ?? null, FILTER_VALIDATE_INT) ?: 0) : 0;
            if ($action === 'update_rule' && $ruleId < 1) throw new RuntimeException('رکورد فیس برای ویرایش معتبر نیست.');
            $yearCheck = $pdo->prepare('SELECT id, is_closed FROM school_years WHERE id = ?');
            $yearCheck->execute([$yearId]);
            $year = $yearCheck->fetch();
            if (!$year || (int)$year['is_closed'] === 1) throw new RuntimeException('برای سال تعلیمی بسته‌شده نمی‌توان فیس تعیین یا ویرایش کرد.');
            $overlapSql = 'SELECT id FROM tuition_fee_rules WHERE school_year_id = ? AND period_name = ? AND NOT (grade_to < ? OR grade_from > ?)';
            $overlapParams = [$yearId, $period, $gradeFrom, $gradeTo];
            if ($ruleId > 0) { $overlapSql .= ' AND id <> ?'; $overlapParams[] = $ruleId; }
            $overlapSql .= ' LIMIT 1';
            $overlap = $pdo->prepare($overlapSql);
            $overlap->execute($overlapParams);
            if ($overlap->fetch()) throw new RuntimeException('برای این دوره، بخشی از این بازه صنفی قبلاً تعریف شده است. بازه‌ها نباید هم‌پوشانی داشته باشند.');
            if ($ruleId > 0) {
                $oldRuleStmt = $pdo->prepare('SELECT id, school_year_id FROM tuition_fee_rules WHERE id = ? LIMIT 1');
                $oldRuleStmt->execute([$ruleId]);
                $oldRule = $oldRuleStmt->fetch();
                if (!$oldRule) throw new RuntimeException('فیس موردنظر پیدا نشد.');
                if ((int)$oldRule['school_year_id'] !== $yearId) throw new RuntimeException('سال تعلیمی یک فیس موجود را نمی‌توان تغییر داد. برای سال دیگر فیس جداگانه ثبت کنید.');
                $pdo->beginTransaction();
                $update = $pdo->prepare('UPDATE tuition_fee_rules SET period_name=?, grade_from=?, grade_to=?, amount=?, due_date=?, notes=? WHERE id=?');
                $update->execute([$period, $gradeFrom, $gradeTo, $amount, $dueDate !== '' ? $dueDate : null, $notes, $ruleId]);
                // Update only charges that have never received a payment. Any charge with a payment
                // is a historical financial record and remains exactly as it was when paid.
                $chargesStmt = $pdo->prepare('SELECT c.id,c.student_id,c.school_year_id,d.discount_type,d.discount_value FROM student_fee_charges c LEFT JOIN student_year_discounts d ON d.student_id=c.student_id AND d.school_year_id=c.school_year_id WHERE c.fee_rule_id=? AND c.school_year_id=? AND NOT EXISTS (SELECT 1 FROM student_fee_payments p WHERE p.charge_id=c.id)');
                $chargesStmt->execute([$ruleId, $yearId]);
                $chargeUpdate = $pdo->prepare('UPDATE student_fee_charges SET period_name=?, amount_due=?, discount_amount=? WHERE id=?');
                foreach ($chargesStmt->fetchAll() as $charge) {
                    $discountType = (string)($charge['discount_type'] ?? 'none');
                    $discountValue = (float)($charge['discount_value'] ?? 0);
                    $discountAmount = $discountType === 'percent' ? round((float)$amount * $discountValue / 100, 2) : ($discountType === 'amount' ? min((float)$amount, $discountValue) : 0.0);
                    $netAmount = max(0, round((float)$amount - $discountAmount, 2));
                    $chargeUpdate->execute([$period, $netAmount, $discountAmount, (int)$charge['id']]);
                }
                $pdo->commit();
                $success = 'فیس دوره و بدهی‌های بدون پرداخت به‌روزرسانی شد. رسیدها و فیس‌های دارای پرداخت قبلی تغییر نکردند.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO tuition_fee_rules (school_year_id, period_name, grade_from, grade_to, amount, due_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$yearId, $period, $gradeFrom, $gradeTo, $amount, $dueDate !== '' ? $dueDate : null, $notes]);
                $success = 'فیس دوره برای تمام سال تعلیمی انتخاب‌شده ثبت شد.';
            }
        } elseif ($action === 'toggle_rule') {
            $ruleId = filter_var($_POST['rule_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            $newStatus = filter_var($_POST['is_active'] ?? null, FILTER_VALIDATE_INT);
            if ($ruleId < 1 || !in_array($newStatus, [0, 1], true)) throw new RuntimeException('وضعیت فیس معتبر نیست.');
            $ruleStmt = $pdo->prepare('SELECT id, school_year_id FROM tuition_fee_rules WHERE id = ? LIMIT 1');
            $ruleStmt->execute([$ruleId]);
            $rule = $ruleStmt->fetch();
            if (!$rule) throw new RuntimeException('فیس موردنظر پیدا نشد.');
            if ($newStatus === 1) {
                $yearStmt = $pdo->prepare('SELECT is_closed FROM school_years WHERE id = ?');
                $yearStmt->execute([(int)$rule['school_year_id']]);
                if ((int)$yearStmt->fetchColumn() === 1) throw new RuntimeException('فیس سال تعلیمی بسته‌شده را نمی‌توان فعال کرد.');
            }
            $toggleStmt = $pdo->prepare('UPDATE tuition_fee_rules SET is_active = ? WHERE id = ?');
            $toggleStmt->execute([$newStatus, $ruleId]);
            $success = $newStatus === 1
                ? 'فیس فعال شد و برای ثبت‌نام‌های جدید قابل انتخاب است.'
                : 'فیس غیرفعال شد. سوابق، بدهی‌ها و رسیدهای قبلی محفوظ هستند.';
        } elseif ($action === 'delete_rule') {
            $ruleId = filter_var($_POST['rule_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            if ($ruleId < 1) throw new RuntimeException('رکورد فیس معتبر نیست.');
            $check = $pdo->prepare('SELECT COUNT(*) FROM student_fee_charges WHERE fee_rule_id = ?');
            $check->execute([$ruleId]);
            if ((int)$check->fetchColumn() > 0) throw new RuntimeException('این فیس به پرونده شاگردان وصل است و حذف نمی‌شود؛ برای حفظ سوابق، آن را نگه دارید.');
            $stmt = $pdo->prepare('DELETE FROM tuition_fee_rules WHERE id = ?');
            $stmt->execute([$ruleId]);
            $success = 'فیس دوره حذف شد.';
        } else {
            throw new RuntimeException('عملیات نامعتبر است.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) $error = $e->getMessage();
        elseif ($e instanceof PDOException && $e->getCode() === '23000') $error = 'این فیس قبلاً برای همین سال، دوره و بازه صنفی ثبت شده است.';
        else { error_log('Tuition fee settings error: '.$e->getMessage()); $error = 'ذخیره فیس انجام نشد. مطمئن شوید به‌روزرسانی پایگاه داده اجرا شده است.'; }
    }
}
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$editingRule = null;
if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT * FROM tuition_fee_rules WHERE id=? LIMIT 1');
    $editStmt->execute([$editId]);
    $editingRule = $editStmt->fetch() ?: null;
}
$years = $pdo->query('SELECT id, name, is_active, is_closed FROM school_years ORDER BY id DESC')->fetchAll();
$rules = [];
try {
    $rules = $pdo->query('SELECT r.*, y.name AS year_name FROM tuition_fee_rules r JOIN school_years y ON y.id=r.school_year_id ORDER BY y.id DESC, r.is_active DESC, r.period_name, r.grade_from')->fetchAll();
} catch (PDOException $e) { $error = $error ?: 'ابتدا فایل به‌روزرسانی tuition_fees_and_payments را از بخش «به‌روزرسانی پایگاه داده» اجرا کنید.'; }
$activeYearId = 0;
foreach ($years as $year) if ((int)$year['is_active'] === 1 && (int)$year['is_closed'] === 0) { $activeYearId = (int)$year['id']; break; }
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تعیین فیس دوره‌ها</title><link rel="stylesheet" href="assets/css/style.css">
<style>
.fees-wrap{max-width:1150px;margin:24px auto;padding:0 18px}.fees-panel{background:#fff;border:1px solid #e1e7f0;border-radius:14px;padding:22px;margin:18px 0}.fees-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.fees-field label{display:block;font-weight:700;margin-bottom:7px}.fees-field input,.fees-field select,.fees-field textarea{width:100%;box-sizing:border-box;min-height:43px;border:1px solid #ccd5e2;border-radius:8px;padding:9px;font:inherit}.fees-field-wide{grid-column:1/-1}.fees-actions{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.fees-table-wrap{overflow-x:auto}.fees-table{width:100%;border-collapse:collapse}.fees-table th,.fees-table td{padding:11px;border-bottom:1px solid #e5e7eb;text-align:right;white-space:nowrap}.fees-table th{background:#f5f7fb}.fees-alert{padding:12px 15px;border-radius:9px;margin:12px 0;background:#e8f7ef;color:#176644}.fees-error{background:#fff0f0;color:#a52c36}.fees-help{color:#64748b;line-height:1.8}@media(max-width:750px){.fees-grid{grid-template-columns:1fr}.fees-field-wide{grid-column:auto}}
</style></head><body class="profile-page"><div class="admin-layout"><?php require __DIR__ . '/includes/sidebar.php'; ?><main class="admin-main"><?php require __DIR__ . '/includes/topbar.php'; ?><div class="fees-wrap">
<header class="page-header"><p class="eyebrow">امور مالی</p><h1>تعیین فیس دوره‌ها</h1><p class="fees-help">هر فیس برای همان سال تعلیمی ثبت می‌شود و در تمام همان سال اعتبار دارد. در صورت ویرایش مبلغ، پرداخت‌ها و رسیدهای قبلی محفوظ می‌مانند؛ فقط بدهی‌هایی که هنوز هیچ پرداختی ندارند به مبلغ جدید تغییر می‌کنند. برای سال تعلیمی جدید، فیس جداگانه ثبت کنید.</p></header>
<?php if ($success !== ''): ?><div class="fees-alert" role="status"><?=fees_h($success)?></div><?php endif; ?><?php if ($error !== ''): ?><div class="fees-alert fees-error" role="alert"><?=fees_h($error)?></div><?php endif; ?>
<section class="fees-panel"><h2><?=$editingRule ? 'ویرایش فیس دوره' : 'ثبت فیس جدید'?></h2><?php if($editingRule && (int)$editingRule['school_year_id'] > 0): ?><p class="fees-help">ویرایش فیس سال تعلیمی <?=fees_h($editingRule['school_year_id'])?>. پرداخت‌های ثبت‌شده و رسیدهای آن‌ها تغییر نمی‌کنند.</p><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?=fees_h(csrf_token())?>"><input type="hidden" name="action" value="<?=$editingRule ? 'update_rule' : 'save_rule'?>"><?php if($editingRule):?><input type="hidden" name="rule_id" value="<?=(int)$editingRule['id']?>"><?php endif;?><div class="fees-grid">
<div class="fees-field"><label for="school_year_id">سال تعلیمی *</label><select id="school_year_id" name="school_year_id" required><option value="">انتخاب سال</option><?php foreach($years as $y): if((int)$y['is_closed']===1) continue; ?><option value="<?=(int)$y['id']?>" <?=(int)$y['id']===(int)($editingRule['school_year_id'] ?? $activeYearId)?'selected':''?>><?=fees_h($y['name'])?><?=(int)$y['is_active']===1?' (سال فعلی)':''?></option><?php endforeach;?></select></div>
<div class="fees-field"><label for="period_name">نام دوره *</label><input id="period_name" name="period_name" required maxlength="100" value="<?=fees_h($editingRule['period_name'] ?? '')?>" placeholder="مثلاً فیس سالانه"></div>
<div class="fees-field"><label for="amount">مبلغ فیس *</label><input id="amount" name="amount" type="number" min="0" step="0.01" value="<?=isset($editingRule['amount']) ? fees_h($editingRule['amount']) : ''?>" required placeholder="مثلاً 5000"></div>
<div class="fees-field"><label for="grade_from">از صنف *</label><select id="grade_from" name="grade_from" required><?php for($g=1;$g<=12;$g++):?><option value="<?=$g?>" <?=(int)($editingRule['grade_from'] ?? 1)===$g?'selected':''?>><?=$g?></option><?php endfor;?></select></div>
<div class="fees-field"><label for="grade_to">تا صنف *</label><select id="grade_to" name="grade_to" required><?php for($g=1;$g<=12;$g++):?><option value="<?=$g?>" <?=(int)($editingRule['grade_to'] ?? 3)===$g?'selected':''?>><?=$g?></option><?php endfor;?></select></div>
<div class="fees-field"><label for="due_date">تاریخ سررسید (اختیاری)</label><input id="due_date" name="due_date" type="date" data-persian-date value="<?=fees_h($editingRule['due_date'] ?? '')?>"></div>
<div class="fees-field fees-field-wide"><label for="notes">یادداشت (اختیاری)</label><textarea id="notes" name="notes" maxlength="500" rows="2" placeholder="توضیحات دوره یا شرایط پرداخت"><?=fees_h($editingRule['notes'] ?? '')?></textarea></div>
<div class="fees-actions"><button class="button button-primary" type="submit"><?=$editingRule ? 'ذخیره تغییرات فیس' : 'ذخیره فیس دوره'?></button><?php if($editingRule):?><a class="button button-secondary" href="fees.php">لغو ویرایش</a><?php endif;?></div>
</div></form></section>
<section class="fees-panel"><h2>فیس‌های ثبت‌شده</h2><div class="fees-table-wrap"><table class="fees-table"><thead><tr><th>سال تعلیمی</th><th>دوره</th><th>بازه صنف</th><th>مبلغ</th><th>سررسید</th><th>یادداشت</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody><?php if(!$rules):?><tr><td colspan="8">هنوز فیس دوره‌ای ثبت نشده است.</td></tr><?php else: foreach($rules as $r):?><tr><td><?=fees_h($r['year_name'])?></td><td><?=fees_h($r['period_name'])?></td><td><?= (int)$r['grade_from']?> تا <?= (int)$r['grade_to']?></td><td><?=number_format((float)$r['amount'],2)?></td><td><?php if($r['due_date']):?><span data-persian-display-date="<?=fees_h($r['due_date'])?>"></span><?php else:?>—<?php endif;?></td><td><?=fees_h($r['notes'] ?: '—')?></td><td><?= (int)$r['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?></td><td><a class="button button-secondary" href="fees.php?edit=<?=(int)$r['id']?>">ویرایش</a> <form style="display:inline" method="post"><input type="hidden" name="csrf_token" value="<?=fees_h(csrf_token())?>"><input type="hidden" name="action" value="toggle_rule"><input type="hidden" name="rule_id" value="<?=(int)$r['id']?>"><input type="hidden" name="is_active" value="<?=(int)$r['is_active'] === 1 ? 0 : 1?>"><button class="button button-secondary" type="submit" onclick="return confirm('<?= (int)$r['is_active'] === 1 ? 'این فیس غیرفعال شود؟ سوابق قبلی حفظ می‌شوند.' : 'این فیس دوباره فعال شود؟' ?>');"><?= (int)$r['is_active'] === 1 ? 'غیرفعال‌کردن' : 'فعال‌کردن' ?></button></form> <?php if ((int)$r['is_active'] === 1): ?><form style="display:inline" method="post" onsubmit="return confirm('این فیس حذف شود؟');"><input type="hidden" name="csrf_token" value="<?=fees_h(csrf_token())?>"><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="rule_id" value="<?=(int)$r['id']?>"><button class="button button-secondary" type="submit">حذف</button></form><?php endif; ?></td></tr><?php endforeach; endif;?></tbody></table></div></section>
</div></main></div><link rel="stylesheet" href="assets/css/persian-calendar.css"><script src="assets/js/persian-calendar.js"></script><script src="assets/js/sidebar-clock.js" defer></script><script src="assets/js/sidebar-submenus.js" defer></script></body></html>