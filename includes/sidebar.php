
<?php

$role = $user['role'] ?? 'visitor';

$nav = [

    'super_admin' => [
        ['super-admin/dashboard.php', 'speedometer2', 'Overview'],
        ['users/index.php', 'people', 'User & roles'],
        ['doctors/index.php', 'person-badge', 'Doctors'],
        ['departments/index.php', 'building', 'Departments'],
        ['patients/index.php', 'people-fill', 'Patients'],
        ['appointments/index.php', 'calendar-check', 'Appointments'],
        ['schedules/index.php', 'clock-history', 'Doctor schedules'],
        ['billing/index.php', 'receipt', 'Billing'],
        ['reports/index.php', 'graph-up-arrow', 'Reports'],
        ['reports/inquiries.php', 'envelope', 'Inquiries'],
        ['reports/audit-logs.php', 'journal-text', 'Audit logs'],
        ['settings/index.php', 'sliders', 'Settings'],
    ],

    'admin' => [
        ['admin/dashboard.php', 'speedometer2', 'Overview'],
        ['users/index.php', 'people', 'User accounts'],
        ['doctors/index.php', 'person-badge', 'Doctors'],
        ['departments/index.php', 'building', 'Departments'],
        ['patients/index.php', 'people-fill', 'Patients'],
        ['appointments/index.php', 'calendar-check', 'Appointments'],
        ['schedules/index.php', 'clock-history', 'Doctor schedules'],
        ['medical-records/index.php', 'file-medical', 'Medical records'],
        ['prescriptions/index.php', 'capsule', 'Prescriptions'],
        ['billing/index.php', 'receipt', 'Billing'],
        ['reports/index.php', 'graph-up-arrow', 'Reports'],
        ['reports/inquiries.php', 'envelope', 'Inquiries'],
    ],

    'doctor' => [
        ['doctor/dashboard.php', 'calendar-week', 'My schedule'],
        ['doctor/patients.php', 'people-fill', 'My Patients'],
        ['schedules/index.php', 'clock-history', 'Clinic hours'],
        ['medical-records/index.php', 'file-medical', 'Medical records'],
        ['prescriptions/index.php', 'capsule', 'Prescriptions'],
        ['doctor/invoices.php', 'receipt', 'My invoices'],
    ],

    'staff' => [
        ['staff/dashboard.php', 'inbox', 'Reception queue'],
        ['patients/index.php', 'people-fill', 'Patients'],
        ['patients/register.php', 'person-plus', 'Register patient'],
        ['appointments/index.php', 'calendar-check', 'Appointments'],
        ['appointments/book.php', 'calendar-plus', 'Book appointment'],
        ['billing/index.php', 'cash-coin', 'Billing'],
    ],

    'patient' => [
        ['patient/dashboard.php', 'house-heart', 'My portal'],
        ['appointments/book.php', 'calendar-plus', 'Book appointment'],
        ['patient/records.php', 'file-medical', 'My records'],
        ['patient/invoices.php', 'receipt', 'My invoices'],
    ],

];

$items = $nav[$role] ?? [];

$current = str_replace(
    BASE_URL,
    '',
    $_SERVER['SCRIPT_NAME'] ?? ''
);

?>

<nav class="nav flex-column sidebar-nav">

    <?php foreach ($items as $it): ?>

        <a
            class="nav-link <?= str_ends_with($current, '/' . $it[0]) ? 'active' : '' ?>"
            href="<?= e(url($it[0])) ?>"
        >
            <i class="bi bi-<?= e($it[1]) ?>"></i>

            <span><?= e($it[2]) ?></span>

        </a>

    <?php endforeach; ?>

</nav>
