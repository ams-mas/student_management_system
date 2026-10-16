<?php
declare(strict_types=1);

/*
 * First-run installer for Student ID Generator.
 * Open /install.php on a fresh local installation, create the database and
 * first super administrator, then remove this file or leave the DB lock in place.
 */
session_start();

function install_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function install_db_settings(): array
{
    return [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'name' => getenv('DB_NAME') ?: 'student_id_generator',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') === false ? '' : (string) getenv('DB_PASS'),
    ];
}

function install_pdo(array $settings, bool $selectDatabase = true): PDO
{
    $dsn = 'mysql:host=' . $settings['host'] . ';charset=utf8mb4';
    if ($selectDatabase) {
        $dsn .= ';dbname=' . $settings['name'];
    }
    return new PDO($dsn, $settings['user'], $settings['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** Run ordinary SQL files containing statements separated by semicolons on their own lines. */
function install_run_sql(PDO $pdo, string $sql): void
{
    // Remove full-line SQL comments. The project migrations do not use stored procedures.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    foreach ($statements as $statementSql) {
        $statementSql = trim($statementSql);
        if ($statementSql === '') {
            continue;
        }
        // The installer has already selected the target database.
        if (preg_match('/^USE\s+[`\w]+\s*$/i', $statementSql)) {
            continue;
        }
        // schema.sql may include CREATE DATABASE; database creation is handled safely above.
        if (preg_match('/^CREATE\s+DATABASE\b/i', $statementSql)) {
            continue;
        }
        $statement = $pdo->query($statementSql);
        if ($statement instanceof PDOStatement) {
            if ($statement->columnCount() > 0) {
                $statement->fetchAll(PDO::FETCH_ASSOC);
            }
            $statement->closeCursor();
        }
    }
}

function install_migrations(PDO $pdo): array
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        migration_name VARCHAR(255) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_schema_migration_name (migration_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $applied = $pdo->query('SELECT migration_name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $directories = [__DIR__ . '/database/migrations', __DIR__ . '/sql_updates'];
    $files = [];
    foreach ($directories as $directory) {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $file) {
            $files[$file] = true;
        }
    }
    $paths = array_keys($files);
    usort($paths, static fn(string $a, string $b): int => strcmp(basename($a), basename($b)));

    $completed = [];
    foreach ($paths as $path) {
        $name = basename($path);
        if (in_array($name, $applied, true)) {
            continue;
        }
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Could not read SQL migration: ' . $name);
        }
        install_run_sql($pdo, $sql);
        $track = $pdo->prepare('INSERT INTO schema_migrations (migration_name) VALUES (:name)');
        $track->execute(['name' => $name]);
        $completed[] = $name;
    }
    return $completed;
}

$settings = install_db_settings();
$message = '';
$messageType = 'error';
$completedMigrations = [];
$installed = false;
$databaseExists = false;
$existingTableCounts = [];
$existingTables = [];

try {
    $server = install_pdo($settings, false);
    $dbName = str_replace('`', '``', $settings['name']);
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = install_pdo($settings, true);
    $databaseExists = true;

    $tableRows = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    foreach ($tableRows as $row) {
        $tableName = (string) $row[0];
        $existingTables[] = $tableName;
        if ($tableName === 'schema_migrations') {
            continue;
        }
        $quotedTable = '`' . str_replace('`', '``', $tableName) . '`';
        try {
            $existingTableCounts[$tableName] = (int) $pdo->query("SELECT COUNT(*) FROM {$quotedTable}")->fetchColumn();
        } catch (Throwable $countException) {
            $existingTableCounts[$tableName] = null;
        }
    }
} catch (Throwable $exception) {
    $message = 'اتصال به MySQL برقرار نشد. مطمئن شوید MySQL فعال است و تنظیمات DB_HOST، DB_NAME، DB_USER و DB_PASS درست هستند. جزئیات: ' . $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !isset($_SESSION['install_csrf']) || !hash_equals($_SESSION['install_csrf'], $token)) {
        http_response_code(400);
        $message = 'درخواست معتبر نیست. صفحه را تازه‌سازی کنید.';
    } elseif (($_POST['confirm_reset'] ?? '') !== 'yes') {
        $message = 'برای ادامه، تأیید حذف اطلاعات موجود را علامت بزنید.';
    } else {
        try {
            $server = install_pdo($settings, false);
            $dbName = str_replace('`', '``', $settings['name']);
            $server->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo = install_pdo($settings, true);

            // Ensure the latest schema and any unapplied migrations are present before clearing data.
            $schemaPath = __DIR__ . '/database/schema.sql';
            $schema = file_get_contents($schemaPath);
            if ($schema === false) {
                throw new RuntimeException('database/schema.sql is missing or unreadable.');
            }
            install_run_sql($pdo, $schema);
            $completedMigrations = install_migrations($pdo);

            // Clear all application-table data. Keep migration history so migrations are not rerun.
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                $tableRows = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
                foreach ($tableRows as $row) {
                    $tableName = (string) $row[0];
                    if ($tableName === 'schema_migrations') {
                        continue;
                    }
                    $quotedTable = '`' . str_replace('`', '``', $tableName) . '`';
                    $pdo->exec("TRUNCATE TABLE {$quotedTable}");
                }
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }

            // Store only a secure hash for the default super administrator password.
            $createUser = $pdo->prepare(
                'INSERT INTO users (username, full_name, password_hash, role, is_active)
                 VALUES (:username, :full_name, :password_hash, :role, 1)'
            );
            $createUser->execute([
                'username' => 'admin',
                'full_name' => 'System Administrator',
                'password_hash' => password_hash('admin@12345', PASSWORD_DEFAULT),
                'role' => 'super_admin',
            ]);

            $installed = true;
            $messageType = 'success';
            $message = 'راه‌اندازی موفق شد. اطلاعات جدول‌های سیستم پاک شد و حساب مدیر کل admin ایجاد گردید.';
            unset($_SESSION['install_csrf']);
            $existingTableCounts = [];
            $existingTables = [];
        } catch (Throwable $exception) {
            error_log('Student ID Generator installer error: ' . $exception->getMessage());
            $message = 'راه‌اندازی کامل نشد: ' . $exception->getMessage() . ' — جزئیات در گزارش PHP/Apache ثبت شده است.';
        }
    }
}

if (!isset($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>راه‌اندازی اولیه سیستم مدیریت مکتب</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body{background:#f3f6fb;margin:0;color:#172033;font-family:inherit}
        .setup-shell{max-width:760px;margin:44px auto;padding:0 18px}
        .setup-card{background:#fff;border:1px solid #e4e9f2;border-radius:18px;padding:28px;box-shadow:0 12px 36px rgba(15,23,42,.07);margin-bottom:18px}
        .setup-card h1{margin-top:0;font-size:1.6rem}
        .setup-subtitle{color:#64748b;line-height:1.9}
        .setup-field{display:grid;gap:7px;margin:16px 0}
        .setup-field label{font-weight:600}
        .setup-field input{box-sizing:border-box;width:100%;padding:12px 14px;border:1px solid #cbd5e1;border-radius:10px;font:inherit}
        .setup-field input:focus{outline:2px solid #93c5fd;border-color:#2563eb}
        .setup-button{border:0;border-radius:10px;background:#1d4ed8;color:#fff;padding:12px 18px;font:inherit;font-weight:700;cursor:pointer}
        .setup-alert{padding:14px 16px;border-radius:10px;margin:16px 0;line-height:1.8;overflow-wrap:anywhere}
        .setup-alert.success{background:#dcfce7;color:#166534}
        .setup-alert.error{background:#fee2e2;color:#991b1b}
        .setup-alert.warning{background:#fff7ed;color:#9a3412}
        .setup-note{font-size:.92rem;color:#64748b;line-height:1.9}
        .setup-ltr{direction:ltr;text-align:left}
    </style>
</head>
<body>
<main class="setup-shell">
    <section class="setup-card">
        <p class="eyebrow">راه‌اندازی سیستم</p>
        <h1>راه‌اندازی اولیه سیستم مدیریت مکتب</h1>
        <p class="setup-subtitle">این صفحه ساختار پایگاه داده و به‌روزرسانی‌ها را بررسی می‌کند. با تأیید شما، تمام اطلاعات موجود در جدول‌های سیستم پاک می‌شود و حساب مدیر کل دوباره ساخته می‌شود.</p>
        <?php if ($message !== ''): ?>
            <div class="setup-alert <?= install_escape($messageType) ?>" role="status"><?= install_escape($message) ?></div>
        <?php endif; ?>

        <?php if ($installed): ?>
            <p><strong>اطلاعات قبلی حذف شد و حساب مدیر کل آماده ورود است.</strong></p>
            <p>نام کاربری: <code class="setup-ltr">admin</code></p>
            <p>رمز عبور: <code class="setup-ltr">admin@12345</code></p>
            <p><a class="setup-button" href="index.php">رفتن به صفحه ورود</a></p>
            <p class="setup-note">این رمز پیش‌فرض است. پس از ورود، آن را تغییر دهید و بعد از اطمینان از کارکرد سیستم، فایل install.php را حذف کنید.</p>
        <?php else: ?>
            <?php if ($existingTables): ?>
                <h2>جدول‌های موجود</h2>
                <p class="setup-note">تعداد رکوردهای فعلی (به‌جز جدول پیگیری به‌روزرسانی‌ها):</p>
                <ul>
                    <?php foreach ($existingTableCounts as $tableName => $rowCount): ?>
                        <li><code><?= install_escape($tableName) ?></code>: <?= $rowCount === null ? 'قابل شمارش نیست' : number_format($rowCount) . ' رکورد' ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="setup-note">در پایگاه داده جدول موجودی پیدا نشد؛ جدول‌های سیستم هنگام اجرا ساخته خواهند شد.</p>
            <?php endif; ?>

            <div class="setup-alert warning">
                <strong>هشدار مهم:</strong> با اجرای این عملیات، تمام رکوردهای جدول‌های سیستم، از جمله کاربران و شاگردان، حذف می‌شوند. جدول‌های ساختاری حذف نمی‌شوند؛ فقط اطلاعات پاک می‌شود. این کار قابل برگشت نیست.
            </div>
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= install_escape($_SESSION['install_csrf']) ?>">
                <div class="setup-field">
                    <label>
                        <input type="checkbox" name="confirm_reset" value="yes" required style="width:auto">
                        تأیید می‌کنم که می‌خواهم تمام اطلاعات موجود پاک شود.
                    </label>
                </div>
                <button class="setup-button" type="submit">پاک‌کردن اطلاعات و ساخت حساب مدیر</button>
            </form>
            <p class="setup-note">حساب مدیر کل: نام کاربری <code class="setup-ltr">admin</code>، رمز عبور <code class="setup-ltr">admin@12345</code>. تنظیمات پیش‌فرض XAMPP: پایگاه داده student_id_generator، کاربر MySQL برابر root و رمز خالی.</p>
        <?php endif; ?>

    </section>
</main>
</body>
</html>
