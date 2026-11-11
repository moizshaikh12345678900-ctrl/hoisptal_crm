<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_role(['super_admin', 'admin', 'staff', 'patient']);
verify_csrf();

$db = db();
$user = current_user();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_string('action', 20);

    try {
        if ($action === 'invoice' && in_array($user['role'], ['super_admin', 'admin'], true)) {
            $patientId = (int)($_POST['patient_id'] ?? 0);
            $amountRaw = (string)($_POST['total'] ?? '');
            $amount = is_numeric($amountRaw) ? round((float)$amountRaw, 2) : 0;
            $dueDate = post_string('due_date', 10);
            if ($patientId < 1 || $amount <= 0 || ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate))) {
                throw new RuntimeException('Select a patient and enter a valid invoice amount and due date.');
            }

            $check = $db->prepare('SELECT id FROM patients WHERE id = ?');
            $check->execute([$patientId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('Selected patient was not found.');
            }

            $invoiceNumber = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $stmt = $db->prepare("
                INSERT INTO invoices (invoice_number, patient_id, total, amount_paid, status, due_date)
                VALUES (?, ?, ?, 0, 'unpaid', ?)
            ");
            $stmt->execute([$invoiceNumber, $patientId, $amount, $dueDate ?: null]);
            $invoiceId = (int)$db->lastInsertId();
            audit((int)$user['id'], 'INVOICE_CREATED', 'invoices', (string)$invoiceId, 'Manual invoice ' . $invoiceNumber . '; total ' . $amount);
            flash('success', 'Invoice created: ' . $invoiceNumber);
            redirect('billing/view.php?id=' . $invoiceId);
        }

        if ($action === 'payment' && in_array($user['role'], ['super_admin', 'admin', 'staff'], true)) {
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $amountRaw = (string)($_POST['amount'] ?? '');
            $amount = is_numeric($amountRaw) ? round((float)$amountRaw, 2) : 0;
            $method = post_string('method', 30);
            $reference = post_string('reference', 100);
            $allowedMethods = ['cash', 'credit_card', 'bank_transfer', 'insurance'];

            if ($invoiceId < 1 || $amount <= 0 || !in_array($method, $allowedMethods, true)) {
                throw new RuntimeException('Enter a valid payment amount and method.');
            }

            $db->beginTransaction();
            $lock = $db->prepare('SELECT * FROM invoices WHERE id = ? FOR UPDATE');
            $lock->execute([$invoiceId]);
            $invoice = $lock->fetch();

            if (!$invoice) {
                throw new RuntimeException('Invoice not found.');
            }

            $balance = round((float)$invoice['total'] - (float)$invoice['amount_paid'], 2);
            if ($amount > $balance + 0.0001) {
                throw new RuntimeException('Payment cannot exceed the outstanding balance of ' . number_format(max(0, $balance), 2) . '.');
            }

            $payment = $db->prepare('
                INSERT INTO payments (invoice_id, amount, method, reference, received_by)
                VALUES (?, ?, ?, ?, ?)
            ');
            $payment->execute([$invoiceId, $amount, $method, $reference ?: null, $user['name']]);

            $newPaid = round((float)$invoice['amount_paid'] + $amount, 2);
            $newStatus = $newPaid + 0.0001 >= (float)$invoice['total'] ? 'paid' : 'partial';
            $db->prepare('UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ?')
                ->execute([$newPaid, $newStatus, $invoiceId]);
            $paymentId = (int)$db->lastInsertId();
            $db->commit();

            audit((int)$user['id'], 'PAYMENT_RECEIVED', 'payments', (string)$paymentId,
                'Invoice ' . $invoice['invoice_number'] . '; amount ' . $amount . '; method ' . $method);
            flash('success', 'Payment recorded successfully.');
            redirect('billing/view.php?id=' . $invoiceId);
        }

        throw new RuntimeException('You do not have permission to perform this action.');
    } catch (Throwable $ex) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $ex->getMessage();
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');
$from = (string)($_GET['from'] ?? '');
$to = (string)($_GET['to'] ?? '');

$sql = "SELECT i.*, COALESCE(NULLIF(p.full_name,''), u.name, 'Patient') AS patient_name,
               p.patient_number
        FROM invoices i
        JOIN patients p ON p.id = i.patient_id
        LEFT JOIN users u ON u.id = p.user_id";
$where = [];
$params = [];

if ($user['role'] === 'patient') {
    $patient = patient_for_user((int)$user['id']);
    $where[] = 'i.patient_id = ?';
    $params[] = (int)($patient['id'] ?? 0);
}
if ($search !== '') {
    $where[] = '(i.invoice_number LIKE ? OR p.patient_number LIKE ? OR p.full_name LIKE ? OR u.name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($statusFilter, ['paid', 'partial', 'unpaid'], true)) {
    $where[] = 'i.status = ?';
    $params[] = $statusFilter;
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'DATE(i.created_at) >= ?';
    $params[] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 'DATE(i.created_at) <= ?';
    $params[] = $to;
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY i.created_at DESC LIMIT 500';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$patients = [];
$outstanding = [];
if (in_array($user['role'], ['super_admin', 'admin'], true)) {
    $patients = $db->query("
        SELECT p.id, COALESCE(NULLIF(p.full_name,''), u.name, 'Patient') AS name, p.patient_number
        FROM patients p LEFT JOIN users u ON u.id = p.user_id ORDER BY name
    ")->fetchAll();
}
if (in_array($user['role'], ['super_admin', 'admin', 'staff'], true)) {
    $outstanding = $db->query("
        SELECT i.id, i.invoice_number, i.total, i.amount_paid,
               COALESCE(NULLIF(p.full_name,''), u.name, 'Patient') AS patient_name
        FROM invoices i JOIN patients p ON p.id = i.patient_id
        LEFT JOIN users u ON u.id = p.user_id
        WHERE i.total > i.amount_paid ORDER BY i.created_at ASC LIMIT 300
    ")->fetchAll();
}

$totalOutstanding = array_sum(array_map(fn($i) => max(0, (float)$i['total'] - (float)$i['amount_paid']), $invoices));
$pageTitle = 'Billing & payments';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/page.php';
page_heading('Finance', 'Billing & payments', 'Manage invoices, payments, balances and printable receipts.');
?>
<?php if ($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4"><?=stat_card('Invoices', (string)count($invoices), 'receipt', 'Matching records')?></div>
    <div class="col-md-4"><?=stat_card('Outstanding', money($totalOutstanding), 'cash-coin', 'Remaining balance in this view')?></div>
    <div class="col-md-4"><?=stat_card('Paid invoices', (string)count(array_filter($invoices, fn($i) => $i['status'] === 'paid')), 'check-circle', 'Fully settled')?></div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-lg-4"><label class="form-label">Search</label><input class="form-control" name="q" value="<?=e($search)?>" placeholder="Invoice number, patient or ID"></div>
            <div class="col-md-2"><label class="form-label">Status</label><select class="form-select" name="status"><option value="">All statuses</option><?php foreach (['unpaid','partial','paid'] as $st): ?><option value="<?=$st?>" <?=$statusFilter===$st?'selected':''?>><?=e(ucfirst($st))?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">From</label><input class="form-control" type="date" name="from" value="<?=e($from)?>"></div>
            <div class="col-md-2"><label class="form-label">To</label><input class="form-control" type="date" name="to" value="<?=e($to)?>"></div>
            <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Filter</button><a class="btn btn-light" href="<?=e(url('billing/index.php'))?>">Reset</a></div>
        </form>
    </div>
</div>

<?php if (in_array($user['role'], ['super_admin', 'admin'], true)): ?>
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card h-100"><div class="card-header"><h2 class="section-title">Create manual invoice</h2></div>
            <div class="card-body">
                <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="invoice">
                    <div class="mb-3"><label class="form-label">Patient</label><select class="form-select" name="patient_id" required><option value="">Select patient</option><?php foreach ($patients as $p): ?><option value="<?=(int)$p['id']?>"><?=e($p['name'].' · '.$p['patient_number'])?></option><?php endforeach; ?></select></div>
                    <div class="mb-3"><label class="form-label">Invoice total</label><input class="form-control" type="number" min="0.01" step="0.01" name="total" required></div>
                    <div class="mb-3"><label class="form-label">Due date</label><input class="form-control" type="date" name="due_date" min="<?=date('Y-m-d')?>"></div>
                    <button class="btn btn-primary">Create invoice</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (in_array($user['role'], ['super_admin', 'admin', 'staff'], true)): ?>
    <div class="<?=in_array($user['role'], ['super_admin', 'admin'], true) ? 'col-lg-7' : 'col-12'?>">
        <div class="card h-100"><div class="card-header"><h2 class="section-title">Record payment</h2></div>
            <div class="card-body">
                <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="payment">
                    <div class="mb-3"><label class="form-label">Outstanding invoice</label><select class="form-select" name="invoice_id" required><option value="">Select invoice</option><?php foreach ($outstanding as $i): ?><option value="<?=(int)$i['id']?>"><?=e($i['invoice_number'].' · '.$i['patient_name'].' · Balance '.number_format((float)$i['total']-(float)$i['amount_paid'],2))?></option><?php endforeach; ?></select></div>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Amount</label><input class="form-control" type="number" name="amount" min="0.01" step="0.01" required></div>
                        <div class="col-md-4"><label class="form-label">Method</label><select class="form-select" name="method"><option value="cash">Cash</option><option value="credit_card">Card</option><option value="bank_transfer">Bank transfer</option><option value="insurance">Insurance</option></select></div>
                        <div class="col-md-4"><label class="form-label">Reference (optional)</label><input class="form-control" name="reference" maxlength="100"></div>
                    </div>
                    <button class="btn btn-outline-primary mt-3">Record payment</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><h2 class="section-title mb-0">Invoice register</h2><span class="small-muted"><?=count($invoices)?> records</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th>Invoice</th><th>Patient</th><th>Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($invoices as $i): ?>
            <tr>
                <td><strong><?=e($i['invoice_number'])?></strong></td>
                <td><?=e($i['patient_name'])?><div class="small-muted"><?=e($i['patient_number'])?></div></td>
                <td><?=e(substr($i['created_at'], 0, 10))?></td>
                <td><?=money($i['total'])?></td><td><?=money($i['amount_paid'])?></td>
                <td class="fw-bold"><?=money(max(0, (float)$i['total'] - (float)$i['amount_paid']))?></td>
                <td><?=status_badge($i['status'])?></td>
                <td><a class="btn btn-sm btn-outline-primary" href="<?=e(url('billing/view.php?id='.(int)$i['id']))?>">View / Print</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$invoices): ?><?=empty_row(8, 'No invoices match your filters.')?><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
