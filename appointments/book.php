<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_role(['super_admin', 'admin', 'staff', 'patient']);
verify_csrf();

$db = db();
$user = current_user();
$patientSelf = null;
if ($user['role'] === 'patient') {
    $patientSelf = patient_for_user((int)$user['id']);
    if (!$patientSelf) {
        flash('danger', 'Patient profile is missing. Contact the clinic.');
        redirect('patient/dashboard.php');
    }
}

$error = '';
$patients = $db->query("
    SELECT p.id, COALESCE(NULLIF(p.full_name,''), u.name, 'Walk-in') AS name, p.patient_number
    FROM patients p LEFT JOIN users u ON u.id=p.user_id ORDER BY name
")->fetchAll();
$doctors = $db->query("
    SELECT d.id, u.name, d.specialization, d.fee, dep.name AS department_name
    FROM doctors d JOIN users u ON u.id=d.user_id
    JOIN departments dep ON dep.id=d.department_id
    WHERE u.status='active' ORDER BY u.name
")->fetchAll();
$scheduleRows = $db->query("
    SELECT doctor_id, weekday, start_time, end_time, slot_minutes
    FROM doctor_schedules ORDER BY doctor_id, weekday, start_time
")->fetchAll();
$scheduleMap = [];
foreach ($scheduleRows as $schedule) {
    $scheduleMap[(string)$schedule['doctor_id']][$schedule['weekday']][] = [
        'start' => substr($schedule['start_time'], 0, 5),
        'end' => substr($schedule['end_time'], 0, 5),
        'slot' => (int)$schedule['slot_minutes']
    ];
}
$allDoctorScheduleIds = [];
foreach ($scheduleMap as $doctorId => $week) {
    $allDoctorScheduleIds[] = (int)$doctorId;
}
$hasAnySchedules = array_fill_keys($allDoctorScheduleIds, true);
$defaultSlotMinutes = max(5, min(240, (int)setting('appointment_slot_minutes', '30')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patientId = $user['role'] === 'patient' ? (int)$patientSelf['id'] : (int)($_POST['patient_id'] ?? 0);
    $doctorId = (int)($_POST['doctor_id'] ?? 0);
    $date = post_string('appointment_date', 10);
    $start = post_string('start_at', 5);
    $reason = post_string('reason', 2000);
    $dateParts = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $dateMatch) ? $dateMatch : null;
    $validDate = $dateParts !== null && checkdate((int)$dateParts[2], (int)$dateParts[3], (int)$dateParts[1]) && $date >= date('Y-m-d');
    $validTime = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start);

    if (!$patientId || !$doctorId || !$validDate || !$validTime) {
        $error = 'Choose a patient, doctor, future date and valid time.';
    } else {
        try {
            $db->beginTransaction();

            $patientCheck = $db->prepare('SELECT id FROM patients WHERE id=? FOR UPDATE');
            $patientCheck->execute([$patientId]);
            if (!$patientCheck->fetch()) {
                throw new RuntimeException('Selected patient was not found.');
            }

            $doctorCheck = $db->prepare("
                SELECT d.id, u.name
                FROM doctors d JOIN users u ON u.id=d.user_id
                WHERE d.id=? AND u.status='active' FOR UPDATE
            ");
            $doctorCheck->execute([$doctorId]);
            $selectedDoctor = $doctorCheck->fetch();
            if (!$selectedDoctor) {
                throw new RuntimeException('Selected doctor is unavailable.');
            }

            $weekday = date('l', strtotime($date));
            $scheduleStmt = $db->prepare("
                SELECT start_time, end_time, slot_minutes
                FROM doctor_schedules
                WHERE doctor_id=? AND weekday=?
                ORDER BY start_time
            ");
            $scheduleStmt->execute([$doctorId, $weekday]);
            $daySchedules = $scheduleStmt->fetchAll();

            $countStmt = $db->prepare('SELECT COUNT(*) FROM doctor_schedules WHERE doctor_id=?');
            $countStmt->execute([$doctorId]);
            $doctorHasSchedules = (int)$countStmt->fetchColumn() > 0;

            $slotMinutes = $defaultSlotMinutes;
            $validScheduleSlot = false;
            $startTimestamp = strtotime($date . ' ' . $start . ':00');
            if ($date === date('Y-m-d') && $startTimestamp <= time()) {
                throw new RuntimeException('Choose a future time for today.');
            }

            if (!$doctorHasSchedules) {
                $fallbackStart = strtotime($date . ' 08:00:00');
                $fallbackEnd = strtotime($date . ' 18:00:00');
                $endTimestamp = $startTimestamp + 30 * 60;
                $validScheduleSlot = $startTimestamp >= $fallbackStart &&
                    $endTimestamp <= $fallbackEnd &&
                    (((int)(($startTimestamp - $fallbackStart) / 60)) % $defaultSlotMinutes === 0);
            } else {
                foreach ($daySchedules as $daySchedule) {
                    $scheduleStart = strtotime($date . ' ' . $daySchedule['start_time']);
                    $scheduleEnd = strtotime($date . ' ' . $daySchedule['end_time']);
                    $slotMinutes = max(5, (int)$daySchedule['slot_minutes']);
                    $endTimestamp = $startTimestamp + $slotMinutes * 60;
                    $offsetMinutes = (int)(($startTimestamp - $scheduleStart) / 60);
                    if ($startTimestamp >= $scheduleStart &&
                        $endTimestamp <= $scheduleEnd &&
                        $offsetMinutes >= 0 &&
                        $offsetMinutes % $slotMinutes === 0) {
                        $validScheduleSlot = true;
                        break;
                    }
                }
            }

            if (!$validScheduleSlot) {
                throw new RuntimeException('This time is outside the doctor’s configured hours or does not match the appointment slot length.');
            }

            $duration = $doctorHasSchedules ? $slotMinutes : $defaultSlotMinutes;
            $startFull = $start . ':00';
            $endFull = date('H:i:s', $startTimestamp + $duration * 60);

            $overlap = $db->prepare("
                SELECT id FROM appointments
                WHERE (doctor_id=? OR patient_id=?)
                  AND appointment_date=?
                  AND status NOT IN ('cancelled','no_show')
                  AND start_at < ? AND end_at > ?
                LIMIT 1 FOR UPDATE
            ");
            $overlap->execute([$doctorId, $patientId, $date, $endFull, $startFull]);
            if ($overlap->fetch()) {
                throw new RuntimeException('This doctor or patient already has an overlapping appointment. Choose another slot.');
            }

            $tokenStmt = $db->prepare('SELECT COALESCE(MAX(token_number),0)+1 FROM appointments WHERE appointment_date=? AND doctor_id=?');
            $tokenStmt->execute([$date, $doctorId]);
            $token = (int)$tokenStmt->fetchColumn();

            $status = $user['role'] === 'patient' ? 'requested' : 'confirmed';
            $insert = $db->prepare("
                INSERT INTO appointments
                    (token_number, patient_id, doctor_id, appointment_date, start_at, end_at, status, reason, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([$token, $patientId, $doctorId, $date, $startFull, $endFull, $status, $reason, $user['role']]);
            $appointmentId = (int)$db->lastInsertId();

            $db->commit();
            audit((int)$user['id'], 'APPOINTMENT_CREATED', 'appointments', (string)$appointmentId, 'Status: ' . $status);
            flash('success', $user['role'] === 'patient'
                ? 'Appointment request submitted. The clinic will confirm it.'
                : 'Appointment booked and assigned to Dr. ' . $selectedDoctor['name'] . '.');
            redirect('appointments/index.php');
        } catch (Throwable $ex) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = $ex->getMessage();
        }
    }
}

$pageTitle = 'Book appointment';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/page.php';
page_heading('Scheduling', 'Book an appointment', 'Available times follow the doctor’s configured weekly schedule.');
?>
<?php if ($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card"><div class="card-body p-4">
            <form method="post" data-disable-submit><?=csrf_field()?>
                <?php if ($user['role'] !== 'patient'): ?>
                    <div class="mb-3"><label class="form-label">Patient *</label><select class="form-select" name="patient_id" required><option value="">Select patient</option><?php foreach ($patients as $patient): ?><option value="<?=(int)$patient['id']?>" <?=((string)($_POST['patient_id']??'')===(string)$patient['id'])?'selected':''?>><?=e($patient['name'].' · '.$patient['patient_number'])?></option><?php endforeach; ?></select></div>
                <?php else: ?>
                    <div class="alert alert-light border">Booking for <strong><?=e($patientSelf['full_name'] ?: $user['name'])?></strong> · <?=e($patientSelf['patient_number'])?></div>
                <?php endif; ?>
                <div class="mb-3"><label class="form-label">Doctor *</label><select class="form-select" id="doctorSelect" name="doctor_id" required><option value="">Choose a doctor</option><?php foreach ($doctors as $doctor): ?><option value="<?=(int)$doctor['id']?>" <?=((string)($_POST['doctor_id']??'')===(string)$doctor['id'])?'selected':''?>><?=e($doctor['name'].' — '.$doctor['specialization'].' / '.$doctor['department_name'])?> · <?=money($doctor['fee'])?></option><?php endforeach; ?></select></div>
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label">Date *</label><input class="form-control" id="appointmentDate" type="date" name="appointment_date" min="<?=date('Y-m-d')?>" value="<?=e($_POST['appointment_date']??'')?>" required></div>
                    <div class="col-md-6 mb-3"><label class="form-label">Start time *</label><select class="form-select" id="timeSelect" name="start_at" required><option value="">Choose doctor and date first</option></select></div>
                </div>
                <div class="mb-4"><label class="form-label">Reason for visit</label><textarea class="form-control" name="reason" rows="3" maxlength="2000" placeholder="Briefly describe the reason for consultation"><?=e($_POST['reason']??'')?></textarea></div>
                <button class="btn btn-primary">Submit appointment</button>
                <a class="btn btn-light" href="<?=e(url('appointments/index.php'))?>">Cancel</a>
            </form>
        </div></div>
    </div>
    <div class="col-lg-4"><div class="card"><div class="card-body">
        <div class="stat-icon mb-3"><i class="bi bi-calendar2-check"></i></div>
        <h2 class="h6 fw-bold">Booking information</h2>
        <ul class="text-secondary small ps-3 mb-0">
            <li class="mb-2">Available times are based on the selected doctor’s weekly schedule.</li>
            <li class="mb-2">The system prevents overlapping appointments for the same doctor or patient.</li>
            <li class="mb-2">Patient requests require clinic confirmation.</li>
            <li class="mb-2">Staff bookings are confirmed immediately.</li>
        </ul>
    </div></div></div>
</div>
<script>
(() => {
    const schedules = <?=json_encode($scheduleMap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
    const doctorsWithSchedules = <?=json_encode($hasAnySchedules)?>;
    const defaultSlot = <?=json_encode($defaultSlotMinutes)?>;
    const doctor = document.getElementById('doctorSelect');
    const date = document.getElementById('appointmentDate');
    const time = document.getElementById('timeSelect');
    const previous = <?=json_encode((string)($_POST['start_at']??''))?>;
    const weekdayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

    function refreshTimes() {
        const doctorId = doctor.value;
        const dateValue = date.value;
        time.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Choose time';
        time.appendChild(placeholder);
        if (!doctorId || !dateValue) {
            placeholder.textContent = 'Choose doctor and date first';
            return;
        }

        const day = weekdayNames[new Date(dateValue + 'T00:00:00').getDay()];
        let ranges = (schedules[doctorId] && schedules[doctorId][day]) || [];
        if (!doctorsWithSchedules[doctorId]) {
            ranges = [{start:'08:00', end:'18:00', slot:defaultSlot}];
        }

        ranges.forEach(range => {
            const [sh, sm] = range.start.split(':').map(Number);
            const [eh, em] = range.end.split(':').map(Number);
            let minutes = sh * 60 + sm;
            const endMinutes = eh * 60 + em;
            const slot = Math.max(5, Number(range.slot) || 30);
            while (minutes + slot <= endMinutes) {
                const value = String(Math.floor(minutes / 60)).padStart(2,'0') + ':' + String(minutes % 60).padStart(2,'0');
                const option = document.createElement('option');
                option.value = value;
                option.textContent = value;
                if (value === previous) option.selected = true;
                time.appendChild(option);
                minutes += slot;
            }
        });
        if (time.options.length === 1) {
            placeholder.textContent = 'No configured hours for this day';
        }
    }
    doctor.addEventListener('change', refreshTimes);
    date.addEventListener('change', refreshTimes);
    refreshTimes();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
