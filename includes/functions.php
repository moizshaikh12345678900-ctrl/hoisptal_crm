<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(['httponly'=>true, 'secure'=>$secure, 'samesite'=>'Lax']);
    session_start();
}
require_once __DIR__ . '/../config/database.php';
function db(): PDO { return getDBConnection(); }
function url(string $path=''): string { return BASE_URL . ($path === '' ? '/' : '/' . ltrim($path, '/')); }
function e(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    return ['id'=>(int)$_SESSION['user_id'], 'name'=>$_SESSION['user_name'] ?? 'User', 'email'=>$_SESSION['user_email'] ?? '', 'role'=>$_SESSION['user_role'] ?? ''];
}
function logged_in(): bool { return current_user() !== null; }
function redirect(string $path): never { header('Location: ' . url($path)); exit; }
function flash(string $type, string $message): void { $_SESSION['flash'] = ['type'=>$type, 'message'=>$message]; }
function take_flash(): ?array { $f=$_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function csrf_token(): string { if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32)); return $_SESSION['csrf_token']; }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="'.e(csrf_token()).'">'; }
function verify_csrf(): void { if ($_SERVER['REQUEST_METHOD']==='POST' && !hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) { http_response_code(419); exit('Invalid or expired form token. Go back and try again.'); } }
function require_login(): void { if (!logged_in()) { flash('warning','Please sign in to continue.'); redirect('login.php'); } }
function require_role(array $roles): void { require_login(); if (!in_array(current_user()['role'], $roles, true)) { http_response_code(403); render_forbidden(); exit; } }
function render_forbidden(): void { echo '<div style="font:16px system-ui;padding:48px"><h1>Access denied</h1><p>Your account does not have permission to access this page.</p><a href="'.e(url('index.php')).'">Return home</a></div>'; }
function audit(?int $userId, string $action, string $entity='system', ?string $entityId=null, ?string $metadata=null): void {
    try { $s=db()->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,metadata,ip_address) VALUES(?,?,?,?,?,?)'); $s->execute([$userId,$action,$entity,$entityId,$metadata,substr($_SERVER['REMOTE_ADDR'] ?? '',0,45)]); } catch(Throwable $e) { error_log('Audit log error: '.$e->getMessage()); }
}
function status_badge(string $status): string {
    $map=['requested'=>'warning','confirmed'=>'primary','checked_in'=>'info','in_consultation'=>'purple','completed'=>'success','cancelled'=>'secondary','no_show'=>'danger','paid'=>'success','partial'=>'warning','unpaid'=>'danger','active'=>'success','inactive'=>'secondary','suspended'=>'danger'];
    $color=$map[$status] ?? 'secondary';
    $label = ucwords(str_replace('_', ' ', $status));
    if($color==='purple') return '<span class="badge text-bg-primary" data-i18n="'.e($status).'">'.e($label).'</span>';
    return '<span class="badge text-bg-'.$color.'" data-i18n="'.e($status).'">'.e($label).'</span>';
}
function money(mixed $amount): string { $symbol='$'; try { $q=db()->query("SELECT setting_value FROM settings WHERE setting_key='currency_symbol'"); $v=$q->fetchColumn(); if($v!==false && $v!=='') $symbol=$v; } catch(Throwable $e) {} return e($symbol).' '.number_format((float)$amount,2); }
function role_home(string $role): string { return match($role) { 'super_admin'=>'super-admin/dashboard.php','admin'=>'admin/dashboard.php','doctor'=>'doctor/dashboard.php','staff'=>'staff/dashboard.php','patient'=>'patient/dashboard.php', default=>'index.php' }; }
function patient_for_user(int $uid): ?array { $s=db()->prepare('SELECT * FROM patients WHERE user_id=? LIMIT 1'); $s->execute([$uid]); $p=$s->fetch(); return $p ?: null; }
function doctor_for_user(int $uid): ?array { $s=db()->prepare('SELECT * FROM doctors WHERE user_id=? LIMIT 1'); $s->execute([$uid]); $d=$s->fetch(); return $d ?: null; }
function setting(string $key, string $default=''): string { try {$s=db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$s->execute([$key]);$v=$s->fetchColumn();return $v===false?$default:(string)$v;}catch(Throwable $e){return $default;} }

/**
 * Create (or return) the consultation invoice for an appointment.
 * Call inside an existing transaction where possible. The appointment row
 * is locked so two requests cannot create two invoices concurrently.
 */
function create_invoice_for_appointment(PDO $pdo, int $appointmentId): array {
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $q = $pdo->prepare("
            SELECT a.id, a.patient_id, a.doctor_id, a.appointment_date,
                   d.fee, u.name AS doctor_name
            FROM appointments a
            JOIN doctors d ON d.id = a.doctor_id
            JOIN users u ON u.id = d.user_id
            WHERE a.id = ?
            FOR UPDATE
        ");
        $q->execute([$appointmentId]);
        $appointment = $q->fetch();

        if (!$appointment) {
            throw new RuntimeException('Appointment not found for invoice generation.');
        }

        $q = $pdo->prepare('SELECT * FROM invoices WHERE appointment_id = ? LIMIT 1 FOR UPDATE');
        $q->execute([$appointmentId]);
        $existing = $q->fetch();

        if ($existing) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return ['created' => false, 'invoice' => $existing];
        }

        $fee = max(0, round((float)$appointment['fee'], 2));
        $year = date('Y');
        $invoiceNumber = 'INV-' . $year . '-' . str_pad((string)$appointmentId, 6, '0', STR_PAD_LEFT);

        // Protect against a manually-created invoice having the same number.
        $numberCheck = $pdo->prepare('SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1');
        $numberCheck->execute([$invoiceNumber]);
        if ($numberCheck->fetchColumn()) {
            $invoiceNumber .= '-' . strtoupper(bin2hex(random_bytes(2)));
        }

        $initialStatus = $fee <= 0 ? 'paid' : 'unpaid';
        $dueDate = date('Y-m-d', strtotime('+7 days'));

        $ins = $pdo->prepare("
            INSERT INTO invoices
                (invoice_number, patient_id, appointment_id, total, amount_paid, status, due_date)
            VALUES (?, ?, ?, ?, 0, ?, ?)
        ");
        $ins->execute([
            $invoiceNumber,
            (int)$appointment['patient_id'],
            $appointmentId,
            $fee,
            $initialStatus,
            $dueDate
        ]);

        $invoiceId = (int)$pdo->lastInsertId();
        $invoice = [
            'id' => $invoiceId,
            'invoice_number' => $invoiceNumber,
            'patient_id' => (int)$appointment['patient_id'],
            'appointment_id' => $appointmentId,
            'total' => number_format($fee, 2, '.', ''),
            'amount_paid' => '0.00',
            'status' => $initialStatus,
            'due_date' => $dueDate
        ];

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return ['created' => true, 'invoice' => $invoice];
    } catch (Throwable $ex) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

function post_string(string $key, int $max=5000): string { return trim(mb_substr((string)($_POST[$key] ?? ''),0,$max)); }
