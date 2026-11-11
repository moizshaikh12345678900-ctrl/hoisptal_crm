<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_role(['doctor', 'super_admin']);

$user = current_user();
$db = db();
$sql = "SELECT i.*, p.patient_number,
               COALESCE(NULLIF(p.full_name,''), pu.name, 'Patient') AS patient_name
        FROM invoices i
        JOIN patients p ON p.id = i.patient_id
        LEFT JOIN users pu ON pu.id = p.user_id
        LEFT JOIN appointments a ON a.id = i.appointment_id";
$params = [];
if ($user['role'] === 'doctor') {
    $doctor = doctor_for_user((int)$user['id']);
    if (!$doctor) {
        http_response_code(403);
        exit('Doctor profile is not linked to this account.');
    }
    $sql .= ' WHERE a.doctor_id = ?';
    $params[] = (int)$doctor['id'];
}
$sql .= ' ORDER BY i.created_at DESC LIMIT 300';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'My invoices';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/page.php';
page_heading('Finance', 'My invoices', 'Invoices generated from your completed consultations.');
?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h2 class="section-title mb-0">Consultation invoice register</h2>
        <span class="small-muted"><?=count($rows)?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Invoice</th><th>Patient</th><th>Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><strong><?=e($row['invoice_number'])?></strong></td>
                    <td><?=e($row['patient_name'])?><div class="small-muted"><?=e($row['patient_number'])?></div></td>
                    <td><?=e(substr($row['created_at'],0,10))?></td>
                    <td><?=money($row['total'])?></td>
                    <td><?=money($row['amount_paid'])?></td>
                    <td class="fw-semibold"><?=money(max(0,(float)$row['total']-(float)$row['amount_paid']))?></td>
                    <td><?=status_badge($row['status'])?></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="<?=e(url('billing/view.php?id='.(int)$row['id']))?>">View / Print</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><?=empty_row(8,'No consultation invoices found.')?><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
