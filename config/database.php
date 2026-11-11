<?php
declare(strict_types=1);
define('DB_HOST', 'localhost');
define('DB_NAME', 'hospital_crm');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
$docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$relative = ($docRoot && str_starts_with(str_replace('\\','/',$projectRoot), str_replace('\\','/',$docRoot)))
    ? substr(str_replace('\\','/',$projectRoot), strlen(str_replace('\\','/',$docRoot))) : '/hospital-crm';
define('BASE_URL', rtrim($relative, '/'));
function getDBConnection(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log('CRM database connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Database connection failed. Start MySQL in XAMPP and import schema.sql into hospital_crm.');
    }
}
