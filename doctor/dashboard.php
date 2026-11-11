<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/functions.php';
require_role(['doctor', 'super_admin']);

$u = current_user();
$db = db();

$d = doctor_for_user((int)$u['id']);

if (!$d && $u['role'] === 'doctor') {
    http_response_code(403);
    exit('Doctor profile is not linked to this account. Ask an administrator to complete setup.');
}

$did = (int)($d['id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Appointment statistics and list
|--------------------------------------------------------------------------
*/

$s = $db->prepare("
    SELECT
        a.*,
        p.patient_number,
        COALESCE(NULLIF(p.full_name,''), pu.name, 'Patient') AS patient_name,
        COALESCE(NULLIF(p.phone,''), pu.phone, '') AS patient_phone,
        p.date_of_birth,
        p.gender,
        p.blood_group,
        p.allergies,
        EXISTS(
            SELECT 1 FROM medical_records mr
            WHERE mr.appointment_id = a.id
        ) AS has_record,
        (
            SELECT i.id FROM invoices i
            WHERE i.appointment_id = a.id
            LIMIT 1
        ) AS invoice_id
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    LEFT JOIN users pu ON pu.id = p.user_id
    WHERE a.doctor_id = ?
    ORDER BY a.appointment_date DESC, a.start_at DESC
    LIMIT 200
");

$s->execute([$did]);
$rows = $s->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Assigned patients
|--------------------------------------------------------------------------
*/

$assignedStmt = $db->prepare("
    SELECT
        p.id,
        p.patient_number,
        COALESCE(NULLIF(p.full_name,''), pu.name, 'Patient') AS patient_name,
        COALESCE(NULLIF(p.phone, ''), pu.phone, '') AS phone,
        COALESCE(NULLIF(p.email, ''), pu.email, '') AS email,
        p.date_of_birth,
        p.gender,
        p.blood_group,
        p.allergies,
        p.created_at,
        COALESCE(visits.total_visits, 0) AS total_visits,
        visits.last_visit
    FROM patients p
    LEFT JOIN users pu ON pu.id = p.user_id
    LEFT JOIN (
        SELECT
            patient_id,
            COUNT(*) AS total_visits,
            MAX(appointment_date) AS last_visit
        FROM appointments
        WHERE doctor_id = ?
          AND status NOT IN ('cancelled', 'no_show')
        GROUP BY patient_id
    ) visits ON visits.patient_id = p.id
    WHERE p.assigned_doctor_id = ?
       OR EXISTS (
            SELECT 1
            FROM appointments a
            WHERE a.patient_id = p.id
              AND a.doctor_id = ?
              AND a.status NOT IN ('cancelled', 'no_show')
       )
    ORDER BY COALESCE(visits.last_visit, p.created_at) DESC, p.id DESC
");

$assignedStmt->execute([$did, $did, $did]);
$assignedPatients = $assignedStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Dashboard statistics
|--------------------------------------------------------------------------
*/

$today = date('Y-m-d');

$todayVisits = count(array_filter(
    $rows,
    fn($a) => $a['appointment_date'] === $today
));

$completed = count(array_filter(
    $rows,
    fn($a) => $a['status'] === 'completed'
));

$pending = count(array_filter(
    $rows,
    fn($a) => in_array(
        $a['status'],
        ['requested', 'confirmed', 'checked_in', 'in_consultation'],
        true
    )
));

$cancelled = count(array_filter(
    $rows,
    fn($a) => in_array($a['status'], ['cancelled', 'no_show'], true)
));

/*
|--------------------------------------------------------------------------
| Invoice statistics
|--------------------------------------------------------------------------
*/

$invoiceStats = [
    'total' => 0,
    'paid' => 0,
    'unpaid' => 0,
    'partial' => 0,
    'revenue' => 0
];

$invoiceStmt = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'paid') AS paid,
        SUM(status = 'unpaid') AS unpaid,
        SUM(status = 'partial') AS partial,
        COALESCE(SUM(amount_paid), 0) AS revenue
    FROM invoices
    WHERE appointment_id IN (
        SELECT id FROM appointments WHERE doctor_id = ?
    )
");

$invoiceStmt->execute([$did]);
$invoiceResult = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

if ($invoiceResult) {
    $invoiceStats = array_merge($invoiceStats, $invoiceResult);
}

/*
|--------------------------------------------------------------------------
| Prescription statistics
|--------------------------------------------------------------------------
*/

$prescriptionStmt = $db->prepare("
    SELECT COUNT(*)
    FROM prescriptions
    WHERE doctor_id = ?
");

$prescriptionStmt->execute([$did]);
$totalPrescriptions = (int)$prescriptionStmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| Medical record statistics
|--------------------------------------------------------------------------
*/

$recordStmt = $db->prepare("
    SELECT COUNT(*)
    FROM medical_records
    WHERE doctor_id = ?
");

$recordStmt->execute([$did]);
$totalRecords = (int)$recordStmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| Recent prescriptions
|--------------------------------------------------------------------------
*/

$recentRxStmt = $db->prepare("
    SELECT
        rx.id,
        rx.prescription_number,
        rx.issued_at,
        mr.diagnosis,
        p.patient_number,
        COALESCE(NULLIF(p.full_name,''), pu.name, 'Patient') AS patient_name
    FROM prescriptions rx
    JOIN medical_records mr ON mr.id = rx.medical_record_id
    JOIN patients p ON p.id = mr.patient_id
    LEFT JOIN users pu ON pu.id = p.user_id
    WHERE rx.doctor_id = ?
    ORDER BY rx.issued_at DESC
    LIMIT 5
");

$recentRxStmt->execute([$did]);
$recentPrescriptions = $recentRxStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Doctor dashboard';

require __DIR__.'/../includes/header.php';
require __DIR__.'/../includes/page.php';

page_heading(
    'Clinical workspace',
    'Doctor dashboard',
    'Manage your appointments, patients, consultations and clinical activity.',
    '<a class="btn btn-primary" href="'.e(url('appointments/index.php')).'"><i class="bi bi-calendar-check me-1"></i>Appointments</a>'
);
?>

<style>
.doctor-dashboard .dash-card {
    height:100%;
    border:1px solid #e9edf2;
    border-radius:14px;
    background:#fff;
    transition:transform .2s ease,box-shadow .2s ease;
}
.doctor-dashboard .dash-card:hover {
    transform:translateY(-3px);
    box-shadow:0 9px 25px rgba(25,40,65,.07);
}
.doctor-dashboard .dash-icon {
    width:43px;
    height:43px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:12px;
    font-size:19px;
    background:#edf5ff;
    color:#2364b5;
}
.doctor-dashboard .dash-number {
    font-size:27px;
    font-weight:800;
    color:#17243a;
    line-height:1.2;
}
.doctor-dashboard .dash-label {
    color:#758196;
    font-size:13px;
}
.doctor-dashboard .quick-link {
    display:flex;
    align-items:center;
    gap:12px;
    padding:14px;
    height:100%;
    text-decoration:none;
    color:#25334a;
    border:1px solid #e9edf2;
    border-radius:12px;
    transition:.2s ease;
    background:#fff;
}
.doctor-dashboard .quick-link:hover {
    border-color:#b8cce8;
    background:#f8fbff;
    transform:translateY(-2px);
}
.doctor-dashboard .quick-link i {
    font-size:21px;
    color:#2870bd;
}
.doctor-dashboard .quick-link small {
    display:block;
    color:#7c8798;
    margin-top:3px;
}
.doctor-dashboard .section-card {
    border:1px solid #e9edf2;
    border-radius:14px;
    overflow:hidden;
    background:#fff;
}
.doctor-dashboard .section-heading {
    padding:17px 20px;
    border-bottom:1px solid #edf0f4;
}
.doctor-dashboard .section-heading h2 {
    font-size:16px;
    font-weight:750;
    margin:0;
}
.doctor-dashboard .table td {
    vertical-align:middle;
}
.doctor-dashboard .patient-name {
    font-weight:700;
    color:#26364c;
}
.doctor-dashboard .muted {
    color:#8490a0;
    font-size:12px;
}
.doctor-dashboard .status-pill {
    font-size:11px;
}
@media(max-width:767px) {
    .doctor-dashboard .dash-number {font-size:23px;}
    .doctor-dashboard .section-heading {padding:14px;}
}
</style>

<div class="doctor-dashboard">

    <!-- Statistics -->
    <div class="row g-3 mb-4">

        <div class="col-6 col-xl-3">
            <div class="dash-card p-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="dash-label">Today's visits</span>
                    <span class="dash-icon"><i class="bi bi-calendar-day"></i></span>
                </div>
                <div class="dash-number"><?= $todayVisits ?></div>
                <div class="dash-label mt-1">Appointments today</div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="dash-card p-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="dash-label">Pending visits</span>
                    <span class="dash-icon"><i class="bi bi-hourglass-split"></i></span>
                </div>
                <div class="dash-number"><?= $pending ?></div>
                <div class="dash-label mt-1">Awaiting completion</div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="dash-card p-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="dash-label">Completed</span>
                    <span class="dash-icon"><i class="bi bi-check-circle"></i></span>
                </div>
                <div class="dash-number"><?= $completed ?></div>
                <div class="dash-label mt-1">Completed consultations</div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <a class="d-block h-100 text-decoration-none text-reset" href="<?=e(url('doctor/patients.php'))?>">
                <div class="dash-card p-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="dash-label">My patients</span>
                        <span class="dash-icon"><i class="bi bi-people"></i></span>
                    </div>
                    <div class="dash-number"><?= count($assignedPatients) ?></div>
                    <div class="dash-label mt-1">Assigned or seen by you</div>
                </div>
            </a>
        </div>

    </div>

    <!-- Clinical overview -->
    <div class="row g-3 mb-4">

        <div class="col-md-4">
            <div class="dash-card p-3">
                <div class="dash-label mb-2">Medical records</div>
                <div class="dash-number"><?= $totalRecords ?></div>
                <div class="dash-label">Records created by you</div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="dash-card p-3">
                <div class="dash-label mb-2">Prescriptions</div>
                <div class="dash-number"><?= $totalPrescriptions ?></div>
                <div class="dash-label">Prescriptions issued</div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="dash-card p-3">
                <div class="dash-label mb-2">Invoice collection</div>
                <div class="dash-number"><?= money((float)$invoiceStats['revenue']) ?></div>
                <div class="dash-label"><?= (int)$invoiceStats['total'] ?> invoices generated</div>
            </div>
        </div>

    </div>

    <!-- Quick actions -->
    <div class="section-card mb-4">
        <div class="section-heading">
            <h2>Quick actions</h2>
        </div>

        <div class="p-3">
            <div class="row g-3">

                <div class="col-6 col-lg-3">
                    <a class="quick-link" href="<?=e(url('appointments/index.php'))?>">
                        <i class="bi bi-calendar-check"></i>
                        <span><strong>Appointments</strong><small>View schedule</small></span>
                    </a>
                </div>

                <div class="col-6 col-lg-3">
                    <a class="quick-link" href="<?=e(url('medical-records/index.php'))?>">
                        <i class="bi bi-file-medical"></i>
                        <span><strong>Medical records</strong><small>Clinical notes</small></span>
                    </a>
                </div>

                <div class="col-6 col-lg-3">
                    <a class="quick-link" href="<?=e(url('prescriptions/index.php'))?>">
                        <i class="bi bi-capsule"></i>
                        <span><strong>Prescriptions</strong><small>Medicine history</small></span>
                    </a>
                </div>

                <div class="col-6 col-lg-3">
                    <a class="quick-link" href="<?=e(url('doctor/invoices.php'))?>">
                        <i class="bi bi-receipt"></i>
                        <span><strong>My invoices</strong><small>Billing history</small></span>
                    </a>
                </div>

                <div class="col-6 col-lg-3">
                    <a class="quick-link" href="<?=e(url('schedules/index.php'))?>">
                        <i class="bi bi-clock-history"></i>
                        <span><strong>Clinic hours</strong><small>My availability</small></span>
                    </a>
                </div>

                <div class="col-6 col-lg-3">
                    <a class="quick-link" href="<?=e(url('doctor/patients.php'))?>">
                        <i class="bi bi-person-lines-fill"></i>
                        <span><strong>My patients</strong><small>Patient register</small></span>
                    </a>
                </div>

            </div>
        </div>
    </div>

    <!-- Assigned appointments -->
    <div class="section-card mb-4">

        <div class="section-heading d-flex justify-content-between align-items-center gap-2">
            <div>
                <h2>Assigned appointments</h2>
                <div class="muted mt-1">Your latest 200 appointments</div>
            </div>
            <a class="btn btn-sm btn-outline-primary" href="<?=e(url('appointments/index.php'))?>">
                View all
            </a>
        </div>

        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Patient details</th>
                        <th>Date & time</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($rows as $a): ?>
                    <tr>

                        <td>
                            <div class="patient-name"><?=e($a['patient_name'])?></div>
                            <div class="muted">
                                <?=e($a['patient_number'])?> · <span data-i18n="token">Token</span> #<?= (int)$a['token_number'] ?>
                            </div>
                        </td>

                        <td>
                            <?=e($a['patient_phone'] ?: 'No phone')?>
                            <div class="muted">
                                <?=e($a['gender'] ?: '—')?> ·
                                <?=e($a['date_of_birth'] ?: 'DOB not recorded')?>
                            </div>

                            <?php if (!empty($a['allergies'])): ?>
                                <div class="small text-danger">
                                    Allergies: <?=e($a['allergies'])?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?=e($a['appointment_date'])?>
                            <div class="muted">
                                <?=e(substr($a['start_at'],0,5))?>–<?=e(substr($a['end_at'],0,5))?>
                            </div>
                        </td>

                        <td><?=e($a['reason'] ?: '—')?></td>

                        <td><?=status_badge($a['status'])?></td>

                        <td>
                            <div class="d-flex flex-wrap gap-1">

                                <?php if (
                                    !in_array($a['status'], ['cancelled','no_show'], true)
                                    && !(bool)$a['has_record']
                                ): ?>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?=e(url('medical-records/create.php?appointment_id='.$a['id']))?>">
                                        Consult
                                    </a>
                                <?php endif; ?>

                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?=e(url('appointments/details.php?id='.$a['id']))?>">
                                    Details
                                </a>

                                <?php if (!empty($a['invoice_id'])): ?>
                                    <a class="btn btn-sm btn-outline-success"
                                       href="<?=e(url('billing/invoice.php?id='.$a['invoice_id']))?>">
                                        Invoice
                                    </a>
                                <?php endif; ?>

                            </div>
                        </td>

                    </tr>
                <?php endforeach; ?>

                <?php if (!$rows): ?>
                    <?=empty_row(6, 'No appointments assigned to your profile.')?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Assigned patients -->
    <div class="section-card mb-4">

        <div class="section-heading d-flex justify-content-between align-items-center gap-2">
            <div>
                <h2>My patients</h2>
                <div class="muted mt-1">Patients assigned to you or seen in your appointments</div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge text-bg-light"><?=count($assignedPatients)?> patients</span>
                <a class="btn btn-sm btn-outline-primary" href="<?=e(url('doctor/patients.php'))?>">
                    View all
                </a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Patient ID</th>
                        <th>Contact</th>
                        <th>Date of birth</th>
                        <th>Gender</th>
                        <th>Blood group</th>
                        <th>Last visit / added</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($assignedPatients as $p): ?>
                    <tr>
                        <td>
                            <strong><?=e($p['patient_name'])?></strong>
                            <?php if (!empty($p['allergies'])): ?>
                                <div class="small text-danger">
                                    Allergies: <?=e($p['allergies'])?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td><code><?=e($p['patient_number'])?></code></td>

                        <td><?=e($p['phone'] ?: ($p['email'] ?: '—'))?></td>

                        <td><?=e($p['date_of_birth'] ?: '—')?></td>

                        <td><?=e($p['gender'] ?: '—')?></td>

                        <td><?=e($p['blood_group'] ?: '—')?></td>

                        <td><?=e($p['last_visit'] ?: substr((string)$p['created_at'],0,10))?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$assignedPatients): ?>
                    <?=empty_row(7, 'No patients are assigned to you and no visits were found.')?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent prescriptions -->
    <div class="section-card">

        <div class="section-heading d-flex justify-content-between align-items-center">
            <h2>Recent prescriptions</h2>
            <a href="<?=e(url('prescriptions/index.php'))?>" class="btn btn-sm btn-outline-primary">
                View all
            </a>
        </div>

        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Prescription</th>
                        <th>Patient</th>
                        <th>Diagnosis</th>
                        <th>Issued</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($recentPrescriptions as $rx): ?>
                    <tr>
                        <td><strong><?=e($rx['prescription_number'])?></strong></td>
                        <td>
                            <?=e($rx['patient_name'])?>
                            <div class="muted"><?=e($rx['patient_number'])?></div>
                        </td>
                        <td><?=e(mb_strimwidth((string)$rx['diagnosis'],0,65,'…'))?></td>
                        <td><?=e(substr($rx['issued_at'],0,10))?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$recentPrescriptions): ?>
                    <?=empty_row(4, 'No prescriptions issued yet.')?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php require __DIR__.'/../includes/footer.php'; ?>
