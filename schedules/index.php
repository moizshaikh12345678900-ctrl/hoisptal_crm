<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_role(['super_admin', 'admin', 'doctor']);
verify_csrf();

$db = db();
$user = current_user();
$doctorSelf = $user['role'] === 'doctor' ? doctor_for_user((int)$user['id']) : null;
if ($user['role'] === 'doctor' && !$doctorSelf) {
    http_response_code(403);
    exit('Doctor profile is not linked to this account.');
}

$weekdays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_string('action', 20);
    try {
        if ($action === 'save') {
            $scheduleId = (int)($_POST['schedule_id'] ?? 0);
            $doctorId = $user['role'] === 'doctor' ? (int)$doctorSelf['id'] : (int)($_POST['doctor_id'] ?? 0);
            $weekday = post_string('weekday', 20);
            $start = post_string('start_time', 8);
            $end = post_string('end_time', 8);
            $slot = (int)($_POST['slot_minutes'] ?? 30);

            if ($doctorId < 1 || !in_array($weekday, $weekdays, true) ||
                !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) ||
                !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end) ||
                strtotime($end) <= strtotime($start) || $slot < 5 || $slot > 240) {
                throw new RuntimeException('Enter a valid doctor, weekday, time range and slot duration.');
            }

            $doctorCheck = $db->prepare('SELECT id FROM doctors WHERE id = ?');
            $doctorCheck->execute([$doctorId]);
            if (!$doctorCheck->fetchColumn()) {
                throw new RuntimeException('Selected doctor was not found.');
            }

            if ($scheduleId) {
                $owner = $db->prepare('SELECT doctor_id FROM doctor_schedules WHERE id = ?');
                $owner->execute([$scheduleId]);
                $existingDoctor = (int)$owner->fetchColumn();
                if (!$existingDoctor || $existingDoctor !== $doctorId) {
                    throw new RuntimeException('You cannot edit this schedule.');
                }
                $stmt = $db->prepare('UPDATE doctor_schedules SET weekday=?, start_time=?, end_time=?, slot_minutes=? WHERE id=?');
                $stmt->execute([$weekday, $start . ':00', $end . ':00', $slot, $scheduleId]);
                audit((int)$user['id'], 'DOCTOR_SCHEDULE_UPDATED', 'doctor_schedules', (string)$scheduleId, $weekday);
            } else {
                $dupe = $db->prepare('SELECT id FROM doctor_schedules WHERE doctor_id=? AND weekday=? AND start_time=? AND end_time=? LIMIT 1');
                $dupe->execute([$doctorId, $weekday, $start . ':00', $end . ':00']);
                if ($dupe->fetchColumn()) {
                    throw new RuntimeException('This schedule already exists.');
                }
                $stmt = $db->prepare('INSERT INTO doctor_schedules (doctor_id, weekday, start_time, end_time, slot_minutes) VALUES (?,?,?,?,?)');
                $stmt->execute([$doctorId, $weekday, $start . ':00', $end . ':00', $slot]);
                $scheduleId = (int)$db->lastInsertId();
                audit((int)$user['id'], 'DOCTOR_SCHEDULE_CREATED', 'doctor_schedules', (string)$scheduleId, $weekday);
            }
            flash('success', 'Doctor schedule saved.');
            redirect('schedules/index.php');
        }

        if ($action === 'delete') {
            $scheduleId = (int)($_POST['schedule_id'] ?? 0);
            $stmt = $db->prepare('SELECT doctor_id FROM doctor_schedules WHERE id=?');
            $stmt->execute([$scheduleId]);
            $ownerId = (int)$stmt->fetchColumn();
            if (!$ownerId || ($user['role'] === 'doctor' && $ownerId !== (int)$doctorSelf['id'])) {
                throw new RuntimeException('You cannot delete this schedule.');
            }
            $db->prepare('DELETE FROM doctor_schedules WHERE id=?')->execute([$scheduleId]);
            audit((int)$user['id'], 'DOCTOR_SCHEDULE_DELETED', 'doctor_schedules', (string)$scheduleId);
            flash('success', 'Schedule removed.');
            redirect('schedules/index.php');
        }

        throw new RuntimeException('Invalid schedule action.');
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$doctors = [];
if ($user['role'] !== 'doctor') {
    $doctors = $db->query("SELECT d.id, u.name FROM doctors d JOIN users u ON u.id=d.user_id WHERE u.status='active' ORDER BY u.name")->fetchAll();
}
$sql = "SELECT ds.*, u.name AS doctor_name, d.specialization
        FROM doctor_schedules ds JOIN doctors d ON d.id=ds.doctor_id JOIN users u ON u.id=d.user_id";
$params = [];
if ($user['role'] === 'doctor') {
    $sql .= ' WHERE ds.doctor_id=?';
    $params[] = (int)$doctorSelf['id'];
}
$sql .= " ORDER BY u.name, FIELD(ds.weekday,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), ds.start_time";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Doctor schedules';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/page.php';
page_heading('Scheduling', 'Doctor schedules', 'Manage recurring weekly clinic hours and appointment slot lengths.');
?>
<?php if ($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card"><div class="card-header"><h2 class="section-title">Add weekly hours</h2></div><div class="card-body">
            <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save">
                <?php if ($user['role'] !== 'doctor'): ?>
                    <div class="mb-3"><label class="form-label">Doctor</label><select class="form-select" name="doctor_id" required><option value="">Select doctor</option><?php foreach ($doctors as $doctor): ?><option value="<?=(int)$doctor['id']?>"><?=e($doctor['name'])?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
                <div class="mb-3"><label class="form-label">Weekday</label><select class="form-select" name="weekday" required><?php foreach ($weekdays as $day): ?><option value="<?=e($day)?>"><?=e($day)?></option><?php endforeach; ?></select></div>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label">Start time</label><input class="form-control" type="time" name="start_time" value="09:00" required></div>
                    <div class="col-6"><label class="form-label">End time</label><input class="form-control" type="time" name="end_time" value="17:00" required></div>
                </div>
                <div class="mt-3 mb-3"><label class="form-label">Slot duration (minutes)</label><input class="form-control" type="number" name="slot_minutes" min="5" max="240" value="30" required></div>
                <button class="btn btn-primary">Save schedule</button>
            </form>
        </div></div>
        <div class="alert alert-info small mt-3 mb-0">These weekly hours are stored in the CRM. Appointment booking still uses the current booking-time selector; schedule-based slot enforcement is not enabled yet.</div>
    </div>
    <div class="col-lg-8">
        <div class="card"><div class="card-header"><h2 class="section-title">Weekly schedule register</h2></div>
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead><tr><?php if ($user['role'] !== 'doctor'): ?><th>Doctor</th><?php endif; ?><th>Day</th><th>Hours</th><th>Slot</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php if ($user['role'] !== 'doctor'): ?><td><strong><?=e($row['doctor_name'])?></strong><div class="small-muted"><?=e($row['specialization'])?></div></td><?php endif; ?>
                        <td><?=e($row['weekday'])?></td><td><?=e(substr($row['start_time'],0,5))?> – <?=e(substr($row['end_time'],0,5))?></td><td><?= (int)$row['slot_minutes']?> min</td>
                        <td><form method="post" data-confirm="Remove this weekly schedule?"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="schedule_id" value="<?=(int)$row['id']?>"><button class="btn btn-sm btn-outline-danger">Remove</button></form></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><?=empty_row($user['role']==='doctor'?4:5,'No weekly schedules configured.')?><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
