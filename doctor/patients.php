
<?php
require_once __DIR__ . '/../includes/functions.php';

require_role(['doctor']);

$user = current_user();
$db = db();

$doctor = doctor_for_user($user['id']);

if (!$doctor) {
    http_response_code(403);
    exit('Doctor profile is not linked to this account.');
}

$doctorId = (int) $doctor['id'];

/*
|--------------------------------------------------------------------------
| Doctor information
|--------------------------------------------------------------------------
*/
$doctorStmt = $db->prepare("
    SELECT u.name, d.specialization, d.qualification
    FROM doctors d
    JOIN users u ON u.id = d.user_id
    WHERE d.id = ?
    LIMIT 1
");
$doctorStmt->execute([$doctorId]);
$doctorInfo = $doctorStmt->fetch();

if (!$doctorInfo) {
    exit('Doctor not found.');
}

/*
|--------------------------------------------------------------------------
| Patient statistics
|--------------------------------------------------------------------------
*/
$statsStmt = $db->prepare("
    SELECT
        COUNT(*) AS total_patients,
        COALESCE(SUM(patient_visits.total_visits), 0) AS total_visits
    FROM patients p
    LEFT JOIN (
        SELECT patient_id, COUNT(*) AS total_visits
        FROM appointments
        WHERE doctor_id = ?
          AND status NOT IN ('cancelled', 'no_show')
        GROUP BY patient_id
    ) patient_visits ON patient_visits.patient_id = p.id
    WHERE p.assigned_doctor_id = ?
       OR EXISTS (
            SELECT 1
            FROM appointments a
            WHERE a.patient_id = p.id
              AND a.doctor_id = ?
              AND a.status NOT IN ('cancelled', 'no_show')
       )
");

$statsStmt->execute([$doctorId, $doctorId, $doctorId]);
$stats = $statsStmt->fetch();

/*
|--------------------------------------------------------------------------
| Patient list
|--------------------------------------------------------------------------
*/
$patientStmt = $db->prepare("
    SELECT
        p.id,
        p.patient_number,
        COALESCE(NULLIF(p.full_name, ''), u.name, 'Patient') AS patient_name,
        COALESCE(NULLIF(p.phone, ''), u.phone, '') AS phone,
        COALESCE(NULLIF(p.email, ''), u.email, '') AS email,
        p.date_of_birth,
        p.gender,
        p.blood_group,
        p.created_at,
        COALESCE(visits.total_visits, 0) AS total_visits,
        visits.last_visit
    FROM patients p
    LEFT JOIN users u ON u.id = p.user_id
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
    ORDER BY
        COALESCE(visits.last_visit, p.created_at) DESC,
        p.id DESC
");

$patientStmt->execute([$doctorId, $doctorId, $doctorId]);
$patients = $patientStmt->fetchAll();

$pageTitle = 'My Patients';

require __DIR__ . '/../includes/header.php';
?>

<style>
.patient-register {
    padding: 24px 0;
}

.patient-register .register-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 24px;
}

.patient-register h2 {
    font-weight: 700;
    margin: 0 0 5px;
}

.patient-register .muted {
    color: #78818d;
    font-size: 13px;
}

.register-stats {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}

.register-stat {
    padding: 22px;
    border: 1px solid #e8ecef;
    background: #fff;
    border-radius: 13px;
}

.register-stat span {
    display: block;
    color: #77818d;
    font-size: 13px;
    margin-bottom: 9px;
}

.register-stat strong {
    font-size: 28px;
    color: #172033;
}

.register-panel {
    background: #fff;
    border: 1px solid #e8ecef;
    border-radius: 13px;
    padding: 20px;
}

.register-table th {
    color: #657080;
    background: #f7f9fb;
    font-size: 12px;
    text-transform: uppercase;
    white-space: nowrap;
}

.register-table td {
    vertical-align: middle;
    font-size: 13px;
}

.patient-name {
    font-weight: 650;
    color: #172033;
}

.patient-number {
    color: #78818d;
    font-size: 12px;
}

.register-actions {
    display: flex;
    gap: 9px;
    flex-wrap: wrap;
}

.pdf-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-width: 175px;
    border-radius: 9px;
    padding: 11px 17px;
    font-size: 13px;
    font-weight: 600;
    transition: all .25s ease;
}

.pdf-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 7px 18px rgba(13, 110, 253, .16);
}

.pdf-button:disabled {
    opacity: .75;
    cursor: wait;
    transform: none;
}

.pdf-spinner {
    display: none;
    width: 15px;
    height: 15px;
    border: 2px solid rgba(255,255,255,.4);
    border-top-color: #fff;
    border-radius: 50%;
    animation: pdfSpin .7s linear infinite;
}

.pdf-button.loading .pdf-spinner {
    display: inline-block;
}

.pdf-button.loading .pdf-icon {
    display: none;
}

@keyframes pdfSpin {
    to {
        transform: rotate(360deg);
    }
}

@media(max-width: 600px) {
    .register-stats {
        grid-template-columns: 1fr;
    }

    .register-panel {
        padding: 12px;
    }

    .pdf-button {
        width: 100%;
    }
}

@media(prefers-reduced-motion: reduce) {
    .pdf-button {
        transition: none;
    }

    .pdf-spinner {
        animation: none;
    }
}
</style>

<div class="container patient-register">

    <div class="register-heading">
        <div>
            <h2>My Patient Register</h2>
            <div class="muted">
                Your complete patient history and records
            </div>
        </div>

        <div class="register-actions">

            <button
                type="button"
                id="downloadPatientPdf"
                class="btn btn-primary pdf-button"
                onclick="openPatientPdf(this)"
            >
                <span class="pdf-icon">
                    <i class="bi bi-file-earmark-pdf"></i>
                </span>

                <span class="pdf-spinner"></span>

                <span class="pdf-button-text">
                    Download Patient PDF
                </span>
            </button>

        </div>
    </div>

    <div class="mb-3">
        <strong>Dr. <?= e($doctorInfo['name']) ?></strong>

        <div class="muted">
            <?= e($doctorInfo['specialization']) ?>
            · <?= e($doctorInfo['qualification']) ?>
        </div>
    </div>

    <div class="register-stats">

        <div class="register-stat">
            <span>Total Patients</span>
            <strong><?= count($patients) ?></strong>
        </div>

        <div class="register-stat">
            <span>Total Visits</span>
            <strong><?= (int)($stats['total_visits'] ?? 0) ?></strong>
        </div>

    </div>

    <div class="register-panel">

        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">

            <h5 class="mb-0">Patient List</h5>

            <span class="muted">
                <?= count($patients) ?> patients found
            </span>

        </div>

        <div class="table-responsive">

            <table class="table table-hover register-table">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Patient</th>
                        <th>Patient ID</th>
                        <th>Phone</th>
                        <th>Age</th>
                        <th>Gender</th>
                        <th>Blood Group</th>
                        <th>Total Visits</th>
                        <th>Last Visit</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($patients): ?>

                    <?php foreach ($patients as $index => $patient): ?>

                        <?php
                        $age = '—';

                        if (!empty($patient['date_of_birth'])) {
                            $birthDate = new DateTime($patient['date_of_birth']);
                            $today = new DateTime();
                            $age = $birthDate->diff($today)->y . ' years';
                        }
                        ?>

                        <tr>
                            <td><?= $index + 1 ?></td>

                            <td>
                                <div class="patient-name">
                                    <?= e($patient['patient_name']) ?>
                                </div>

                                <div class="patient-number">
                                    <?= e($patient['email'] ?: 'No email') ?>
                                </div>
                            </td>

                            <td><?= e($patient['patient_number']) ?></td>

                            <td><?= e($patient['phone'] ?: '—') ?></td>

                            <td><?= e($age) ?></td>

                            <td><?= e($patient['gender']) ?></td>

                            <td><?= e($patient['blood_group'] ?: '—') ?></td>

                            <td>
                                <span class="badge bg-primary">
                                    <?= (int)$patient['total_visits'] ?>
                                </span>
                            </td>

                            <td>
                                <?= e($patient['last_visit'] ?: 'No visit yet') ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="9" class="text-center py-4">
                            No patients found for your account.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<script>
function openPatientPdf(button) {

    if (button.disabled) {
        return;
    }

    const originalText = 'Download Patient PDF';

    button.disabled = true;
    button.classList.add('loading');

    button.querySelector('.pdf-button-text').textContent = 'Opening PDF...';

    const pdfUrl = <?= json_encode(url('doctor/patients-pdf.php')) ?>;

    // Navigate in the same tab to avoid popup/new-tab flicker.
    window.location.assign(pdfUrl);

    // If navigation is delayed or fails, restore the button.
    window.setTimeout(function () {
        if (document.visibilityState === 'visible') {
            button.disabled = false;
            button.classList.remove('loading');
            button.querySelector('.pdf-button-text').textContent = originalText;
        }
    }, 8000);
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
