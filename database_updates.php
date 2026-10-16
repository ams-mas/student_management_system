<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_roles(['super_admin']);
require_once __DIR__ . '/config/database.php';

$pdo = database_connection();
$migrationDirectories = [__DIR__ . '/sql_updates', __DIR__ . '/database/migrations'];
$message = '';
$messageType = 'info';
$results = [];

function update_page_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        migration_name VARCHAR(255) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_schema_migration_name (migration_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $appliedNames = $pdo->query('SELECT migration_name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $exception) {
    error_log('Database updater could not initialize migration tracking: ' . $exception->getMessage());
    http_response_code(500);
    exit('ساخت جدول پیگیری به‌روزرسانی‌ها انجام نشد. دسترسی پایگاه داده را بررسی کنید.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('درخواست معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.');
    }

    $requestedMigration = (string) ($_POST['migration'] ?? '');
    $availableFiles = [];
    foreach ($migrationDirectories as $directory) {
        $availableFiles = array_merge($availableFiles, glob($directory . DIRECTORY_SEPARATOR . '*.sql') ?: []);
    }
    $selectedPath = null;
    foreach ($availableFiles as $filePath) {
        if (hash_equals(basename($filePath), $requestedMigration)) {
            $selectedPath = $filePath;
            break;
        }
    }

    if ($selectedPath === null) {
        $message = 'فایل به‌روزرسانی پیدا نشد.';
        $messageType = 'error';
    } elseif (in_array(basename($selectedPath), $appliedNames, true)) {
        $message = 'این فایل قبلاً اجرا شده است.';
        $messageType = 'info';
    } else {
        $sql = file_get_contents($selectedPath);
        if ($sql === false) {
            $message = 'خواندن فایل SQL انجام نشد.';
            $messageType = 'error';
        } else {
            // Supports ordinary SQL statements separated by semicolons, not stored procedures or triggers.
            $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
            try {
                foreach ($statements as $statementSql) {
                    $statementSql = trim($statementSql);
                    if ($statementSql === '') {
                        continue;
                    }
                    // The configured PDO connection already selects the database.
                    if (preg_match('/^USE\s+[`\w]+\s*$/i', $statementSql)) {
                        continue;
                    }
                    // Use query() and fully consume any result set (for example SELECT 1
                    // in idempotent migrations) before executing the next statement.
                    $statement = $pdo->query($statementSql);
                    if ($statement instanceof PDOStatement) {
                        if ($statement->columnCount() > 0) {
                            $statement->fetchAll(PDO::FETCH_ASSOC);
                        }
                        $statement->closeCursor();
                    }
                }
                $track = $pdo->prepare('INSERT INTO schema_migrations (migration_name) VALUES (:name)');
                $track->execute(['name' => basename($selectedPath)]);
                $appliedNames[] = basename($selectedPath);
                $message = 'به‌روزرسانی با موفقیت اجرا شد: ' . basename($selectedPath);
                $messageType = 'success';
            } catch (Throwable $exception) {
                error_log('Database migration failed (' . basename($selectedPath) . '): ' . $exception->getMessage());
                $message = 'اجرای به‌روزرسانی متوقف شد: ' . $exception->getMessage()
                    . ' — ممکن است بعضی جدول‌ها پیش از خطا ساخته شده باشند. خطا را بررسی کنید.';
                $messageType = 'error';
            }
        }
    }
}

$availableFiles = [];
foreach ($migrationDirectories as $directory) {
    $availableFiles = array_merge($availableFiles, glob($directory . DIRECTORY_SEPARATOR . '*.sql') ?: []);
}
usort($availableFiles, static fn(string $a, string $b): int => strcmp(basename($a), basename($b)));
foreach ($availableFiles as $filePath) {
    $name = basename($filePath);
    $results[] = ['name' => $name, 'applied' => in_array($name, $appliedNames, true)];
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>به‌روزرسانی پایگاه داده</title>
    <link rel="stylesheet" href="assets/css/style.css">
<style>
        .migration-wrap{max-width:1000px;margin:32px auto;padding:0 18px}
        .migration-panel{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:24px;margin:18px 0;box-shadow:0 6px 24px rgba(15,23,42,.05)}
        .migration-list{display:grid;gap:12px}
        .migration-row{display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap;border:1px solid #e5e7eb;border-radius:12px;padding:14px}
        .migration-name{direction:ltr;text-align:left;overflow-wrap:anywhere;font-family:monospace}
        .migration-status{font-size:.9rem;color:#475569}
        .migration-alert{padding:14px 16px;border-radius:10px;margin:16px 0;overflow-wrap:anywhere}
        .migration-alert.success{background:#dcfce7;color:#166534}
        .migration-alert.error{background:#fee2e2;color:#991b1b}
        .migration-alert.info{background:#e0f2fe;color:#075985}
        .migration-warning{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;padding:14px;border-radius:10px;line-height:1.9}
        .migration-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
        .migration-actions form{margin:0}
        .migration-back{display:inline-block;margin-bottom:12px}
    </style>
</head>
<body class="profile-page">
<div class="admin-layout">
    <?php require __DIR__ . '/includes/sidebar.php'; ?>
    <main class="admin-main">
            <?php require __DIR__ . '/includes/topbar.php'; ?>
      <div class="migration-wrap">
    <a class="migration-back" href="dashboard.php">بازگشت به داشبورد</a>
    <section class="migration-panel">
        <p class="eyebrow">مدیریت سیستم</p>
        <h1>به‌روزرسانی پایگاه داده</h1>
        <p><a href="download_database_backup.php" class="button button-primary">دانلود پشتیبان کامل پایگاه داده (SQL)</a></p>
        <p>هر تغییر جدید در جدول‌ها باید به‌صورت یک فایل SQL جدید در پوشه sql_updates/ اضافه شود. فایل‌های قدیمی database/migrations/ نیز همچنان نمایش داده می‌شوند. این صفحه فایل‌های اجرا‌نشده را نمایش می‌دهد.</p>
        <div class="migration-warning">پیش از اجرای هر تغییر، از پایگاه داده نسخه پشتیبان بگیرید. فایل‌های SQL می‌توانند جدول یا ستون بسازند، تغییر دهند یا حذف کنند. دستورهایی مانند DROP TABLE و DROP COLUMN داده‌ها را برای همیشه حذف می‌کنند. اگر خطا رخ دهد، ممکن است بخشی از تغییرات اعمال شده باشد.</div>
        <?php if ($message !== ''): ?>
            <div class="migration-alert <?= update_page_escape($messageType) ?>" role="status"><?= update_page_escape($message) ?></div>
        <?php endif; ?>
    </section>
    <section class="migration-panel">
        <h2>فایل‌های SQL</h2>
        <?php if ($results === []): ?>
            <p>هیچ فایل SQL در پوشه مهاجرت‌ها پیدا نشد.</p>
        <?php else: ?>
            <div class="migration-list">
                <?php foreach ($results as $item): ?>
                    <div class="migration-row">
                        <div>
                            <div class="migration-name"><?= update_page_escape($item['name']) ?></div>
                            <div class="migration-status"><?= $item['applied'] ? 'قبلاً اجرا شده' : 'در انتظار اجرا' ?></div>
                        </div>
                        <div class="migration-actions">
                            <?php if ($item['applied']): ?>
                                <span class="button button-secondary" aria-disabled="true">اجرا شده</span>
                            <?php else: ?>
                                <form method="post" onsubmit="return confirm('پیش از اجرا از پایگاه داده نسخه پشتیبان گرفته‌اید؟ آیا این فایل اجرا شود؟');">
                                    <input type="hidden" name="csrf_token" value="<?= update_page_escape(csrf_token()) ?>">
                                    <input type="hidden" name="migration" value="<?= update_page_escape($item['name']) ?>">
                                    <button type="submit" class="button button-primary">اجرای به‌روزرسانی</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <p class="migration-status">دسترسی این صفحه فقط برای سوپر ادمین و ادمین فعال است.</p>
</div>
    </main>
</div>
    <script src="assets/js/sidebar-clock.js" defer></script>
    <script src="assets/js/sidebar-submenus.js" defer></script>
</body>
</html>
