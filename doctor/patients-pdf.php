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

$doctorId = (int)$doctor['id'];

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
| Patient list for PDF
|--------------------------------------------------------------------------
*/
$stmt = $db->prepare("
    SELECT
        p.id,
        p.patient_number,
        COALESCE(NULLIF(p.full_name, ''), u.name, 'Patient') AS patient_name,
        COALESCE(NULLIF(p.phone, ''), u.phone, '') AS phone,
        p.date_of_birth,
        p.gender,
        p.blood_group,
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

$stmt->execute([$doctorId, $doctorId, $doctorId]);
$patients = $stmt->fetchAll();

$totalPatients = count($patients);

$totalVisits = 0;

foreach ($patients as $patient) {
    $totalVisits += (int)$patient['total_visits'];
}

/*
|--------------------------------------------------------------------------
| Clinic name
|--------------------------------------------------------------------------
*/
$clinicName = setting('clinic_name', 'Hospital & Clinic');
$logoLetter = strtoupper(substr(trim((string)$clinicName) ?: 'C', 0, 1));
$pageTitle = 'Doctor Patient Register';
require __DIR__ . '/../includes/header.php';

function pdf_escape($value) {
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>
<div class="patient-pdf">
    <style>
        .patient-pdf {
            --teal: #14c4b2;
            --teal-dark: #0a9d90;
            --navy: #101536;
            --ink: #1d2233;
            --muted: #6f7785;
            --line: #3b4152;
        }

        .patient-pdf *,
        .patient-pdf *::before,
        .patient-pdf *::after {
            box-sizing: border-box;
        }

        .patient-pdf {
            color: var(--ink);
            background: #eef1f4;
            font-family: Roboto, Arial, Helvetica, sans-serif;
            font-size: 14px;
            line-height: 1.4;
            padding: 25px;
        }

        /* ---------- Toolbar ---------- */
        .patient-pdf .toolbar {
            max-width: 1100px;
            margin: 0 auto 15px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .patient-pdf .toolbar button,
        .patient-pdf .toolbar a {
            border: 0;
            border-radius: 20px;
            padding: 10px 20px;
            text-decoration: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
        }

        .patient-pdf .print-btn { background: var(--teal-dark); color: #fff; }
        .patient-pdf .back-btn { background: #e1e5e9; color: var(--navy); }

        /* ---------- Paper ---------- */
        .patient-pdf .report {
            position: relative;
            max-width: 1100px;
            margin: auto;
            overflow: hidden;
            background: #fff;
            border: 1px solid #e3e8e8;
            box-shadow: 0 10px 30px rgba(16, 21, 54, .10);
        }

        .patient-pdf .bar { display: flex; gap: 16px; height: 28px; }
        .patient-pdf .bar span { display: block; background: var(--teal); }
        .patient-pdf .bar span:first-child { flex: 0 0 48%; }
        .patient-pdf .bar span:last-child { flex: 1; border-radius: 24px 0 0 24px; }
        .patient-pdf .bar.bottom { height: 22px; margin-top: 26px; }
        .patient-pdf .bar.bottom span:first-child { flex: 1; }
        .patient-pdf .bar.bottom span:last-child { display: none; }

        .patient-pdf .body { padding: 28px 38px 0; }

        /* ---------- Header ---------- */
        .patient-pdf .head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
        }

        .patient-pdf .title {
            margin: 0 0 6px;
            color: var(--teal-dark);
            font-size: 30px;
            font-weight: 900;
            letter-spacing: 5px;
            line-height: 1.1;
            text-transform: uppercase;
        }

        .patient-pdf .mini { font-size: 12px; line-height: 1.6; }
        .patient-pdf .mini b { display: inline-block; min-width: 82px; color: var(--muted); font-weight: 400; }
        .patient-pdf .mini strong { color: var(--navy); }

        .patient-pdf .brand { display: flex; align-items: center; gap: 12px; }
        .patient-pdf .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 48px;
            width: 48px;
            height: 48px;
            color: var(--teal);
            border: 4px solid var(--teal);
            border-radius: 50%;
            font-size: 25px;
            font-weight: 900;
        }
        .patient-pdf .brand-name {
            color: var(--teal-dark);
            font-size: 22px;
            font-weight: 800;
            line-height: 1.05;
            text-transform: uppercase;
        }
        .patient-pdf .brand-sub {
            margin-top: 4px;
            color: var(--muted);
            font-size: 9px;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        /* ---------- Doctor + summary ---------- */
        .patient-pdf .info {
            display: flex;
            justify-content: space-between;
            gap: 30px;
            margin-top: 28px;
        }

        .patient-pdf .to { font-size: 14px; font-weight: 500; color: var(--navy); }
        .patient-pdf .doc-name {
            margin: 2px 0 6px;
            color: var(--teal);
            font-size: 23px;
            font-weight: 800;
            line-height: 1.15;
        }

        .patient-pdf .list { margin: 0; padding: 0; list-style: none; font-size: 12px; line-height: 1.7; }
        .patient-pdf .list li { display: flex; gap: 6px; }
        .patient-pdf .list .k { flex: 0 0 110px; color: var(--navy); font-weight: 700; text-transform: uppercase; }
        .patient-pdf .list .k::after { content: ":"; float: right; padding-right: 4px; }

        .patient-pdf .summary { min-width: 300px; }
        .patient-pdf .h-teal { margin: 0 0 8px; color: var(--teal); font-size: 15px; font-weight: 800; text-align: right; }
        .patient-pdf .sum-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }
        .patient-pdf .sum-row span {
            flex: 1;
            padding: 8px 12px;
            color: #fff;
            background: var(--navy);
            border-radius: 26px;
            font-size: 14px;
            font-weight: 700;
            text-align: center;
        }
        .patient-pdf .sum-row span:last-child { background: var(--teal); font-size: 16px; font-weight: 800; }

        /* ---------- Table ---------- */
        .patient-pdf .label { margin: 26px 0 10px; color: var(--teal); font-size: 15px; font-weight: 800; }

        .patient-pdf table {
            width: calc(100% + 12px);
            margin: 0 -6px;
            border-collapse: separate;
            border-spacing: 6px 0;
            font-size: 13px;
        }

        .patient-pdf th {
            padding: 9px 8px;
            color: #fff;
            background: var(--navy);
            border-radius: 26px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            white-space: nowrap;
        }
        .patient-pdf th:nth-child(even) { background: var(--teal); }

        .patient-pdf td {
            padding: 10px 8px;
            border-bottom: 1.5px solid var(--line);
            text-align: center;
            vertical-align: middle;
        }
        .patient-pdf td.left { text-align: left; font-weight: 600; }

        /* ---------- Footer ---------- */
        .patient-pdf .thanks { margin-top: 18px; color: var(--teal); font-size: 15px; font-weight: 800; }
        .patient-pdf .foot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-top: 16px;
            padding-top: 8px;
            color: var(--muted);
            border-top: 1px solid #e1e5e6;
            font-size: 11px;
        }

        /* ---------- Mobile ---------- */
        @media screen and (max-width: 767px) {
            .patient-pdf { padding: 10px; }
            .patient-pdf .body { padding: 20px 14px 0; }
            .patient-pdf .head, .patient-pdf .info { flex-direction: column; }
            .patient-pdf .summary { min-width: 0; width: 100%; }
            .patient-pdf .h-teal { text-align: left; }
            .patient-pdf .title { font-size: 23px; letter-spacing: 3px; }
            .patient-pdf .table-wrap { overflow-x: auto; }
            .patient-pdf table { min-width: 760px; }
            .patient-pdf .foot { flex-direction: column; align-items: flex-start; }
        }

        /* ---------- Print ---------- */
        @media print {
            @page {
                size: A4 landscape;
                margin: 8mm;
            }

            body {
                background: #fff;
                margin: 0;
                font-size: 11px;
            }

            .app-topbar,
            .app-sidebar,
            .app-footer,
            .patient-pdf .toolbar { display: none !important; }

            .app-shell { display: block; min-height: 0; }
            .app-main { display: block; padding: 0; }
            .content-wrap { max-width: none; margin: 0; }
            .patient-pdf { padding: 0; background: #fff; }

            .patient-pdf .report {
                max-width: none;
                border: 0;
                box-shadow: none;
            }

            .patient-pdf .body { padding: 18px 26px 0; }
            .patient-pdf .title { font-size: 24px; }
            .patient-pdf .doc-name { font-size: 19px; }
            .patient-pdf .brand-name { font-size: 18px; }
            .patient-pdf table { font-size: 10.5px; }
            .patient-pdf th { font-size: 10.5px; padding: 6px 6px; }
            .patient-pdf td { padding: 6px 6px; }

            .patient-pdf thead { display: table-header-group; }
            .patient-pdf tr { page-break-inside: avoid; break-inside: avoid; }

            .patient-pdf,
            .patient-pdf * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>

    <div class="toolbar">
        <a href="<?= pdf_escape(url('doctor/patients.php')) ?>" class="back-btn">
            Back to Patients
        </a>

        <button class="print-btn" onclick="window.print()">
            Print / Save PDF
        </button>
    </div>

    <section class="report">

        <div class="bar"><span></span><span></span></div>

        <div class="body">

            <!-- Header -->
            <header class="head">

                <div>
                    <h1 class="title">Patient Register</h1>
                    <div class="mini">
                        <div><b>Report Date:</b> <strong><?= date('m/d/Y') ?></strong></div>
                        <div><b>Report Time:</b> <strong><?= date('h:i A') ?></strong></div>
                    </div>
                </div>

                <div class="brand">
                    <div class="logo"><?= pdf_escape($logoLetter) ?></div>
                    <div>
                        <div class="brand-name"><?= pdf_escape($clinicName) ?></div>
                        <div class="brand-sub">Doctor patient register</div>
                    </div>
                </div>

            </header>

            <!-- Doctor + summary -->
            <section class="info">

                <div>
                    <div class="to">DOCTOR:</div>
                    <div class="doc-name"><?= pdf_escape($doctorInfo['name']) ?></div>

                    <ul class="list">
                        <li>
                            <span class="k">Specialization</span>
                            <span><?= pdf_escape($doctorInfo['specialization'] ?: '—') ?></span>
                        </li>
                        <li>
                            <span class="k">Qualification</span>
                            <span><?= pdf_escape($doctorInfo['qualification'] ?: '—') ?></span>
                        </li>
                    </ul>
                </div>

                <div class="summary">
                    <div class="h-teal">Summary</div>

                    <div class="sum-row">
                        <span>Total Patients</span>
                        <span><?= $totalPatients ?></span>
                    </div>

                    <div class="sum-row">
                        <span>Total Visits</span>
                        <span><?= $totalVisits ?></span>
                    </div>
                </div>

            </section>

            <!-- Patient list -->
            <div class="label">Complete Patient List</div>

            <div class="table-wrap">
                <table>

                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Patient ID</th>
                            <th>Patient Name</th>
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
                                $age = $birthDate->diff($today)->y;
                            }
                            ?>

                            <tr>
                                <td><?= $index + 1 ?></td>

                                <td><?= pdf_escape($patient['patient_number']) ?></td>

                                <td class="left"><?= pdf_escape($patient['patient_name']) ?></td>

                                <td><?= pdf_escape($patient['phone'] ?: '—') ?></td>

                                <td><?= pdf_escape($age) ?></td>

                                <td><?= pdf_escape($patient['gender']) ?></td>

                                <td><?= pdf_escape($patient['blood_group'] ?: '—') ?></td>

                                <td><?= (int)$patient['total_visits'] ?></td>

                                <td><?= pdf_escape($patient['last_visit'] ?: 'No visit') ?></td>
                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="9">No patients found.</td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>
            </div>

            <div class="thanks">Thank You For Your Trust!</div>

            <footer class="foot">
                <span>Confidential Patient Register</span>
                <span>Generated on <?= date('d M Y h:i A') ?></span>
            </footer>

        </div>

        <div class="bar bottom"><span></span><span></span></div>

    </section>

</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>