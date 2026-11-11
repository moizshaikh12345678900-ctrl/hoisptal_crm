<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_role(['super_admin']);

try {
    db()->query('SELECT 1');
    $message = 'Database connection successful.';
    $ok = true;
} catch (Throwable $ex) {
    error_log('Database diagnostic failed: ' . $ex->getMessage());
    $message = 'Database connection failed. Check config/database.php and the MySQL service.';
    $ok = false;
}
$pageTitle = 'Database diagnostics';
require __DIR__ . '/header.php';
?>
<div class="alert alert-<?=$ok ? 'success' : 'danger'?>"><?=e($message)?></div>
<?php require __DIR__ . '/footer.php'; ?>
