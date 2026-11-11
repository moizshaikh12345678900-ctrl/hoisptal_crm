<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_role(['super_admin', 'admin', 'staff', 'doctor', 'patient']);

$db = db();
$user = current_user();
$id = (int)($_GET['id'] ?? 0);

$sql = "SELECT i.*, p.patient_number, p.full_name, p.email AS patient_email, p.phone AS patient_phone,
               p.address AS patient_address, COALESCE(NULLIF(p.full_name,''), pu.name, 'Patient') AS patient_name,
               a.appointment_date, a.start_at, a.token_number,
               d.id AS doctor_id, du.name AS doctor_name, d.specialization, dep.name AS department_name
        FROM invoices i
        JOIN patients p ON p.id = i.patient_id
        LEFT JOIN users pu ON pu.id = p.user_id
        LEFT JOIN appointments a ON a.id = i.appointment_id
        LEFT JOIN doctors d ON d.id = a.doctor_id
        LEFT JOIN users du ON du.id = d.user_id
        LEFT JOIN departments dep ON dep.id = d.department_id
        WHERE i.id = ? LIMIT 1";
$stmt = $db->prepare($sql);
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found.');
}

if ($user['role'] === 'patient') {
    $patient = patient_for_user((int)$user['id']);
    if (!$patient || (int)$patient['id'] !== (int)$invoice['patient_id']) {
        http_response_code(403);
        exit('You cannot access this invoice.');
    }
} elseif ($user['role'] === 'doctor') {
    $doctor = doctor_for_user((int)$user['id']);
    if (!$doctor || (int)$doctor['id'] !== (int)$invoice['doctor_id']) {
        http_response_code(403);
        exit('You can only access invoices for your own appointments.');
    }
}

$paymentStmt = $db->prepare('SELECT * FROM payments WHERE invoice_id = ? ORDER BY paid_at ASC, id ASC');
$paymentStmt->execute([$id]);
$payments = $paymentStmt->fetchAll();

$clinicName = setting('clinic_name', 'Clinic');
$clinicAddress = setting('clinic_address');
$clinicPhone = setting('clinic_phone');
$clinicEmail = setting('clinic_email');
$balance = max(0, (float)$invoice['total'] - (float)$invoice['amount_paid']);
$pageTitle = 'Invoice ' . $invoice['invoice_number'];
$pageTitlePrefix = 'Invoice';
$logoLetter = strtoupper(substr(trim((string)$clinicName) ?: 'C', 0, 1));

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/page.php';
page_heading('Finance', 'Invoice details', 'Review invoice, payment history and print a clinic copy.',
    '<a class="btn btn-light" href="'.e(url($user['role']==='patient'?'patient/invoices.php':($user['role']==='doctor'?'doctor/invoices.php':'billing/index.php'))).'"><i class="bi bi-arrow-left me-1"></i>Back</a><button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print / Save PDF</button>');
?>
<style>
:root {
    --iv-teal: #14c4b2;
    --iv-teal-dark: #0a9d90;
    --iv-navy: #101536;
    --iv-ink: #1d2233;
    --iv-muted: #6f7785;
    --iv-line: #3b4152;
}

.iv {
    position: relative;
    max-width: 820px;
    margin: 0 auto;
    overflow: hidden;
    color: var(--iv-ink);
    background: #fff;
    border: 1px solid #e3e8e8;
    box-shadow: 0 10px 30px rgba(16, 21, 54, .10);
    font-family: Roboto, Arial, Helvetica, sans-serif;
    font-size: 12px;
    line-height: 1.4;
    overflow-wrap: anywhere;
}

/* ---------- Bars ---------- */
.iv-bar { display: flex; gap: 14px; height: 24px; }
.iv-bar span { display: block; background: var(--iv-teal); }
.iv-bar span:first-child { flex: 0 0 48%; }
.iv-bar span:last-child { flex: 1; border-radius: 22px 0 0 22px; }
.iv-bar.bottom { height: 20px; margin-top: 22px; }
.iv-bar.bottom span:first-child { flex: 1; }
.iv-bar.bottom span:last-child { display: none; }

.iv-body { padding: 24px 32px 0; }

/* ---------- Header ---------- */
.iv-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; }
.iv-title {
    margin: 0 0 5px;
    color: var(--iv-teal-dark);
    font-size: 27px;
    font-weight: 900;
    letter-spacing: 5px;
    line-height: 1.1;
}
.iv-mini { font-size: 10px; line-height: 1.55; }
.iv-mini b { display: inline-block; min-width: 70px; color: var(--iv-muted); font-weight: 400; }
.iv-mini strong { color: var(--iv-navy); }

.iv-brand { display: flex; align-items: center; gap: 10px; }
.iv-logo {
    display: flex; align-items: center; justify-content: center;
    flex: 0 0 42px; width: 42px; height: 42px;
    color: var(--iv-teal);
    border: 4px solid var(--iv-teal);
    border-radius: 50%;
    font-size: 22px; font-weight: 900;
}
.iv-brand-name {
    color: var(--iv-teal-dark);
    font-size: 19px; font-weight: 800; line-height: 1.05;
    text-transform: uppercase;
}
.iv-brand-sub { margin-top: 3px; color: var(--iv-muted); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; }

/* ---------- Info ---------- */
.iv-info { display: flex; justify-content: space-between; gap: 24px; margin-top: 24px; }
.iv-info > div { min-width: 0; }
.iv-info .right { min-width: 250px; }
.iv-to { font-size: 12px; font-weight: 500; color: var(--iv-navy); }
.iv-name { margin: 1px 0 5px; color: var(--iv-teal); font-size: 20px; font-weight: 800; line-height: 1.15; }
.iv-h-teal { margin: 0 0 5px; color: var(--iv-teal); font-size: 13px; font-weight: 800; }
.iv-info .right .iv-h-teal { text-align: right; }

.iv-list { margin: 0; padding: 0; list-style: none; font-size: 10px; line-height: 1.6; }
.iv-list li { display: flex; gap: 6px; }
.iv-list .k { flex: 0 0 78px; color: var(--iv-navy); font-weight: 700; text-transform: uppercase; }
.iv-list .k::after { content: ":"; float: right; padding-right: 4px; }
.iv-list .v { min-width: 0; }

.iv-badge-wrap .badge { font-size: 10px; }

/* ---------- Pill tables ---------- */
.iv-label { margin: 22px 0 8px; color: var(--iv-teal); font-size: 12px; font-weight: 800; }
.iv-table {
    width: calc(100% + 12px);
    margin: 0 -6px;
    border-collapse: separate;
    border-spacing: 6px 0;
    font-size: 11px;
}
.iv-table th {
    padding: 7px 10px;
    color: #fff;
    background: var(--iv-navy);
    border-radius: 24px;
    font-size: 13px; font-weight: 600;
    text-align: center; white-space: nowrap;
}
.iv-table th:nth-child(even) { background: var(--iv-teal); }
.iv-table td {
    padding: 11px 10px;
    border-bottom: 1.5px solid var(--iv-line);
    text-align: center;
    vertical-align: middle;
}
.iv-table td.left { text-align: left; }
.iv-table small { display: block; color: var(--iv-muted); font-size: 9.5px; }
.iv-table.sm td { padding: 8px 10px; font-size: 10.5px; }
.iv-table.sm th { font-size: 11.5px; padding: 6px 8px; }

/* ---------- Bottom split ---------- */
.iv-bottom { display: flex; justify-content: space-between; align-items: flex-start; gap: 26px; margin-top: 10px; }
.iv-bottom .left { flex: 1; min-width: 0; font-size: 10px; padding-top: 10px; }
.iv-bottom .left p { margin: 0; color: var(--iv-ink); }
.iv-bottom .right { flex: 0 0 290px; }
.iv-sum {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    padding: 8px 0;
    border-bottom: 1.5px solid var(--iv-line);
    font-size: 11.5px;
}
.iv-sum span:first-child { flex: 1; text-align: center; }
.iv-sum span:last-child { flex: 1; text-align: center; font-weight: 600; }
.iv-total { display: flex; gap: 8px; margin-top: 12px; }
.iv-total span {
    flex: 1; padding: 7px 10px;
    color: #fff; background: var(--iv-navy);
    border-radius: 24px;
    font-size: 13px; font-weight: 800; text-align: center;
}
.iv-total span:last-child { background: var(--iv-teal); }

/* ---------- Footer ---------- */
.iv-foot { display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; margin-top: 22px; }
.iv-contact { margin: 0; padding: 0; list-style: none; font-size: 10.5px; color: var(--iv-ink); }
.iv-contact li { display: flex; align-items: center; gap: 8px; margin-top: 7px; }
.iv-ico {
    display: inline-flex; align-items: center; justify-content: center;
    flex: 0 0 20px; width: 20px; height: 20px;
    color: #fff; background: var(--iv-teal);
    border-radius: 5px; font-size: 11px;
}
.iv-sign { min-width: 230px; text-align: center; }
.iv-sign-line { height: 28px; margin-bottom: 3px; border-bottom: 1.5px solid var(--iv-teal); }
.iv-sign strong { display: block; color: var(--iv-navy); font-size: 15px; line-height: 1.2; }
.iv-sign span { font-size: 11px; letter-spacing: .5px; text-transform: uppercase; }
.iv-note { margin: 14px 0 0; padding-top: 6px; color: var(--iv-muted); border-top: 1px solid #e1e5e6; font-size: 8.5px; text-align: center; }

/* ---------- Larger on desktop screens ---------- */
@media screen and (min-width: 768px) {
    .iv { zoom: 1.2; }
}

/* ---------- Mobile ---------- */
@media screen and (max-width: 767px) {
    .iv-body { padding: 18px 14px 0; }
    .iv-head, .iv-info, .iv-bottom, .iv-foot { flex-direction: column; }
    .iv-info .right, .iv-bottom .right { min-width: 0; width: 100%; flex: none; }
    .iv-info .right .iv-h-teal { text-align: left; }
    .iv-title { font-size: 22px; letter-spacing: 3px; }
    .iv-tw { overflow-x: auto; }
    .iv-table { min-width: 460px; }
    .iv-sign { width: 100%; }
}

/* ---------- Print ---------- */
@media print {
    @page { size: A4 portrait; margin: 8mm; }
    body { background: #fff !important; }
    .app-topbar, .app-sidebar, .page-heading, .no-print, .app-main > .content-wrap > .alert { display: none !important; }
    .app-shell, .app-main, .content-wrap {
        display: block !important;
        margin: 0 !important; padding: 0 !important;
        width: 100% !important; max-width: none !important;
    }
    .iv { max-width: none; border: 0; box-shadow: none; zoom: .92; }
    .iv-tw { overflow: visible; }
    .iv-table { min-width: 0; }
    .iv-table tr, .iv-sum { break-inside: avoid; page-break-inside: avoid; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
}
</style>

<div class="iv">

    <div class="iv-bar"><span></span><span></span></div>

    <div class="iv-body">

        <!-- Header -->
        <div class="iv-head">
            <div>
                <h1 class="iv-title">INVOICE</h1>
                <div class="iv-mini">
                    <div><b>Invoice:</b> <strong><?=e($invoice['invoice_number'])?></strong></div>
                    <div><b>Patient ID:</b> <strong><?=e($invoice['patient_number'])?></strong></div>
                    <div><b>Date:</b> <strong><?=e(substr($invoice['created_at'],0,10))?></strong></div>
                    <div><b>Due Date:</b> <strong><?=e($invoice['due_date'] ?: 'On receipt')?></strong></div>
                </div>
            </div>

            <div class="iv-brand">
                <div class="iv-logo"><?=e($logoLetter)?></div>
                <div>
                    <div class="iv-brand-name"><?=e($clinicName)?></div>
                    <div class="iv-brand-sub">Payment invoice</div>
                </div>
            </div>
        </div>

        <!-- Billed to / details -->
        <div class="iv-info">
            <div class="left">
                <div class="iv-to">INVOICE TO:</div>
                <div class="iv-name"><?=e($invoice['patient_name'])?></div>
                <ul class="iv-list">
                    <?php if ($invoice['patient_phone']): ?>
                        <li><span class="k">Phone</span><span class="v"><?=e($invoice['patient_phone'])?></span></li>
                    <?php endif; ?>
                    <?php if ($invoice['patient_email']): ?>
                        <li><span class="k">Email</span><span class="v"><?=e($invoice['patient_email'])?></span></li>
                    <?php endif; ?>
                    <?php if ($invoice['patient_address']): ?>
                        <li><span class="k">Address</span><span class="v"><?=e($invoice['patient_address'])?></span></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="right">
                <div class="iv-h-teal">Invoice Details</div>
                <ul class="iv-list">
                    <li><span class="k">Status</span><span class="v iv-badge-wrap"><?=status_badge($invoice['status'])?></span></li>
                    <li><span class="k">Appointment</span><span class="v"><?= $invoice['appointment_id'] ? '#'.(int)$invoice['appointment_id'] : 'Manual invoice' ?></span></li>
                    <?php if ($invoice['doctor_name']): ?>
                        <li><span class="k">Doctor</span><span class="v"><?=e($invoice['doctor_name'])?></span></li>
                    <?php endif; ?>
                    <?php if ($invoice['department_name']): ?>
                        <li><span class="k">Department</span><span class="v"><?=e($invoice['department_name'])?></span></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <!-- Items -->
        <div class="iv-label">Services</div>
        <div class="iv-tw">
            <table class="iv-table">
                <thead>
                    <tr><th>Description</th><th>Consultation Details</th><th>Amount</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="left"><strong><?= $invoice['appointment_id'] ? 'Doctor consultation fee' : 'Clinic services' ?></strong></td>
                        <td class="left">
                            <?php if ($invoice['doctor_name']): ?>
                                <?=e($invoice['doctor_name'])?><?= $invoice['specialization'] ? ' · '.e($invoice['specialization']) : '' ?>
                            <?php endif; ?>
                            <small>
                                <?php if ($invoice['appointment_date']): ?>
                                    <?=e($invoice['appointment_date'])?><?= $invoice['start_at'] ? ' · '.e(substr($invoice['start_at'],0,5)) : '' ?>
                                <?php else: ?>As billed by clinic<?php endif; ?>
                            </small>
                        </td>
                        <td><strong><?=money($invoice['total'])?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Notes + totals -->
        <div class="iv-bottom">
            <div class="left">
                <div class="iv-h-teal">Terms &amp; Conditions:</div>
                <p>This is a computer-generated invoice. Please keep it for your records and quote the invoice number for any payment query.</p>
                <div class="iv-h-teal" style="margin-top:12px">Thank You For Your Business!</div>
            </div>

            <div class="right">
                <div class="iv-sum"><span>Invoice Total</span><span><?=money($invoice['total'])?></span></div>
                <div class="iv-sum"><span>Amount Paid</span><span><?=money($invoice['amount_paid'])?></span></div>
                <div class="iv-total"><span>BALANCE DUE</span><span><?=money($balance)?></span></div>
            </div>
        </div>

        <!-- Payment history -->
        <div class="iv-label">Payment History</div>
        <div class="iv-tw">
            <table class="iv-table sm">
                <thead>
                    <tr><th>Date</th><th>Method</th><th>Reference</th><th>Received By</th><th>Amount</th></tr>
                </thead>
                <tbody>
                <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td><?=e($payment['paid_at'])?></td>
                        <td><?=e(ucwords(str_replace('_',' ',$payment['method'])))?></td>
                        <td><?=e($payment['reference'] ?: '—')?></td>
                        <td><?=e($payment['received_by'])?></td>
                        <td><strong><?=money($payment['amount'])?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$payments): ?>
                    <tr><td colspan="5">No payments recorded yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Footer: contact + signature -->
        <div class="iv-foot">
            <ul class="iv-contact">
                <?php if ($clinicPhone): ?>
                    <li><span class="iv-ico"><i class="bi bi-telephone-fill"></i></span><?=e($clinicPhone)?></li>
                <?php endif; ?>
                <?php if ($clinicEmail): ?>
                    <li><span class="iv-ico"><i class="bi bi-envelope-fill"></i></span><?=e($clinicEmail)?></li>
                <?php endif; ?>
                <?php if ($clinicAddress): ?>
                    <li><span class="iv-ico"><i class="bi bi-geo-alt-fill"></i></span><?=nl2br(e($clinicAddress))?></li>
                <?php endif; ?>
            </ul>

            <div class="iv-sign">
                <div class="iv-sign-line"></div>
                <strong>Authorized Signature</strong>
                <span><?=e($clinicName)?></span>
            </div>
        </div>

        <div class="iv-note">
            <span data-i18n="thank_you_choosing">Thank you for choosing</span> <?=e($clinicName)?>. ·
            <span data-i18n="invoice">Invoice</span> <?=e($invoice['invoice_number'])?>
        </div>

    </div>

    <div class="iv-bar bottom"><span></span><span></span></div>

</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>