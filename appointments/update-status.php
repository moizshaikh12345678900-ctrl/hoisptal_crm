<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_role(['super_admin', 'admin', 'staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

verify_csrf();

$db = db();
$user = current_user();
$id = (int)($_POST['id'] ?? 0);
$status = post_string('status', 30);

$allowed = ['requested', 'confirmed', 'checked_in', 'in_consultation', 'completed', 'cancelled', 'no_show'];

if ($id < 1 || !in_array($status, $allowed, true)) {
    flash('danger', 'Invalid appointment update.');
    redirect('appointments/index.php');
}

try {
    $db->beginTransaction();

    $lock = $db->prepare('SELECT id, status FROM appointments WHERE id = ? FOR UPDATE');
    $lock->execute([$id]);
    $appointment = $lock->fetch();

    if (!$appointment) {
        throw new RuntimeException('Appointment not found.');
    }

    $update = $db->prepare('UPDATE appointments SET status = ? WHERE id = ?');
    $update->execute([$status, $id]);

    $invoiceResult = null;
    if ($status === 'completed') {
        $invoiceResult = create_invoice_for_appointment($db, $id);
    }

    $db->commit();

    audit((int)$user['id'], 'APPOINTMENT_STATUS_CHANGED', 'appointments', (string)$id, 'Status: ' . $status);

    if ($invoiceResult && $invoiceResult['created']) {
        audit((int)$user['id'], 'INVOICE_CREATED', 'invoices', (string)$invoiceResult['invoice']['id'],
            'Invoice: ' . $invoiceResult['invoice']['invoice_number']);
        flash('success', 'Appointment completed. Invoice ' . $invoiceResult['invoice']['invoice_number'] . ' generated successfully.');
    } elseif ($status === 'completed') {
        flash('success', 'Appointment completed. Existing invoice was kept; no duplicate was created.');
    } else {
        flash('success', 'Appointment status updated.');
    }
} catch (Throwable $ex) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Appointment status update error: ' . $ex->getMessage());
    flash('danger', 'Unable to update appointment: ' . $ex->getMessage());
}

redirect('appointments/index.php');
