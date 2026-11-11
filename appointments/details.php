<?php
require_once __DIR__.'/../includes/functions.php';

require_role(['doctor', 'admin', 'super_admin']);

$user = current_user();
$db = db();

$doctor = $user['role'] === 'doctor'
    ? doctor_for_user($user['id'])
    : null;

if ($user['role'] === 'doctor' && !$doctor) {
    http_response_code(403);
    exit('Doctor profile is not linked to this account.');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    exit('Invalid appointment ID.');
}

/* Appointment and patient details */
$sql = "SELECT a.*,
        p.id AS patient_id,
        p.patient_number,
        COALESCE(NULLIF(p.full_name,''), pu.name, 'Patient') AS patient_name,
        COALESCE(NULLIF(p.phone,''), pu.phone, '') AS patient_phone,
        COALESCE(p.email, pu.email, '') AS patient_email,
        p.date_of_birth,
        p.gender,
        p.blood_group,
        p.allergies,
        p.address,
        p.emergency_name,
        p.emergency_phone,
        du.name AS doctor_name,
        dep.name AS department_name
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
        LEFT JOIN users pu ON pu.id = p.user_id
        JOIN doctors d ON d.id = a.doctor_id
        JOIN users du ON du.id = d.user_id
        JOIN departments dep ON dep.id = d.department_id
        WHERE a.id = ?
        LIMIT 1";

$stmt = $db->prepare($sql);
$stmt->execute([$id]);
$appointment = $stmt->fetch();

if (!$appointment) {
    http_response_code(404);
    exit('Appointment not found.');
}

/* Doctors can only view their own appointments */
if (
    $user['role'] === 'doctor'
    && (int)$appointment['doctor_id'] !== (int)$doctor['id']
) {
    http_response_code(403);
    exit('You can only view appointments assigned to you.');
}

/* Latest consultation for this appointment */
$recordStmt = $db->prepare(
    "SELECT mr.*
     FROM medical_records mr
     WHERE mr.appointment_id = ?
     ORDER BY mr.id DESC
     LIMIT 1"
);

$recordStmt->execute([$id]);
$currentRecord = $recordStmt->fetch();

$hasConsultation = (bool)$currentRecord;

/* Prescription and medicines */
$medicines = [];
$prescriptionInstructions = [];

if ($currentRecord) {
    $rxStmt = $db->prepare(
        "SELECT pr.prescription_number,
                pr.instructions,
                pi.medicine_name,
                pi.dosage,
                pi.frequency,
                pi.duration,
                pi.notes
         FROM prescriptions pr
         LEFT JOIN prescription_items pi
            ON pi.prescription_id = pr.id
         WHERE pr.medical_record_id = ?
         ORDER BY pr.id DESC"
    );

    $rxStmt->execute([(int)$currentRecord['id']]);
    $medicines = $rxStmt->fetchAll();

    foreach ($medicines as $medicine) {
        if (!empty($medicine['instructions'])) {
            $prescriptionInstructions[] = $medicine['instructions'];
        }
    }

    $prescriptionInstructions = array_unique($prescriptionInstructions);
}

$reportDate = $currentRecord['created_at'] ?? $appointment['appointment_date'];
$reportDateText = $reportDate ? date('m/d/Y', strtotime($reportDate)) : '—';

$pageTitle = 'Medical Report';

require __DIR__.'/../includes/header.php';
?>

<style>
:root {
    --rp-teal: #14c4b2;
    --rp-teal-dark: #0a9d90;
    --rp-navy: #101536;
    --rp-ink: #1d2233;
    --rp-muted: #6f7785;
    --rp-line: #3b4152;
}

.rp {
    position: relative;
    width: 100%;
    max-width: 820px;
    margin: 14px auto;
    padding: 0 0 0;
    overflow: hidden;
    color: var(--rp-ink);
    background: #fff;
    border: 1px solid #e3e8e8;
    box-shadow: 0 10px 30px rgba(16, 21, 54, .10);
    font-family: Roboto, Arial, Helvetica, sans-serif;
    font-size: 12px;
    line-height: 1.4;
    overflow-wrap: anywhere;
}

/* ---------- Top / bottom bars ---------- */
.rp-bar {
    display: flex;
    gap: 14px;
    height: 24px;
}
.rp-bar span { display: block; background: var(--rp-teal); }
.rp-bar span:first-child { flex: 0 0 48%; }
.rp-bar span:last-child { flex: 1; border-radius: 22px 0 0 22px; }
.rp-bar.bottom { margin-top: 18px; height: 20px; }
.rp-bar.bottom span:first-child { flex: 1; }
.rp-bar.bottom span:last-child { flex: 0 0 0; display: none; }

.rp-body { padding: 22px 30px 0; }

/* ---------- Header ---------- */
.rp-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
}
.rp-doc-title {
    margin: 0 0 4px;
    color: var(--rp-teal-dark);
    font-size: 25px;
    font-weight: 900;
    letter-spacing: 4px;
    line-height: 1.1;
    text-transform: uppercase;
}
.rp-mini { font-size: 10px; line-height: 1.5; }
.rp-mini b { display: inline-block; min-width: 78px; color: var(--rp-muted); font-weight: 400; }
.rp-mini strong { color: var(--rp-navy); }

.rp-brand { display: flex; align-items: center; gap: 10px; }
.rp-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    color: var(--rp-teal);
    border: 4px solid var(--rp-teal);
    border-radius: 50%;
    font-size: 21px;
    font-weight: 900;
}
.rp-brand-name {
    color: var(--rp-teal-dark);
    font-size: 19px;
    font-weight: 800;
    line-height: 1;
    text-transform: uppercase;
}
.rp-brand-slogan {
    margin-top: 3px;
    color: var(--rp-muted);
    font-size: 8px;
    letter-spacing: 2px;
    text-transform: uppercase;
}

/* ---------- Two column info ---------- */
.rp-info {
    display: flex;
    justify-content: space-between;
    gap: 24px;
    margin-top: 22px;
}
.rp-info > div { min-width: 0; }
.rp-info .right { text-align: left; min-width: 255px; }
.rp-to { font-size: 12px; font-weight: 500; color: var(--rp-navy); }
.rp-name {
    margin: 1px 0 4px;
    color: var(--rp-teal);
    font-size: 19px;
    font-weight: 800;
    line-height: 1.15;
}
.rp-heading-teal {
    margin: 0 0 4px;
    color: var(--rp-teal);
    font-size: 13px;
    font-weight: 800;
}
.rp-info .right .rp-heading-teal { text-align: right; }

.rp-list { margin: 0; padding: 0; list-style: none; font-size: 10px; line-height: 1.55; }
.rp-list li { display: flex; gap: 6px; }
.rp-list .k {
    flex: 0 0 82px;
    color: var(--rp-navy);
    font-weight: 700;
    text-transform: uppercase;
}
.rp-list .k::after { content: ":"; float: right; padding-right: 4px; }
.rp-list .v { min-width: 0; white-space: pre-wrap; }

/* ---------- Section label ---------- */
.rp-label {
    margin: 20px 0 8px;
    color: var(--rp-teal);
    font-size: 12px;
    font-weight: 800;
}

/* ---------- Pill table ---------- */
.rp-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 6px 0;
    margin: 0 -6px;
    width: calc(100% + 12px);
    font-size: 11px;
}
.rp-table th {
    padding: 7px 10px;
    color: #fff;
    background: var(--rp-navy);
    border-radius: 24px;
    font-size: 13px;
    font-weight: 600;
    text-align: center;
    white-space: nowrap;
}
.rp-table th:nth-child(even) { background: var(--rp-teal); }
.rp-table td {
    padding: 9px 10px;
    border-bottom: 1.5px solid var(--rp-line);
    text-align: center;
    vertical-align: middle;
    white-space: pre-wrap;
}
.rp-table td.left { text-align: left; }
.rp-table tr:last-child td { border-bottom: 1.5px solid var(--rp-line); }
.rp-table small { display: block; color: var(--rp-muted); font-size: 9px; }

/* ---------- Bottom split ---------- */
.rp-bottom {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 26px;
    margin-top: 14px;
}
.rp-bottom .left { flex: 1; min-width: 0; font-size: 10px; }
.rp-bottom .right { flex: 0 0 280px; }
.rp-bottom .left .rp-heading-teal { margin-top: 10px; }
.rp-bottom .left .rp-heading-teal:first-child { margin-top: 0; }
.rp-bottom p { margin: 0; white-space: pre-wrap; }

.rp-sum { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 0; font-size: 11px; }
.rp-sum span:first-child { flex: 1; text-align: center; }
.rp-sum span:last-child { flex: 1; text-align: center; }
.rp-pill-navy, .rp-pill-teal {
    display: block;
    flex: 1;
    padding: 6px 10px;
    color: #fff;
    border-radius: 24px;
    font-size: 12px;
    font-weight: 700;
    text-align: center;
}
.rp-pill-navy { background: var(--rp-navy); }
.rp-pill-teal { background: var(--rp-teal); }
.rp-sum + .rp-sum { border-top: 1.5px solid var(--rp-line); }
.rp-sum.pills { border-top: 1.5px solid var(--rp-line); padding-top: 10px; }

.rp-alert {
    padding: 9px 12px;
    color: #72551b;
    background: #fff9e9;
    border: 1px solid #f2e3bd;
    border-radius: 8px;
    font-size: 11px;
}

/* ---------- Footer ---------- */
.rp-foot {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 24px;
    margin-top: 18px;
}
.rp-contact { font-size: 10px; color: var(--rp-muted); }
.rp-sign { min-width: 220px; text-align: center; }
.rp-sign-line { height: 26px; margin-bottom: 3px; border-bottom: 1.5px solid var(--rp-teal); }
.rp-sign strong { display: block; color: var(--rp-navy); font-size: 15px; line-height: 1.2; }
.rp-sign span { color: var(--rp-ink); font-size: 11px; letter-spacing: .5px; text-transform: uppercase; }
.rp-note {
    margin: 14px 0 0;
    padding-top: 6px;
    color: var(--rp-muted);
    border-top: 1px solid #e1e5e6;
    font-size: 8px;
    text-align: center;
}

.rp-actions .btn { border-radius: 20px; font-weight: 600; }
.rp-actions .btn-primary { background: var(--rp-teal-dark); border-color: var(--rp-teal-dark); }
.rp-actions .btn-outline-primary { color: var(--rp-teal-dark); border-color: var(--rp-teal-dark); }
.rp-actions .btn-outline-primary:hover { color: #fff; background: var(--rp-teal-dark); }

/* ---------- Larger on desktop screens ---------- */
@media screen and (min-width: 768px) {
    .rp { zoom: 1.2; }
}

/* ---------- Mobile ---------- */
@media screen and (max-width: 767px) {
    .rp-body { padding: 18px 14px 0; }
    .rp-head, .rp-info, .rp-bottom, .rp-foot { flex-direction: column; }
    .rp-info .right, .rp-bottom .right { min-width: 0; width: 100%; flex: none; }
    .rp-info .right .rp-heading-teal { text-align: left; }
    .rp-doc-title { font-size: 21px; letter-spacing: 3px; }
    .rp-table-wrap { overflow-x: auto; }
    .rp-table { min-width: 520px; }
    .rp-sign { width: 100%; }
}

/* ---------- Print (one A4 page) ---------- */
@media print {
    @page { size: A4 portrait; margin: 6mm; }
    html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
    nav, aside, footer, .sidebar, .no-print { display: none !important; }
    .container, .container-fluid, main {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .rp {
        max-width: none;
        margin: 0;
        border: 0;
        box-shadow: none;
        zoom: .88;
    }
    .rp-table-wrap { overflow: visible; }
    .rp-table { min-width: 0; }
    .rp-label, .rp-table tr, .rp-sum { break-inside: avoid; page-break-inside: avoid; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    a { color: inherit !important; text-decoration: none !important; }
}
</style>

<div class="container py-4">

    <!-- Screen actions -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print rp-actions">

        <a
            href="<?= e(url(
                $user['role'] === 'doctor'
                    ? 'doctor/dashboard.php'
                    : 'medical-records/index.php'
            )) ?>"
            class="btn btn-sm btn-light"
        >
            <i class="bi bi-arrow-left me-1"></i>
            Back
        </a>

        <div class="d-flex flex-wrap gap-2">

            <?php if ($hasConsultation): ?>
                <a
                    href="<?= e(url(
                        'medical-records/details.php?id='
                        . (int)$currentRecord['id']
                    )) ?>"
                    class="btn btn-sm btn-outline-primary"
                >
                    View Medical Record
                </a>
            <?php endif; ?>

            <button
                type="button"
                class="btn btn-sm btn-primary"
                onclick="window.print()"
            >
                <i class="bi bi-printer me-1"></i>
                Save PDF / Print
            </button>

        </div>
    </div>

    <div class="rp">

        <!-- Top bar -->
        <div class="rp-bar"><span></span><span></span></div>

        <div class="rp-body">

            <!-- Header -->
            <div class="rp-head">

                <div>
                    <h1 class="rp-doc-title">Medical Report</h1>
                    <div class="rp-mini">
                        <div><b>Appointment:</b> <strong>#<?= (int)$appointment['id'] ?></strong></div>
                        <div><b>Patient ID:</b> <strong><?= e($appointment['patient_number'] ?: '—') ?></strong></div>
                        <div><b>Date:</b> <strong><?= e($reportDateText) ?></strong></div>
                    </div>
                </div>

                <div class="rp-brand">
                    <div class="rp-logo">C</div>
                    <div>
                        <div class="rp-brand-name">Clinical Care</div>
                        <div class="rp-brand-slogan">Patient &amp; consultation report</div>
                    </div>
                </div>

            </div>

            <!-- Patient / Appointment info -->
            <div class="rp-info">

                <div class="left">
                    <div class="rp-to">PATIENT:</div>
                    <div class="rp-name"><?= e($appointment['patient_name']) ?></div>

                    <ul class="rp-list">
                        <li><span class="k">Phone</span><span class="v"><?= e($appointment['patient_phone'] ?: 'Not provided') ?></span></li>
                        <li><span class="k">Email</span><span class="v"><?= e($appointment['patient_email'] ?: 'Not provided') ?></span></li>
                        <li><span class="k">Address</span><span class="v"><?= e($appointment['address'] ?: 'Not provided') ?></span></li>
                        <li><span class="k">Birth Date</span><span class="v"><?= e($appointment['date_of_birth'] ?: '—') ?></span></li>
                        <li><span class="k">Gender</span><span class="v"><?= e($appointment['gender'] ?: '—') ?></span></li>
                        <li><span class="k">Blood Group</span><span class="v"><?= e($appointment['blood_group'] ?: '—') ?></span></li>
                        <li><span class="k">Allergies</span><span class="v"><?= e($appointment['allergies'] ?: 'None recorded') ?></span></li>
                        <?php if ($appointment['emergency_name'] || $appointment['emergency_phone']): ?>
                            <li>
                                <span class="k">Emergency</span>
                                <span class="v"><?= e($appointment['emergency_name'] ?: '—') ?> · <?= e($appointment['emergency_phone'] ?: '—') ?></span>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="right">
                    <div class="rp-heading-teal">Appointment Details</div>

                    <ul class="rp-list">
                        <li><span class="k">Doctor</span><span class="v"><?= e($appointment['doctor_name']) ?></span></li>
                        <li><span class="k">Department</span><span class="v"><?= e($appointment['department_name']) ?></span></li>
                        <li><span class="k">Date</span><span class="v"><?= e($appointment['appointment_date']) ?></span></li>
                        <li>
                            <span class="k">Time</span>
                            <span class="v"><?= e(substr($appointment['start_at'], 0, 5)) ?> – <?= e(substr($appointment['end_at'], 0, 5)) ?></span>
                        </li>
                        <li><span class="k">Token</span><span class="v">#<?= (int)$appointment['token_number'] ?></span></li>
                        <li>
                            <span class="k">Status</span>
                            <span class="v"><?= e(ucwords(str_replace('_', ' ', $appointment['status']))) ?></span>
                        </li>
                        <li><span class="k">Reason</span><span class="v"><?= e($appointment['reason'] ?: 'Not provided') ?></span></li>
                        <?php if (!empty($appointment['notes'])): ?>
                            <li><span class="k">Notes</span><span class="v"><?= e($appointment['notes']) ?></span></li>
                        <?php endif; ?>
                    </ul>
                </div>

            </div>

            <?php if ($currentRecord): ?>

                <!-- Clinical assessment -->
                <div class="rp-label">Clinical Assessment</div>
                <div class="rp-table-wrap">
                    <table class="rp-table">
                        <thead>
                            <tr>
                                <th>Diagnosis</th>
                                <th>Symptoms</th>
                                <th>Treatment / Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="left"><?= e($currentRecord['diagnosis'] ?: 'Not recorded') ?></td>
                                <td class="left"><?= e($currentRecord['symptoms'] ?: 'Not recorded') ?></td>
                                <td class="left"><?= e($currentRecord['notes'] ?: 'Not recorded') ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Vitals -->
                <?php
                $vitals = [
                    'bp' => 'Blood Pressure',
                    'pulse' => 'Pulse',
                    'temp' => 'Temperature',
                    'weight' => 'Weight',
                    'spo2' => 'SpO₂'
                ];
                ?>
                <div class="rp-label">Vital Signs</div>
                <div class="rp-table-wrap">
                    <table class="rp-table">
                        <thead>
                            <tr>
                                <?php foreach ($vitals as $label): ?>
                                    <th><?= e($label) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <?php foreach ($vitals as $key => $label): ?>
                                    <td><?= e(($currentRecord[$key] ?? '') ?: '—') ?></td>
                                <?php endforeach; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Medicines -->
                <div class="rp-label">Prescription &amp; Medicines</div>

                <?php
                $rxRows = array_filter(
                    $medicines,
                    fn($m) => !empty($m['medicine_name'])
                );
                ?>

                <?php if ($rxRows): ?>
                    <div class="rp-table-wrap">
                        <table class="rp-table">
                            <thead>
                                <tr>
                                    <th>Medicine</th>
                                    <th>Dosage</th>
                                    <th>Frequency</th>
                                    <th>Duration</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rxRows as $medicine): ?>
                                    <tr>
                                        <td>
                                            <?= e($medicine['medicine_name']) ?>
                                            <?php if (!empty($medicine['notes'])): ?>
                                                <small><?= e($medicine['notes']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e($medicine['dosage'] ?: '—') ?></td>
                                        <td><?= e($medicine['frequency'] ?: '—') ?></td>
                                        <td><?= e($medicine['duration'] ?: '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="rp-mini">No prescription recorded.</div>
                <?php endif; ?>

                <!-- Instructions + follow-up -->
                <div class="rp-bottom">

                    <div class="left">
                        <?php if ($prescriptionInstructions): ?>
                            <div class="rp-heading-teal">Prescription Instructions:</div>
                            <p><?= e(implode("\n", $prescriptionInstructions)) ?></p>
                        <?php endif; ?>

                        <div class="rp-heading-teal">Thank You For Your Trust!</div>
                    </div>

                    <div class="right">
                        <div class="rp-sum pills">
                            <span class="rp-pill-navy">Follow-up</span>
                            <span class="rp-pill-teal"><?= e($currentRecord['follow_up_date'] ?: 'Not scheduled') ?></span>
                        </div>
                    </div>

                </div>

            <?php else: ?>

                <div class="rp-label">Clinical Assessment</div>

                <div class="rp-alert">
                    Consultation has not been recorded for this appointment.
                    Diagnosis, vital signs and prescription will appear after
                    the doctor saves the consultation.
                </div>

                <?php
                $canAddConsultation =
                    $user['role'] === 'doctor'
                    && !in_array(
                        $appointment['status'],
                        ['cancelled', 'no_show'],
                        true
                    );
                ?>

                <?php if ($canAddConsultation): ?>
                    <a
                        class="btn btn-sm btn-primary no-print mt-2"
                        href="<?= e(url(
                            'medical-records/create.php?appointment_id='
                            . (int)$appointment['id']
                        )) ?>"
                    >
                        Add Consultation
                    </a>
                <?php endif; ?>

            <?php endif; ?>

            <!-- Signature -->
            <div class="rp-foot">

                <div class="rp-contact">
                    <div class="rp-sign" style="text-align:left;min-width:180px">
                        <div class="rp-sign-line"></div>
                        <span>Patient / Clinic Copy</span>
                    </div>
                </div>

                <div class="rp-sign">
                    <div class="rp-sign-line"></div>
                    <strong><?= e($appointment['doctor_name']) ?></strong>
                    <span>Treating Doctor</span>
                </div>

            </div>

            <div class="rp-note">
                <span data-i18n="confidential_report_notice">Confidential medical information. Share only with authorized individuals.</span>
                · <span data-i18n="appointment_number_label">Appointment #</span><?= (int)$appointment['id'] ?>
                · <span data-i18n="patient_id">Patient</span> <?= e($appointment['patient_number']) ?>
            </div>

        </div>

        <!-- Bottom bar -->
        <div class="rp-bar bottom"><span></span><span></span></div>

    </div>
</div>

<?php require __DIR__.'/../includes/footer.php'; ?>