
<?php
require_once __DIR__.'/../includes/functions.php';

require_role(['super_admin', 'admin', 'doctor']);

$u = current_user();
$db = db();

$sql = "SELECT mr.*,
        COALESCE(NULLIF(p.full_name,''), pu.name, 'Patient') AS patient_name,
        du.name AS doctor_name,
        p.patient_number
        FROM medical_records mr
        JOIN patients p ON p.id = mr.patient_id
        LEFT JOIN users pu ON pu.id = p.user_id
        JOIN doctors d ON d.id = mr.doctor_id
        JOIN users du ON du.id = d.user_id";

$params = [];

if ($u['role'] === 'doctor') {
    $d = doctor_for_user($u['id']);

    $sql .= ' WHERE mr.doctor_id = ?';
    $params[] = (int)($d['id'] ?? 0);
}

$sql .= ' ORDER BY mr.created_at DESC LIMIT 250';

$s = $db->prepare($sql);
$s->execute($params);
$rows = $s->fetchAll();

$pageTitle = 'Medical records';

require __DIR__.'/../includes/header.php';
require __DIR__.'/../includes/page.php';

page_heading(
    'Clinical care',
    'Medical records',
    'Diagnosis and consultation notes are restricted clinical information'
);
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h2 class="section-title mb-0">Consultation history</h2>

        <span class="badge bg-light text-dark">
            Total records: <?= count($rows) ?>
        </span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Patient</th>
                    <th>Patient ID</th>
                    <th>Doctor</th>
                    <th>Diagnosis</th>
                    <th>Consultation date</th>
                    <th>Follow-up</th>
                    <th>Report</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <strong data-no-i18n><?= e($r['patient_name']) ?></strong>
                        </td>

                        <td>
                            <code data-no-i18n><?= e($r['patient_number'] ?? '—') ?></code>
                        </td>

                        <td>
                            <span data-no-i18n><?= e($r['doctor_name']) ?></span>
                        </td>

                        <td data-no-i18n>
                            <?= e(mb_strimwidth(
                                $r['diagnosis'] ?? '',
                                0,
                                80,
                                '…'
                            )) ?>
                        </td>

                        <td>
                            <?= e(substr($r['created_at'] ?? '', 0, 10)) ?>
                        </td>

                        <td>
                            <?= e($r['follow_up_date'] ?: '—') ?>
                        </td>

                        <td>
                            <div class="d-flex gap-2 flex-wrap">
                                <a
                                    href="<?= e(url(
                                        'appointments/details.php?id='
                                        . (int)($r['appointment_id'] ?? 0)
                                    )) ?>"
                                    class="btn btn-sm btn-outline-secondary"
                                >
                                    <i class="bi bi-eye me-1"></i>
                                    View Report
                                </a>

                                
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$rows): ?>
                    <?= empty_row(7, 'No medical records found.') ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__.'/../includes/footer.php'; ?>
