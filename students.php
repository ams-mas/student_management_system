<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_roles(['super_admin', 'head_teacher', 'administrative_staff', 'teacher']);
require_once __DIR__ . '/config/database.php';

$pdo = database_connection();
$role = current_role();
$canEdit = in_array($role, ['super_admin', 'head_teacher', 'administrative_staff'], true);
$canManageFees = $role === 'super_admin';
$canManageStudentRecords = in_array($role, ['super_admin', 'head_teacher'], true);
$roleLabels = ['super_admin' => 'مدیر کل', 'head_teacher' => 'سرمعلم', 'finance_manager' => 'مدیر مالی', 'administrative_staff' => 'کارمند اداری', 'teacher' => 'معلم'];
$error = '';
$success = '';
$successReceipt = '';
$editing = null;

if (isset($_SESSION['students_flash']) && is_array($_SESSION['students_flash'])) {
    $success = (string) ($_SESSION['students_flash']['success'] ?? '');
    $successReceipt = (string) ($_SESSION['students_flash']['receipt'] ?? '');
    $error = (string) ($_SESSION['students_flash']['error'] ?? '');
    unset($_SESSION['students_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid form token. Refresh the page and try again.');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if (!$canEdit) {
            throw new RuntimeException('شما اجازه تغییر پرونده‌های دانش‌آموزان را ندارید.');
        }
        if ($action === 'save') {
            $id = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
            $number = '';
            if ($id) {
                // A student's permanent ID must never be changed by editing a form.
                $existingNumberStmt = $pdo->prepare('SELECT student_number FROM students WHERE id = :id LIMIT 1');
                $existingNumberStmt->execute(['id' => $id]);
                $existingNumber = $existingNumberStmt->fetchColumn();
                if ($existingNumber === false) {
                    throw new RuntimeException('پرونده شاگرد پیدا نشد.');
                }
                $number = (string)$existingNumber;
            } else {
                // Temporary unique value; replaced with ST-<student id> after INSERT.
                $number = 'TMP-' . strtoupper(bin2hex(random_bytes(8)));
            }
            $bookNumber = trim((string)($_POST['register_book_number'] ?? ''));
            $first = trim((string)($_POST['first_name'] ?? ''));
            $last = trim((string)($_POST['last_name'] ?? ''));
            $fatherName = trim((string)($_POST['father_name'] ?? ''));
            $grandfatherName = trim((string)($_POST['grandfather_name'] ?? ''));
            $gender = (string)($_POST['gender'] ?? 'unspecified');
            $dob = trim((string)($_POST['date_of_birth'] ?? ''));
            $classSectionId = filter_var($_POST['class_section_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            $class = '';
            $phone = trim((string)($_POST['phone'] ?? ''));
            $guardian = trim((string)($_POST['guardian_name'] ?? ''));
            $guardianPhone = trim((string)($_POST['guardian_phone'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $discountType = $canManageFees ? (string)($_POST['discount_type'] ?? 'none') : 'none';
            $discountRaw = $canManageFees ? trim((string)($_POST['discount_value'] ?? '')) : '0';
            $discountValue = $discountRaw === '' ? 0.0 : filter_var($discountRaw, FILTER_VALIDATE_FLOAT);
            if (!in_array($discountType, ['none', 'percent', 'amount'], true)) {
                throw new RuntimeException('نوع تخفیف معتبر نیست.');
            }
            if ($discountValue === false || !is_finite((float)$discountValue) || $discountValue < 0) {
                throw new RuntimeException('مقدار تخفیف باید صفر یا یک عدد مثبت باشد.');
            }
            if ($discountType === 'percent' && $discountValue > 100) {
                throw new RuntimeException('تخفیف درصدی نمی‌تواند بیشتر از ۱۰۰ درصد باشد.');
            }
            if ($discountType !== 'none' && $discountValue <= 0) {
                throw new RuntimeException('برای ثبت تخفیف، مقدار آن باید بیشتر از صفر باشد.');
            }
            if ($id && !preg_match('/^[A-Za-z0-9._\\/-]{1,40}$/', $number)) {
                throw new RuntimeException('شماره مسلسل معتبر نیست.');
            }
            if (mb_strlen($bookNumber) > 100) {
                throw new RuntimeException('شماره کتاب اساس نباید بیشتر از ۱۰۰ نویسه باشد.');
            }
            if ($first === '' || $last === '' || mb_strlen($first) > 100 || mb_strlen($last) > 100) {
                throw new RuntimeException('نام و نام خانوادگی الزامی است و نباید بیشتر از ۱۰۰ نویسه باشد.');
            }
            $activeYearStmt = $pdo->query('SELECT id FROM school_years WHERE is_active = 1 AND is_closed = 0 ORDER BY id DESC LIMIT 1');
            $activeSchoolYearId = (int)($activeYearStmt->fetchColumn() ?: 0);
            if ($classSectionId > 0) {
                if ($activeSchoolYearId < 1) {
                    throw new RuntimeException('ابتدا یک سال تحصیلی فعلی را در تنظیمات مدرسه فعال کنید.');
                }
                $sectionStmt = $pdo->prepare('SELECT grade, section_name FROM class_sections WHERE id = :id AND school_year_id = :year_id AND is_active = 1 LIMIT 1');
                $sectionStmt->execute(['id' => $classSectionId, 'year_id' => $activeSchoolYearId]);
                $selectedSection = $sectionStmt->fetch();
                if (!$selectedSection) {
                    throw new RuntimeException('صنف انتخاب‌شده مربوط به سال تحصیلی فعلی نیست یا فعال نمی‌باشد.');
                }
                $gradeDigits = (string)$selectedSection['grade'];
                $class = $gradeDigits . ' ' . trim((string)$selectedSection['section_name']);
            } elseif ($activeSchoolYearId > 0) {
                throw new RuntimeException('لطفاً صنف دانش‌آموز را از فهرست صنف‌های سال تحصیلی فعلی انتخاب کنید.');
            } else {
                throw new RuntimeException('هیچ سال تحصیلی فعالی وجود ندارد. ابتدا سال تحصیلی را تنظیم کنید.');
            }
            $selectedGrade = (int)$selectedSection['grade'];
            $feeRuleId = $canManageFees ? (filter_var($_POST['fee_rule_id'] ?? null, FILTER_VALIDATE_INT) ?: 0) : 0;
            $initialPaymentRaw = $canManageFees ? trim((string)($_POST['initial_payment'] ?? '')) : '0';
            $initialPayment = $initialPaymentRaw === '' ? 0.0 : filter_var($initialPaymentRaw, FILTER_VALIDATE_FLOAT);
            if ($initialPayment === false || !is_finite((float)$initialPayment) || $initialPayment < 0) {
                throw new RuntimeException('مبلغ پرداخت اولیه معتبر نیست.');
            }
            $matchingFeeRules = [];
            $selectedFeeRule = null;
            $selectedFeeNet = 0.0;
            if (!$id) {
                $feeRulesStmt = $pdo->prepare('SELECT id, period_name, grade_from, grade_to, amount FROM tuition_fee_rules WHERE school_year_id = :year_id AND is_active = 1 AND grade_from <= :grade_min AND grade_to >= :grade_max ORDER BY id');
                $feeRulesStmt->execute(['year_id' => $activeSchoolYearId, 'grade_min' => $selectedGrade, 'grade_max' => $selectedGrade]);
                $matchingFeeRules = $feeRulesStmt->fetchAll();
                if ($canManageFees) {
                    if (!$matchingFeeRules) {
                        throw new RuntimeException('برای صنف انتخاب‌شده هنوز فیس دوره‌ای تعیین نشده است. ابتدا از بخش «تعیین فیس دوره‌ها» فیس را ثبت کنید.');
                    }
                    foreach ($matchingFeeRules as $feeRule) {
                        if ((int)$feeRule['id'] === $feeRuleId) { $selectedFeeRule = $feeRule; break; }
                    }
                    if (!$selectedFeeRule) {
                        throw new RuntimeException('لطفاً یکی از دوره‌های فیس مربوط به صنف انتخاب‌شده را انتخاب کنید.');
                    }
                    $selectedFeeBase = (float)$selectedFeeRule['amount'];
                    $selectedDiscountAmount = $discountType === 'percent'
                        ? round($selectedFeeBase * (float)$discountValue / 100, 2)
                        : ($discountType === 'amount' ? min($selectedFeeBase, (float)$discountValue) : 0.0);
                    $selectedFeeNet = max(0, round($selectedFeeBase - $selectedDiscountAmount, 2));
                    if ((float)$initialPayment > $selectedFeeNet) {
                        throw new RuntimeException('پرداخت اولیه نمی‌تواند بیشتر از فیس دوره پس از تخفیف باشد.');
                    }
                } else {
                    // Educational staff can register students without seeing fees or recording payments.
                    $initialPayment = 0.0;
                }
            } else {
                $initialPayment = 0.0;
            }
            if (!in_array($gender, ['female','male','unspecified'], true)) {
                throw new RuntimeException('جنسیت انتخاب‌شده معتبر نیست.');
            }
            if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
                throw new RuntimeException('تاریخ تولد معتبر نیست.');
            }
            if (mb_strlen($fatherName) > 100 || mb_strlen($grandfatherName) > 100) {
                throw new RuntimeException('نام پدر و نام پدرکلان نباید بیشتر از ۱۰۰ نویسه باشد.');
            }
            $params = ['number'=>$number,'book_number'=>$bookNumber !== '' ? $bookNumber : null,'first'=>$first,'last'=>$last,'father_name'=>$fatherName !== '' ? $fatherName : null,'grandfather_name'=>$grandfatherName !== '' ? $grandfatherName : null,'gender'=>$gender,'dob'=>$dob !== '' ? $dob : null,'class'=>$class,'phone'=>$phone,'guardian'=>$guardian,'guardian_phone'=>$guardianPhone,'address'=>$address];
            $receiptNumber = '';
            $pdo->beginTransaction();
            if ($id) {
                $params['id'] = $id;
                $stmt = $pdo->prepare('UPDATE students SET student_number=:number, register_book_number=:book_number, first_name=:first, last_name=:last, father_name=:father_name, grandfather_name=:grandfather_name, gender=:gender, date_of_birth=:dob, class_name=:class, phone=:phone, guardian_name=:guardian, guardian_phone=:guardian_phone, address=:address WHERE id=:id');
                $stmt->execute($params);

                // Write the register-book number separately with an explicit PDO type.
                // This makes the value's persistence independent of the larger student update.
                $bookUpdateStmt = $pdo->prepare('UPDATE students SET register_book_number = :book_number WHERE id = :id');
                $bookUpdateStmt->bindValue(':book_number', $bookNumber === '' ? null : $bookNumber, $bookNumber === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $bookUpdateStmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
                $bookUpdateStmt->execute();

                $verifyBookStmt = $pdo->prepare('SELECT register_book_number FROM students WHERE id = :id LIMIT 1');
                $verifyBookStmt->execute(['id' => $id]);
                $savedBookNumber = $verifyBookStmt->fetchColumn();
                if (($bookNumber === '' && $savedBookNumber !== null) || ($bookNumber !== '' && (string)$savedBookNumber !== $bookNumber)) {
                    throw new RuntimeException('شماره کتاب اساس در پایگاه داده ذخیره نشد. لطفاً صفحه را تازه‌سازی کنید و دوباره تلاش کنید.');
                }
                $savedStudentId = (int)$id;
            } else {
                $stmt = $pdo->prepare('INSERT INTO students (student_number, register_book_number, first_name, last_name, father_name, grandfather_name, gender, date_of_birth, class_name, phone, guardian_name, guardian_phone, address) VALUES (:number,:book_number,:first,:last,:father_name,:grandfather_name,:gender,:dob,:class,:phone,:guardian,:guardian_phone,:address)');
                $stmt->execute($params);
                $newStudentId = (int)$pdo->lastInsertId();
                $number = 'ST-' . sprintf('%04d', $newStudentId);
                $studentNumberUpdate = $pdo->prepare('UPDATE students SET student_number = :number WHERE id = :id');
                $studentNumberUpdate->execute(['number' => $number, 'id' => $newStudentId]);

                // Save the book number separately for new records as well. Persian/Arabic
                // letters and digits are valid UTF-8 text and must be stored unchanged.
                $bookInsertUpdateStmt = $pdo->prepare('UPDATE students SET register_book_number = :book_number WHERE id = :id');
                $bookInsertUpdateStmt->bindValue(':book_number', $bookNumber === '' ? null : $bookNumber, $bookNumber === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $bookInsertUpdateStmt->bindValue(':id', $newStudentId, PDO::PARAM_INT);
                $bookInsertUpdateStmt->execute();

                $verifyBookStmt = $pdo->prepare('SELECT register_book_number FROM students WHERE id = :id LIMIT 1');
                $verifyBookStmt->execute(['id' => $newStudentId]);
                $savedBookNumber = $verifyBookStmt->fetchColumn();
                if (($bookNumber === '' && $savedBookNumber !== null) || ($bookNumber !== '' && (string)$savedBookNumber !== $bookNumber)) {
                    throw new RuntimeException('شماره کتاب اساس در پایگاه داده ذخیره نشد. لطفاً صفحه را تازه‌سازی کنید و دوباره تلاش کنید.');
                }
                $savedStudentId = $newStudentId;
            }
            // Close the previous year's current enrollment while preserving its record and history.
            $closePriorEnrollmentsStmt = $pdo->prepare('UPDATE student_enrollments SET is_current = 0, ended_at = COALESCE(ended_at, CURRENT_TIMESTAMP) WHERE student_id = :student_id AND school_year_id <> :year_id AND is_current = 1');
            $closePriorEnrollmentsStmt->execute(['student_id' => $savedStudentId, 'year_id' => $activeSchoolYearId]);

            // Keep a year-specific enrollment record instead of storing class placement only as text.
            $currentEnrollmentStmt = $pdo->prepare('SELECT id, class_section_id FROM student_enrollments WHERE student_id = :student_id AND school_year_id = :year_id AND is_current = 1 ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $currentEnrollmentStmt->execute(['student_id' => $savedStudentId, 'year_id' => $activeSchoolYearId]);
            $currentEnrollment = $currentEnrollmentStmt->fetch();
            if (!$currentEnrollment) {
                $enrollmentInsert = $pdo->prepare("INSERT INTO student_enrollments (student_id, school_year_id, class_section_id, status, is_current, notes) VALUES (:student_id, :year_id, :section_id, 'enrolled', 1, '')");
                $enrollmentInsert->execute(['student_id' => $savedStudentId, 'year_id' => $activeSchoolYearId, 'section_id' => $classSectionId]);
                $enrollmentId = (int)$pdo->lastInsertId();
                $historyInsert = $pdo->prepare("INSERT INTO student_enrollment_history (enrollment_id, student_id, school_year_id, from_section_id, to_section_id, changed_by, change_type, notes) VALUES (:enrollment_id, :student_id, :year_id, NULL, :to_section, NULL, 'assigned', 'ثبت‌نام در سال تعلیمی')");
                $historyInsert->execute(['enrollment_id' => $enrollmentId, 'student_id' => $savedStudentId, 'year_id' => $activeSchoolYearId, 'to_section' => $classSectionId]);
            } else {
                $enrollmentId = (int)$currentEnrollment['id'];
                $previousSectionId = (int)$currentEnrollment['class_section_id'];
                if ($previousSectionId !== $classSectionId) {
                    $enrollmentUpdate = $pdo->prepare('UPDATE student_enrollments SET class_section_id = :section_id WHERE id = :id');
                    $enrollmentUpdate->execute(['section_id' => $classSectionId, 'id' => $enrollmentId]);
                    $historyInsert = $pdo->prepare("INSERT INTO student_enrollment_history (enrollment_id, student_id, school_year_id, from_section_id, to_section_id, changed_by, change_type, notes) VALUES (:enrollment_id, :student_id, :year_id, :from_section, :to_section, NULL, 'transferred', 'تغییر صنف از صفحه ویرایش شاگرد')");
                    $historyInsert->execute(['enrollment_id' => $enrollmentId, 'student_id' => $savedStudentId, 'year_id' => $activeSchoolYearId, 'from_section' => $previousSectionId, 'to_section' => $classSectionId]);
                }
            }
            if (!$id) {
                foreach ($matchingFeeRules as $feeRule) {
                    $base = (float)$feeRule['amount'];
                    $discountAmountForRule = $discountType === 'percent'
                        ? round($base * (float)$discountValue / 100, 2)
                        : ($discountType === 'amount' ? min($base, (float)$discountValue) : 0.0);
                    $netAmount = max(0, round($base - $discountAmountForRule, 2));
                    $chargeInsert = $pdo->prepare('INSERT INTO student_fee_charges (student_id, school_year_id, fee_rule_id, period_name, amount_due, discount_amount) VALUES (:student_id, :year_id, :rule_id, :period_name, :amount_due, :discount_amount)');
                    $chargeInsert->execute(['student_id' => $savedStudentId, 'year_id' => $activeSchoolYearId, 'rule_id' => (int)$feeRule['id'], 'period_name' => (string)$feeRule['period_name'], 'amount_due' => $netAmount, 'discount_amount' => $discountAmountForRule]);
                    $chargeId = (int)$pdo->lastInsertId();
                    if ((int)$feeRule['id'] === $feeRuleId && (float)$initialPayment > 0) {
                        $receiptNumber = 'RC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
                        $paymentInsert = $pdo->prepare("INSERT INTO student_fee_payments (charge_id, amount, receipt_number, payment_method, notes) VALUES (:charge_id, :amount, :receipt, :method, :notes)");
                        $paymentInsert->execute(['charge_id' => $chargeId, 'amount' => $initialPayment, 'receipt' => $receiptNumber, 'method' => in_array((string)($_POST['initial_payment_method'] ?? 'cash'), ['cash','bank','other'], true) ? (string)($_POST['initial_payment_method'] ?? 'cash') : 'cash', 'notes' => 'پرداخت هنگام ثبت‌نام']);
                    }
                }
            }
            if ($canManageFees) {
            if ($activeSchoolYearId < 1) {
                throw new RuntimeException('برای ثبت تخفیف، سال تحصیلی فعلی باید فعال باشد.');
            }
            if ($discountType === 'none') {
                $discountDeleteStmt = $pdo->prepare('DELETE FROM student_year_discounts WHERE student_id = :student_id AND school_year_id = :year_id');
                $discountDeleteStmt->execute(['student_id' => $savedStudentId, 'year_id' => $activeSchoolYearId]);
            } else {
                $discountSaveStmt = $pdo->prepare('INSERT INTO student_year_discounts (student_id, school_year_id, discount_type, discount_value) VALUES (:student_id, :year_id, :discount_type, :discount_value) ON DUPLICATE KEY UPDATE discount_type = VALUES(discount_type), discount_value = VALUES(discount_value)');
                $discountSaveStmt->execute(['student_id' => $savedStudentId, 'year_id' => $activeSchoolYearId, 'discount_type' => $discountType, 'discount_value' => $discountValue]);
            }
            }
            $pdo->commit();
            $successMessage = $id ? 'پرونده دانش‌آموز به‌روزرسانی شد.' : ($canManageFees ? 'دانش‌آموز با موفقیت ثبت شد و فیس دوره‌ها ثبت گردید.' : 'دانش‌آموز با موفقیت ثبت شد.');
            if (!$id && $receiptNumber !== '') {
                $successMessage .= ' شماره رسید پرداخت: ' . $receiptNumber . ' (برای چاپ به بخش دریافت فیس مراجعه کنید).';
            }
            $_SESSION['students_flash'] = ['success' => $successMessage, 'receipt' => (!$id ? $receiptNumber : '')];
            header('Location: students.php');
            exit;
        }
        if ($action === 'delete') {
            if (!$canManageStudentRecords) throw new RuntimeException('فقط مدیر کل یا سرمعلم می‌تواند پرونده شاگرد را حذف کند.');
            $id = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) throw new RuntimeException('شناسه پرونده معتبر نیست.');
            $stmt = $pdo->prepare('DELETE FROM students WHERE id=:id');
            $stmt->execute(['id'=>$id]);
            if ($stmt->rowCount() < 1) throw new RuntimeException('پرونده دانش‌آموز پیدا نشد.');
            $_SESSION['students_flash'] = ['success' => 'پرونده دانش‌آموز حذف شد.'];
            header('Location: students.php');
            exit;
        }
        if ($action === 'toggle') {
            if (!$canManageStudentRecords) throw new RuntimeException('فقط مدیر کل یا سرمعلم می‌تواند وضعیت پرونده شاگرد را تغییر دهد.');
            $id = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) throw new RuntimeException('شناسه پرونده معتبر نیست.');
            $stmt = $pdo->prepare('UPDATE students SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=:id');
            $stmt->execute(['id'=>$id]);
            $_SESSION['students_flash'] = ['success' => 'وضعیت پرونده تغییر کرد.'];
            header('Location: students.php');
            exit;
        }
        throw new RuntimeException('درخواست نامعتبر است.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = $e->getMessage();
        // Keep the edit form visible after validation errors instead of silently
        // returning to the list when the POST request has no ?edit=... query.
        $postedStudentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
        if ($action === 'save' && $postedStudentId) {
            $reloadEditingStmt = $pdo->prepare('SELECT * FROM students WHERE id = :id LIMIT 1');
            $reloadEditingStmt->execute(['id' => $postedStudentId]);
            $editing = $reloadEditingStmt->fetch() ?: null;
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e->getCode() === '23000') {
            $error = 'این شماره دانش‌آموزی قبلاً ثبت شده است.';
        } else {
            error_log('Student management error: '.$e->getMessage());
            $error = 'ذخیره اطلاعات انجام نشد. وجود جدول دانش‌آموزان در پایگاه داده را بررسی کنید.';
        }
        $postedStudentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
        if ($action === 'save' && $postedStudentId) {
            $reloadEditingStmt = $pdo->prepare('SELECT * FROM students WHERE id = :id LIMIT 1');
            $reloadEditingStmt->execute(['id' => $postedStudentId]);
            $editing = $reloadEditingStmt->fetch() ?: null;
        }
    }
}

if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($editId) {
        header('Location: edit_student.php?edit=' . $editId);
        exit;
    }
}
$search = trim((string)($_GET['q'] ?? ''));
$filterGender = (string)($_GET['gender'] ?? '');
$filterClass = trim((string)($_GET['class_name'] ?? ''));
$filterAge = trim((string)($_GET['age'] ?? ''));
$where = [];
$params = [];
if ($role === 'teacher') {
    $where[] = 'id IN (SELECT e.student_id FROM student_enrollments e JOIN class_sections cs ON cs.id = e.class_section_id JOIN teachers t ON t.id = cs.teacher_id WHERE t.user_id = :teacher_user_id AND e.is_current = 1)';
    $params['teacher_user_id'] = (int)$_SESSION['user_id'];
}
if ($search !== '') {
    $where[] = "(student_number LIKE :q1 OR CONCAT('R', LPAD(id, 8, '0')) LIKE :q7 OR first_name LIKE :q2 OR last_name LIKE :q3 OR father_name LIKE :q8 OR grandfather_name LIKE :q9 OR class_name LIKE :q4 OR guardian_name LIKE :q5 OR register_book_number LIKE :q6)";
    $term = '%'.$search.'%';
    $params += ['q1'=>$term, 'q2'=>$term, 'q3'=>$term, 'q4'=>$term, 'q5'=>$term, 'q6'=>$term, 'q7'=>$term, 'q8'=>$term, 'q9'=>$term];
}
if (in_array($filterGender, ['female','male','unspecified'], true)) {
    $where[] = 'gender = :filter_gender';
    $params['filter_gender'] = $filterGender;
}
if ($filterClass !== '') {
    $where[] = 'class_name = :filter_class';
    $params['filter_class'] = $filterClass;
}
if ($filterAge !== '' && ctype_digit($filterAge) && (int)$filterAge <= 120) {
    $where[] = 'date_of_birth IS NOT NULL AND TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) = :filter_age';
    $params['filter_age'] = (int)$filterAge;
}
$sql = 'SELECT * FROM students';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY is_active DESC, last_name ASC, first_name ASC LIMIT 500';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();
if ($role === 'teacher') {
    $teacherCountStmt = $pdo->prepare('SELECT COUNT(*) FROM students s WHERE s.id IN (SELECT e.student_id FROM student_enrollments e JOIN class_sections cs ON cs.id=e.class_section_id JOIN teachers t ON t.id=cs.teacher_id WHERE t.user_id=? AND e.is_current=1)');
    $teacherCountStmt->execute([(int)$_SESSION['user_id']]);
    $total = (int)$teacherCountStmt->fetchColumn();
    $teacherActiveStmt = $pdo->prepare('SELECT COUNT(*) FROM students s WHERE s.is_active=1 AND s.id IN (SELECT e.student_id FROM student_enrollments e JOIN class_sections cs ON cs.id=e.class_section_id JOIN teachers t ON t.id=cs.teacher_id WHERE t.user_id=? AND e.is_current=1)');
    $teacherActiveStmt->execute([(int)$_SESSION['user_id']]);
    $active = (int)$teacherActiveStmt->fetchColumn();
} else {
    $total = (int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
    $active = (int)$pdo->query('SELECT COUNT(*) FROM students WHERE is_active=1')->fetchColumn();
}
$activeSchoolYear = $pdo->query('SELECT id, name FROM school_years WHERE is_active = 1 AND is_closed = 0 ORDER BY id DESC LIMIT 1')->fetch() ?: null;
$currentDiscount = ['discount_type' => 'none', 'discount_value' => ''];
if ($canManageFees && $editing && $activeSchoolYear) {
    try {
        $discountReadStmt = $pdo->prepare('SELECT discount_type, discount_value FROM student_year_discounts WHERE student_id = :student_id AND school_year_id = :year_id LIMIT 1');
        $discountReadStmt->execute(['student_id' => (int)$editing['id'], 'year_id' => (int)$activeSchoolYear['id']]);
        $savedDiscount = $discountReadStmt->fetch();
        if ($savedDiscount) {
            $currentDiscount = ['discount_type' => (string)$savedDiscount['discount_type'], 'discount_value' => (string)$savedDiscount['discount_value']];
        }
    } catch (PDOException $e) {
        // The migration may not have been applied yet; the form remains usable without a saved discount.
    }
}
$classOptions = [];
if ($activeSchoolYear) {
    $sectionOptionsStmt = $pdo->prepare('SELECT id, grade, section_name FROM class_sections WHERE school_year_id = :year_id AND is_active = 1 ORDER BY grade ASC, section_name ASC');
    $sectionOptionsStmt->execute(['year_id' => (int)$activeSchoolYear['id']]);
    $classOptions = $sectionOptionsStmt->fetchAll();
}
$feeRulesForForm = [];
if ($canManageFees && $activeSchoolYear) {
    try {
        $feeRulesFormStmt = $pdo->prepare('SELECT id, period_name, grade_from, grade_to, amount FROM tuition_fee_rules WHERE school_year_id = :year_id AND is_active = 1 ORDER BY period_name, grade_from');
        $feeRulesFormStmt->execute(['year_id' => (int)$activeSchoolYear['id']]);
        $feeRulesForForm = $feeRulesFormStmt->fetchAll();
    } catch (PDOException $e) {
        $feeRulesForForm = [];
    }
}
$showForm = $canEdit && (isset($_GET['new']) || $editing !== null);
$nextStudentId = (int)($pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'")->fetchColumn() ?: 0);
if ($nextStudentId < 1) {
    $nextStudentId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM students')->fetchColumn();
}
function student_escape(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$genderLabels = ['female'=>'دختر','male'=>'پسر','unspecified'=>'ثبت نشده'];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>مدیریت دانش‌آموزان | سامانه مدیریت دانش‌آموزان</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
.students-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:20px;align-items:start}
.student-toolbar{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 16px}
.students-filters{display:grid;grid-template-columns:minmax(200px,2fr) repeat(3,minmax(130px,1fr));gap:12px;align-items:end}
.students-filters label{display:flex;flex-direction:column;gap:6px;font-weight:700;font-size:13px}
.students-filters input,.students-filters select{box-sizing:border-box;width:100%;min-height:42px;padding:9px 10px;border:1px solid #d5deeb;border-radius:9px;background:#fff;font:inherit;color:var(--ink)}
.filter-actions{display:flex;gap:8px;flex-wrap:wrap;grid-column:1/-1}
.button-danger{background:#b42332;color:#fff;border:1px solid #b42332}

.student-form-panel{max-width:1100px;margin-inline:auto}
.student-form-heading{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:22px;padding-bottom:16px;border-bottom:1px solid var(--line)}
.student-form-heading h2{margin:0 0 6px}

.student-form-heading p{margin:0}
.student-form-section{padding:18px 0;border-bottom:1px solid var(--line)}
.student-form-section:last-of-type{border-bottom:0}
.student-form-section h3{font-size:16px;margin:0 0 14px;color:var(--ink)}
.student-form-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 20px}
.student-field{min-width:0}
.student-field label{display:block;margin:0 0 7px;font-weight:700;font-size:14px}
.student-field input,.student-field select,.student-field textarea{box-sizing:border-box;width:100%;min-height:44px;padding:10px 12px;border:1px solid #d5deeb;border-radius:10px;background:#fff;color:var(--ink);font:inherit}
.student-field textarea{min-height:88px;resize:vertical}
.student-field small{display:block;margin-top:6px;color:#64748b;font-size:12px;line-height:1.7}
.student-number-note{display:flex;flex-direction:column;justify-content:center;min-height:70px;padding:10px 12px;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc}
.student-number-note span{font-weight:700;font-size:14px}
.student-field-wide{grid-column:1/-1}
.students-form label{display:block;margin:12px 0 6px;font-weight:700;font-size:14px}
.students-form input,.students-form select,.students-form textarea{box-sizing:border-box;width:100%;min-height:44px;padding:10px 12px;border:1px solid #d5deeb;border-radius:10px;background:#fff;color:var(--ink);font:inherit}
.students-form textarea{min-height:88px;resize:vertical}
.students-form .form-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
.students-form .button{font-size:14px}
.students-table-wrap{overflow-x:auto;width:100%}
.students-table{width:100%;border-collapse:collapse;white-space:nowrap;text-align:right}
.students-table th,.students-table td{padding:11px 9px;border-bottom:1px solid var(--line);font-size:13px;vertical-align:middle}
.students-table th{background:#f5f7fb;color:#56637a}
.students-table .student-inactive{opacity:.62}
.students-flash{margin-bottom:16px;padding:12px 15px;border-radius:10px;background:#e8f7ef;color:#176644}
.students-error{background:#fff0f0;color:#a52c36}
.student-actions{display:flex;gap:6px;align-items:center}
.student-actions form{margin:0}
.student-actions .button{padding:7px 9px;min-height:34px;font-size:12px}
.students-search{display:flex;gap:8px;align-items:center;margin:12px 0 18px}
.students-search input{max-width:360px}
.students-metrics{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.students-metric{padding:10px 14px;border:1px solid var(--line);border-radius:12px;background:#fff}
.students-metric strong{display:block;font-size:20px}
@media(max-width:1000px){.students-filters{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.student-form-fields{grid-template-columns:1fr}.student-field-wide{grid-column:auto}.student-form-heading{align-items:flex-start;flex-direction:column}}
@media(max-width:600px){.students-filters{grid-template-columns:1fr}.students-search input{max-width:none}.student-actions{flex-wrap:wrap}}
</style>
</head>
<body class="profile-page">
<div class="admin-layout">

<?php require __DIR__ . '/includes/sidebar.php'; ?>
        <main class="admin-main">
            <?php require __DIR__ . '/includes/topbar.php'; ?>
<header class="page-header dashboard-header"><div><p class="eyebrow">پرونده‌ها</p><h1>مدیریت دانش‌آموزان</h1><p class="muted">ثبت، جست‌وجو و به‌روزرسانی پرونده‌های دانش‌آموزان.</p></div><div class="account-actions"><span class="signed-in-as">نقش شما: <strong><?= student_escape($roleLabels[$role] ?? $role) ?></strong></span></div></header>
<?php if ($success !== ''): ?><div class="students-flash" role="status"><?= student_escape($success) ?><?php if ($successReceipt !== ''): ?> — <a href="fee_receipt.php?receipt=<?= rawurlencode($successReceipt) ?>" target="_blank" rel="noopener">چاپ رسید پرداخت</a><?php endif; ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="students-flash students-error" role="alert"><?= student_escape($error) ?></div><?php endif; ?>
<?php if (!$showForm): ?><div class="students-metrics"><div class="students-metric"><span>همه دانش‌آموزان</span><strong><?= $total ?></strong></div><div class="students-metric"><span>پرونده‌های فعال</span><strong><?= $active ?></strong></div></div><?php endif; ?>
<?php $mode = (string)($_GET['mode'] ?? ''); ?>
<?php if ($showForm): ?><div class="student-toolbar"><a class="button button-secondary" href="students.php">بازگشت به فهرست دانش‌آموزان</a></div><?php else: ?><div class="student-toolbar"><?php if ($canEdit): ?><a class="button button-primary" href="students.php?new=1">＋ ثبت دانش‌آموز</a><?php endif; ?><a class="button button-secondary" href="students.php">فهرست دانش‌آموزان</a></div><?php endif; ?>
<div class="students-grid">
<?php if ($showForm): ?>
<section class="panel student-form-panel"><div class="student-form-heading"><div><h2><?= $editing ? 'ویرایش دانش‌آموز فعلی' : 'ثبت دانش‌آموز جدید' ?></h2></div></div>
<form class="students-form" method="post" action="students.php?new=1">
<input type="hidden" name="csrf_token" value="<?= student_escape(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="student_id" value="<?= student_escape($editing['id'] ?? '') ?>"><input type="hidden" name="student_number" value="<?= student_escape($editing['student_number'] ?? '') ?>">
<div class="student-form-section"><h3>مشخصات دانش‌آموز</h3><div class="student-form-fields">
<div class="student-field"><label>شماره دائمی شاگرد</label><input type="text" value="<?= student_escape($editing['student_number'] ?? ('ST-' . sprintf('%04d', (int)($nextStudentId ?: 1)))) ?>" readonly aria-readonly="true"><small>شماره اصلی شاگرد ثابت می‌ماند و در سال‌های تعلیمی بعدی نیز استفاده می‌شود.</small></div>
<div class="student-field"><label for="register_book_number">شماره کتاب اساس (برای دانش‌آموزان قبلی)</label><input id="register_book_number" name="register_book_number" maxlength="100" value="<?= student_escape($editing['register_book_number'] ?? '') ?>" placeholder="شماره ثبت‌شده در کتاب اساس"><small>برای دانش‌آموزانی که پیش از این سامانه ثبت شده‌اند.</small></div>

<div class="student-field"><label for="first_name">نام *</label><input id="first_name" name="first_name" required maxlength="100" value="<?= student_escape($editing['first_name'] ?? '') ?>"></div>
<div class="student-field"><label for="last_name">نام خانوادگی *</label><input id="last_name" name="last_name" required maxlength="100" value="<?= student_escape($editing['last_name'] ?? '') ?>"></div>
<div class="student-field"><label for="father_name">نام پدر</label><input id="father_name" name="father_name" maxlength="100" value="<?= student_escape($editing['father_name'] ?? '') ?>"></div>
<div class="student-field"><label for="grandfather_name">نام پدرکلان</label><input id="grandfather_name" name="grandfather_name" maxlength="100" value="<?= student_escape($editing['grandfather_name'] ?? '') ?>"></div>
<div class="student-field"><label for="gender">جنسیت</label><select id="gender" name="gender"><option value="unspecified">ثبت نشده</option><option value="female" <?= (($editing['gender'] ?? '')==='female')?'selected':'' ?>>دختر</option><option value="male" <?= (($editing['gender'] ?? '')==='male')?'selected':'' ?>>پسر</option></select></div>
<div class="student-field"><label for="date_of_birth">تاریخ تولد (هجری شمسی)</label><input id="date_of_birth" name="date_of_birth" type="date" data-persian-date value="<?= student_escape($editing['date_of_birth'] ?? '') ?>"></div>
<div class="student-field"><label for="class_section_id">صنف / کلاس سال تحصیلی فعلی *</label><select id="class_section_id" name="class_section_id" required><option value="">انتخاب صنف</option><?php foreach ($classOptions as $classOption): ?><option data-grade="<?= (int)$classOption['grade'] ?>" value="<?= (int)$classOption['id'] ?>" <?= ((int)($editing['class_section_id'] ?? 0) === (int)$classOption['id'] || (($editing['class_name'] ?? '') === ((string)$classOption['grade'].' '.(string)$classOption['section_name']))) ? 'selected' : '' ?>><?= student_escape((string)$classOption['grade'].' '.(string)$classOption['section_name']) ?></option><?php endforeach; ?></select><?php if (!$activeSchoolYear): ?><small>ابتدا سال تحصیلی فعلی را فعال کنید.</small><?php elseif (!$classOptions): ?><small>برای سال تحصیلی <?= student_escape($activeSchoolYear['name']) ?> هنوز صنفی تعریف نشده است. ابتدا در تنظیمات مدرسه صنف‌ها را ایجاد کنید.</small><?php else: ?><small>فقط صنف‌های فعال سال تحصیلی <?= student_escape($activeSchoolYear['name']) ?> نمایش داده می‌شوند.</small><?php endif; ?></div>
</div></div>
<?php if ($canManageFees): ?><div class="student-form-section"><h3>تخفیف شهریه سال تحصیلی <?= student_escape($activeSchoolYear['name'] ?? '') ?></h3><div class="student-form-fields">
<div class="student-field"><label for="discount_type">نوع تخفیف</label><select id="discount_type" name="discount_type"><option value="none" <?= ($currentDiscount['discount_type'] ?? 'none') === 'none' ? 'selected' : '' ?>>بدون تخفیف</option><option value="percent" <?= ($currentDiscount['discount_type'] ?? '') === 'percent' ? 'selected' : '' ?>>درصدی (%)</option><option value="amount" <?= ($currentDiscount['discount_type'] ?? '') === 'amount' ? 'selected' : '' ?>>مبلغ ثابت</option></select><small>این تخفیف فقط برای سال تحصیلی فعلی ذخیره می‌شود.</small></div>
<div class="student-field"><label for="discount_value">مقدار تخفیف</label><input id="discount_value" name="discount_value" type="number" min="0" step="0.01" value="<?= student_escape($currentDiscount['discount_value'] ?? '') ?>" placeholder="مثلاً ۱۰ یا ۵۰۰"><small>برای تخفیف درصدی از ۱ تا ۱۰۰ وارد کنید؛ برای مبلغ ثابت، مبلغ تخفیف را وارد کنید.</small></div>
</div></div><?php endif; ?>

<?php if ($canManageFees && !$editing): ?><div class="student-form-section"><h3>فیس و پرداخت دوره</h3><div class="student-form-fields">
<div class="student-field"><label for="fee_rule_id">دورهٔ فیس *</label><select id="fee_rule_id" name="fee_rule_id" required><option value="">انتخاب دوره</option><?php foreach ($feeRulesForForm as $feeRule): ?><option value="<?= (int)$feeRule['id'] ?>" data-grade-from="<?= (int)$feeRule['grade_from'] ?>" data-grade-to="<?= (int)$feeRule['grade_to'] ?>" data-amount="<?= student_escape($feeRule['amount']) ?>"><?= student_escape($feeRule['period_name'].' — صنف '.$feeRule['grade_from'].' تا '.$feeRule['grade_to'].' — '.number_format((float)$feeRule['amount'],2)) ?></option><?php endforeach; ?></select><small>فیس هر دوره از تنظیمات مالی گرفته می‌شود.</small></div>
<div class="student-field"><label for="initial_payment">پرداخت فعلی (اختیاری)</label><input id="initial_payment" name="initial_payment" type="number" min="0" step="0.01" value="0" <?= $editing ? 'disabled' : '' ?>><small>۰ یعنی فعلاً پرداخت نشده؛ مبلغ باقی‌مانده بعداً از بخش دریافت فیس قابل پرداخت است.</small></div>
<div class="student-field"><label for="initial_payment_method">روش پرداخت</label><select id="initial_payment_method" name="initial_payment_method" <?= $editing ? 'disabled' : '' ?>><option value="cash">نقدی</option><option value="bank">بانکی</option><option value="other">دیگر</option></select></div>
<div class="student-field"><label>مبلغ پس از تخفیف</label><div class="student-number-note"><span id="fee-net-preview">—</span><small id="fee-preview-note">ابتدا صنف و دوره را انتخاب کنید.</small></div></div>
</div></div><?php endif; ?>
<div class="student-form-section"><h3>اطلاعات تماس و سرپرست</h3><div class="student-form-fields">
<div class="student-field"><label for="phone">شماره تماس دانش‌آموز</label><input id="phone" name="phone" maxlength="40" value="<?= student_escape($editing['phone'] ?? '') ?>"></div>
<div class="student-field"><label for="guardian_name">نام والد / سرپرست</label><input id="guardian_name" name="guardian_name" maxlength="200" value="<?= student_escape($editing['guardian_name'] ?? '') ?>"></div>
<div class="student-field"><label for="guardian_phone">شماره تماس سرپرست</label><input id="guardian_phone" name="guardian_phone" maxlength="40" value="<?= student_escape($editing['guardian_phone'] ?? '') ?>"></div>
<div class="student-field student-field-wide"><label for="address">نشانی</label><textarea id="address" name="address" maxlength="500"><?= student_escape($editing['address'] ?? '') ?></textarea></div>
</div></div>
<div class="form-actions"><button class="button button-primary" type="submit"><?= $editing ? 'ذخیره تغییرات' : 'ثبت دانش‌آموز' ?></button></div>
</form></section>
<?php endif; ?>
<?php if (!$showForm): ?><section class="panel"><h2>فهرست دانش‌آموزان</h2>
<form class="students-search students-filters" method="get" action="students.php">
<label>جست‌وجو<input type="search" name="q" value="<?= student_escape($search) ?>" placeholder="نام، شماره یا سرپرست"></label>
<label>صنف / کلاس<select name="class_name"><option value="">همه صنف‌ها</option><?php foreach ($classOptions as $classOption): $classOptionName = (string)$classOption['grade'].' '.(string)$classOption['section_name']; ?><option value="<?= student_escape($classOptionName) ?>" <?= $filterClass === $classOptionName ? 'selected' : '' ?>><?= student_escape($classOptionName) ?></option><?php endforeach; ?></select></label>
<label>جنسیت<select name="gender"><option value="">همه</option><option value="female" <?= $filterGender==='female'?'selected':'' ?>>دختر</option><option value="male" <?= $filterGender==='male'?'selected':'' ?>>پسر</option><option value="unspecified" <?= $filterGender==='unspecified'?'selected':'' ?>>ثبت نشده</option></select></label>
<label>سن<select name="age"><option value="">همه سنین</option><?php for($a=4;$a<=30;$a++): ?><option value="<?= $a ?>" <?= $filterAge===(string)$a?'selected':'' ?>><?= $a ?> سال</option><?php endfor; ?></select></label>
<div class="filter-actions"><button class="button button-primary" type="submit">اعمال فیلتر</button><a class="button button-secondary" href="students.php">پاک کردن فیلتر</a></div>
</form>
<div class="students-table-wrap"><table class="students-table"><thead><tr><th>شماره دائمی شاگرد</th><th>شماره کتاب اساس</th><th>نام کامل</th><th>نام پدر</th><th>نام پدرکلان</th><th>صنف / کلاس</th><th>سرپرست</th><th>وضعیت</th><?php if ($canEdit): ?><th>عملیات</th><?php endif; ?></tr></thead><tbody>
<?php if (!$students): ?><tr><td colspan="<?= $canEdit ? 9 : 8 ?>">هنوز دانش‌آموزی ثبت نشده است.</td></tr><?php endif; ?>
<?php foreach ($students as $student): ?><tr class="<?= (int)$student['is_active']===1 ? '' : 'student-inactive' ?>"><td dir="ltr"><?= student_escape($student['student_number']) ?></td><td><?= student_escape($student['register_book_number'] ?: '—') ?></td><td><?= student_escape($student['first_name'].' '.$student['last_name']) ?></td><td><?= student_escape($student['father_name'] ?: '—') ?></td><td><?= student_escape($student['grandfather_name'] ?: '—') ?></td><td><?= student_escape($student['class_name'] ?: '—') ?></td><td><?= student_escape($student['guardian_name'] ?: '—') ?></td><td><?= (int)$student['is_active']===1 ? 'فعال' : 'غیرفعال' ?></td><?php if ($canEdit): ?><td><div class="student-actions"><?php if (($mode = (string)($_GET['mode'] ?? '')) !== 'delete'): ?><a class="button button-secondary" href="edit_student.php?edit=<?= (int)$student['id'] ?>">ویرایش</a><?php endif; ?><?php if ($canManageStudentRecords): ?><form method="post" action="students.php" onsubmit="return confirm('آیا مطمئن هستید که می‌خواهید پرونده این دانش‌آموز را برای همیشه حذف کنید؟ این کار قابل بازگشت نیست.');"><input type="hidden" name="csrf_token" value="<?= student_escape(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>"><button class="button button-danger" type="submit">حذف</button></form><?php endif; ?><?php if ($canManageStudentRecords && ($_GET['mode'] ?? '') !== 'edit'): ?><form method="post" action="students.php"><input type="hidden" name="csrf_token" value="<?= student_escape(csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>"><button class="button button-secondary" type="submit"><?= (int)$student['is_active']===1 ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?></button></form><?php endif; ?></div></td><?php endif; ?></tr><?php endforeach; ?>
</tbody></table></div>
<p class="help-text">حداکثر ۵۰۰ پرونده در این فهرست نمایش داده می‌شود. از فیلترها برای پیدا کردن رکوردها استفاده کنید.</p>
</section><?php endif; ?></div>
</main></div>

<link rel="stylesheet" href="assets/css/persian-calendar.css?v=20261012-calendar-spacing">
<script src="assets/js/persian-calendar.js"></script>
<script>
(function(){
 const classSelect=document.getElementById('class_section_id');
 const feeSelect=document.getElementById('fee_rule_id');
 const discountType=document.getElementById('discount_type');
 const discountValue=document.getElementById('discount_value');
 const paymentInput=document.getElementById('initial_payment');
 const preview=document.getElementById('fee-net-preview');
 const note=document.getElementById('fee-preview-note');
 if(!classSelect||!feeSelect||!preview)return;
 function updateFeeOptions(){
   const classOption=classSelect.options[classSelect.selectedIndex];
   const grade=classOption?Number(classOption.dataset.grade||0):0;
   let selectedStillValid=false;
   Array.from(feeSelect.options).forEach(function(option){
     if(!option.value)return;
     const from=Number(option.dataset.gradeFrom||0),to=Number(option.dataset.gradeTo||0);
     const allowed=grade>=from&&grade<=to;
     option.hidden=!allowed; option.disabled=!allowed;
     if(allowed&&option.selected)selectedStillValid=true;
   });
   if(!selectedStillValid)feeSelect.value='';
   updatePreview();
 }
 function updatePreview(){
   const option=feeSelect.options[feeSelect.selectedIndex];
   if(!option||!option.value){preview.textContent='—';note.textContent='ابتدا صنف و دوره را انتخاب کنید.';if(paymentInput)paymentInput.removeAttribute('max');return;}
   const base=Number(option.dataset.amount||0);
   const type=discountType?discountType.value:'none';
   const raw=discountValue?Number(discountValue.value||0):0;
   const discount=type==='percent'?base*Math.min(100,Math.max(0,raw))/100:(type==='amount'?Math.min(base,Math.max(0,raw)):0);
   const net=Math.max(0,base-discount);
   preview.textContent=net.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
   note.textContent='فیس اصلی: '+base.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})+'؛ تخفیف: '+discount.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
   if(paymentInput&&!paymentInput.disabled)paymentInput.max=net.toFixed(2);
 }
 classSelect.addEventListener('change',updateFeeOptions);feeSelect.addEventListener('change',updatePreview);
 if(discountType)discountType.addEventListener('change',updatePreview);if(discountValue)discountValue.addEventListener('input',updatePreview);
 updateFeeOptions();
})();
</script>
    <script src="assets/js/form-validation.js" defer></script></body></html>
