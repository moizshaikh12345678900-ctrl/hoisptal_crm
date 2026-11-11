<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_role(['patient']);

$patient = patient_for_user((int)current_user()['id']);
$rows = [];
if ($patient) {
    $stmt = db()->prepare('SELECT * FROM invoices WHERE patient_id = ? ORDER BY created_at DESC');
    $stmt->execute([(int)$patient['id']]);
    $rows = $stmt->fetchAll();
}
$pageTitle = 'My invoices';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/page.php';
page_heading('Personal account', 'My invoices', 'View your invoices, payment history and outstanding balances.');
?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Invoice</th><th>Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $invoice): ?>
                <tr>
                    <td><strong><?=e($invoice['invoice_number'])?></strong></td>
                    <td><?=e(substr($invoice['created_at'],0,10))?></td>
                    <td><?=money($invoice['total'])?></td>
                    <td><?=money($invoice['amount_paid'])?></td>
                    <td class="fw-semibold"><?=money(max(0,(float)$invoice['total']-(float)$invoice['amount_paid']))?></td>
                    <td><?=status_badge($invoice['status'])?></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="<?=e(url('billing/view.php?id='.(int)$invoice['id']))?>">View / Print</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><?=empty_row(7,'No invoices found.')?><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
