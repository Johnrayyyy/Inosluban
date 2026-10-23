<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_resident();

$residentId = (int) $_SESSION['user']['resident_id'];
$services = ['Document Processing', 'Barangay Clearance', 'Business Permit', 'Indigency Certificate', 'General Inquiry', 'Other'];
$errors = [];
$success = '';

try {
    $staffStatement = db()->query(
        "SELECT s.staff_id, s.first_name, s.last_name, s.position
         FROM staff s
         JOIN user_account ua ON ua.user_id = s.user_id
         WHERE s.employment_status = 'Active'
           AND ua.account_status = 'active'
         ORDER BY s.last_name, s.first_name"
    );
    $staffMembers = $staffStatement->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        $staffId = filter_var($_POST['assigned_staff_id'] ?? '', FILTER_VALIDATE_INT);
        $schedule = trim((string) ($_POST['schedule'] ?? ''));
        $serviceType = trim((string) ($_POST['service_type'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if (!$staffId || !array_filter($staffMembers, static fn (array $staff): bool => (int) $staff['staff_id'] === $staffId)) {
            $errors[] = 'Please choose an available barangay staff member.';
        }
        $scheduleDate = DateTime::createFromFormat('!Y-m-d\TH:i', $schedule);
        if (!$scheduleDate || $scheduleDate < new DateTime('now')) {
            $errors[] = 'Please choose a future appointment date and time.';
        }
        if (!in_array($serviceType, $services, true)) {
            $errors[] = 'Please choose a valid service type.';
        }
        if ($notes === '') {
            $errors[] = 'Please enter the purpose or notes for your appointment.';
        }

        if ($errors === []) {
            $appointmentStatement = db()->prepare(
                'INSERT INTO appointment (resident_id, assigned_staff_id, schedule, service_type, notes)
                 VALUES (:resident_id, :assigned_staff_id, :schedule, :service_type, :notes)'
            );
            $appointmentStatement->execute([
                'resident_id' => $residentId,
                'assigned_staff_id' => $staffId,
                'schedule' => str_replace('T', ' ', $schedule),
                'service_type' => $serviceType,
                'notes' => $notes,
            ]);
            $success = 'Your appointment request was submitted and is pending review.';
        }
    }

    $todayAppointmentsStatement = db()->query(
        "SELECT a.schedule, a.service_type,
                s.first_name AS staff_first_name, s.last_name AS staff_last_name, s.position
         FROM appointment a
         JOIN staff s ON s.staff_id = a.assigned_staff_id
         WHERE a.status = 'Approved'
           AND a.schedule::date = CURRENT_DATE
         ORDER BY a.schedule ASC"
    );
    $todayAppointments = $todayAppointmentsStatement->fetchAll();

    $upcomingAppointmentsStatement = db()->query(
        "SELECT a.schedule, a.service_type,
                s.first_name AS staff_first_name, s.last_name AS staff_last_name, s.position
         FROM appointment a
         JOIN staff s ON s.staff_id = a.assigned_staff_id
         WHERE a.status = 'Approved'
           AND a.schedule >= CURRENT_TIMESTAMP
         ORDER BY a.schedule ASC
         LIMIT 6"
    );
    $upcomingAppointments = $upcomingAppointmentsStatement->fetchAll();

    $appointmentsStatement = db()->prepare(
        'SELECT a.appointment_id, a.schedule, a.service_type, a.status, a.notes,
                s.first_name AS staff_first_name, s.last_name AS staff_last_name, s.position
         FROM appointment a
         LEFT JOIN staff s ON s.staff_id = a.assigned_staff_id
         WHERE a.resident_id = :resident_id
         ORDER BY a.schedule DESC'
    );
    $appointmentsStatement->execute(['resident_id' => $residentId]);
    $appointments = $appointmentsStatement->fetchAll();
    $appointmentCounts = [
        'total' => count($appointments),
        'upcoming' => count(array_filter($appointments, static fn (array $appointment): bool => new DateTime($appointment['schedule']) >= new DateTime('now') && !in_array($appointment['status'], ['Cancelled', 'Missed'], true))),
        'completed' => count(array_filter($appointments, static fn (array $appointment): bool => $appointment['status'] === 'Completed')),
        'cancelled' => count(array_filter($appointments, static fn (array $appointment): bool => $appointment['status'] === 'Cancelled')),
    ];
} catch (PDOException $exception) {
    error_log('Appointment database error: ' . $exception->getMessage());
    http_response_code(500);
    exit('The appointment service is temporarily unavailable.');
}

$pageTitle = 'Book an appointment';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page request-module appointment-module" data-theme="light">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Community Portal</small></span></a>
        <?php require __DIR__ . '/includes/resident_nav.php'; ?>
        <div class="dashboard-sidebar-footer"><img src="assets/images/logo.png" alt=""><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small></span></div>
    </aside>
    <section class="dashboard-content request-content">
        <header class="dashboard-topbar request-topbar"><div class="dashboard-search"><i class="bi bi-search"></i><input type="search" placeholder="Search services, announcements, or information..." aria-label="Search portal"></div><div class="dashboard-user"><button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch to dark mode"><i class="bi bi-moon-stars"></i></button><span class="user-avatar"><i class="bi bi-person-fill"></i></span><span><strong><?= e($_SESSION['user']['full_name']) ?></strong><small>Resident Account</small></span></div></header>
        <div class="request-main appointment-main">
            <div class="request-breadcrumb">Resident Services / Appointment</div>
            <div class="request-title-row"><div><h1><i class="bi bi-calendar3"></i> Appointment Dashboard</h1><p>View today’s official schedule and request time with a barangay staff member.</p></div></div>
            <?php if ($errors !== []): ?><div class="alert alert-danger request-alert"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <?php if ($success !== ''): ?><div class="alert alert-success request-alert"><?= e($success) ?></div><?php endif; ?>
            <div class="appointment-reference-stats">
                <article><span class="reference-stat-icon"><i class="bi bi-calendar2-check"></i></span><span><small>Total Appointments</small><strong><?= $appointmentCounts['total'] ?></strong></span><i class="bi bi-arrow-right"></i></article>
                <article><span class="reference-stat-icon"><i class="bi bi-clock-history"></i></span><span><small>Upcoming</small><strong><?= $appointmentCounts['upcoming'] ?></strong></span><i class="bi bi-arrow-right"></i></article>
                <article><span class="reference-stat-icon"><i class="bi bi-check2-circle"></i></span><span><small>Completed</small><strong><?= $appointmentCounts['completed'] ?></strong></span><i class="bi bi-arrow-right"></i></article>
                <article><span class="reference-stat-icon"><i class="bi bi-x-lg"></i></span><span><small>Cancelled</small><strong><?= $appointmentCounts['cancelled'] ?></strong></span><i class="bi bi-arrow-right"></i></article>
            </div>
            <div class="appointment-dashboard-grid">
            <div class="appointment-primary-column">
            <section class="request-card today-schedule-card">
                <div class="card-heading"><div><h2><i class="bi bi-calendar-day"></i> Today’s Official Schedule</h2><p><?= e((new DateTime('today'))->format('l, F j, Y')) ?> · Approved appointments with barangay officials</p></div><span class="active-count"><?= count($todayAppointments) ?> scheduled</span></div>
                <div class="today-schedule-list"><?php foreach ($todayAppointments as $appointment): ?><article class="today-schedule-item"><span class="schedule-time"><?= e((new DateTime($appointment['schedule']))->format('g:i A')) ?></span><span><strong><?= e($appointment['service_type']) ?></strong><small><?= e(trim(($appointment['staff_first_name'] ?? '') . ' ' . ($appointment['staff_last_name'] ?? ''))) ?><?= $appointment['position'] ? ' · ' . e($appointment['position']) : '' ?></small></span></article><?php endforeach; ?><?php if ($todayAppointments === []): ?><p class="empty-requests">No official appointments are scheduled for today.</p><?php endif; ?></div>
            </section>
            <section class="reference-appointments-table request-card">
                <div class="reference-table-toolbar"><button class="btn btn-primary" type="button" onclick="document.getElementById('book-appointment').scrollIntoView({behavior: 'smooth'})"><i class="bi bi-plus-lg"></i> Book an Appointment</button><div class="reference-table-filters"><input class="form-control" type="search" placeholder="Search by service or purpose..." aria-label="Search appointments"><select class="form-control" aria-label="Filter appointments"><option>All Status</option><option>Pending</option><option>Approved</option><option>Completed</option><option>Cancelled</option></select></div></div>
                <div class="reference-table-wrap"><table><thead><tr><th>Date &amp; Time</th><th>Service Type</th><th>Purpose / Notes</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($appointments as $appointment): ?><tr><td><strong><?= e((new DateTime($appointment['schedule']))->format('M j, Y')) ?></strong><small><?= e((new DateTime($appointment['schedule']))->format('g:i A')) ?></small></td><td><strong><i class="bi bi-file-earmark-text"></i> <?= e($appointment['service_type']) ?></strong><small><?= e(trim(($appointment['staff_first_name'] ?? '') . ' ' . ($appointment['staff_last_name'] ?? ''))) ?></small></td><td><?= e($appointment['notes'] ?: 'No notes provided') ?></td><td><span class="appointment-status status-<?= strtolower((string) $appointment['status']) ?>"><?= e($appointment['status']) ?></span></td><td><a class="reference-details-link" href="#my-appointments">View Details <i class="bi bi-chevron-right"></i></a></td></tr><?php endforeach; ?><?php if ($appointments === []): ?><tr><td class="reference-empty" colspan="5">You have no appointment requests yet.</td></tr><?php endif; ?></tbody></table></div>
            </section>
            <section class="request-card appointment-form-card" id="book-appointment"><div class="card-heading"><div><h2><i class="bi bi-calendar-plus"></i> Book an Appointment</h2><p>Request an appointment for a document or other barangay service. New requests are automatically set to Pending.</p></div></div><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="appointment-form-grid"><div><label class="form-label" for="assigned_staff_id">Barangay Official <b>*</b></label><select class="form-control request-input" id="assigned_staff_id" name="assigned_staff_id" required><option value="">Select an official</option><?php foreach ($staffMembers as $staff): ?><option value="<?= (int) $staff['staff_id'] ?>" <?= (string) ($_POST['assigned_staff_id'] ?? '') === (string) $staff['staff_id'] ? 'selected' : '' ?>><?= e($staff['first_name'] . ' ' . $staff['last_name'] . ($staff['position'] ? ' · ' . $staff['position'] : '')) ?></option><?php endforeach; ?></select><?php if ($staffMembers === []): ?><small class="profile-help">No active officials are currently available.</small><?php endif; ?></div><div><label class="form-label" for="schedule">Preferred Date &amp; Time <b>*</b></label><input class="form-control request-input" type="datetime-local" id="schedule" name="schedule" value="<?= e((string) ($_POST['schedule'] ?? '')) ?>" min="<?= e(date('Y-m-d\TH:i')) ?>" required></div><div><label class="form-label" for="service_type">Service Type <b>*</b></label><select class="form-control request-input" id="service_type" name="service_type" required><option value="">Select service type</option><?php foreach ($services as $service): ?><option value="<?= e($service) ?>" <?= ($_POST['service_type'] ?? '') === $service ? 'selected' : '' ?>><?= e($service) ?></option><?php endforeach; ?></select></div><div class="detail-wide"><label class="form-label" for="notes">Purpose / Document Needed <b>*</b></label><textarea class="form-control request-input" id="notes" name="notes" rows="4" maxlength="500" placeholder="Tell us what you need help with..." required><?= e((string) ($_POST['notes'] ?? '')) ?></textarea><small class="profile-help text-end d-block">Maximum 500 characters.</small></div></div><div class="wizard-actions"><a class="btn btn-light request-back" href="appointment.php">Clear</a><button class="btn btn-primary request-next" type="submit" <?= $staffMembers === [] ? 'disabled' : '' ?>><i class="bi bi-calendar-check me-1"></i> Submit Request</button></div></form></section>
            <section class="request-card resident-history-card" id="my-appointments"><div class="card-heading"><div><h2><i class="bi bi-person-lines-fill"></i> My Appointments</h2><p>Track all appointment requests for your resident account.</p></div><span class="active-count"><?= count($appointments) ?> total</span></div><div class="appointment-list"><?php foreach ($appointments as $appointment): ?><article class="appointment-item"><div class="appointment-date"><strong><?= e((new DateTime($appointment['schedule']))->format('M')) ?></strong><b><?= e((new DateTime($appointment['schedule']))->format('d')) ?></b></div><div class="appointment-detail"><strong><?= e($appointment['service_type']) ?></strong><small><?= e((new DateTime($appointment['schedule']))->format('M j, Y · g:i A')) ?> · <?= e(trim(($appointment['staff_first_name'] ?? '') . ' ' . ($appointment['staff_last_name'] ?? ''))) ?></small><span class="appointment-status status-<?= strtolower((string) $appointment['status']) ?>"><?= e($appointment['status']) ?></span></div></article><?php endforeach; ?><?php if ($appointments === []): ?><p class="empty-requests">You have no appointment requests yet.</p><?php endif; ?></div></section>
            </div>
            <aside class="appointment-glass-sidebar">
                <section class="appointment-upcoming-card"><div class="card-heading"><div><h2><i class="bi bi-calendar-event"></i> Upcoming Appointments</h2><p>Barangay-wide approved schedule</p></div><span class="upcoming-count"><?= count($upcomingAppointments) ?></span></div><div class="upcoming-appointment-list"><?php foreach ($upcomingAppointments as $appointment): ?><article class="upcoming-appointment-item"><div class="upcoming-date"><strong><?= e((new DateTime($appointment['schedule']))->format('M')) ?></strong><b><?= e((new DateTime($appointment['schedule']))->format('d')) ?></b></div><div><strong><?= e($appointment['service_type']) ?></strong><small><?= e((new DateTime($appointment['schedule']))->format('g:i A')) ?> · <?= e(trim(($appointment['staff_first_name'] ?? '') . ' ' . ($appointment['staff_last_name'] ?? ''))) ?></small></div></article><?php endforeach; ?><?php if ($upcomingAppointments === []): ?><p class="upcoming-empty">No upcoming approved appointments.</p><?php endif; ?></div></section>
                <section class="appointment-quick-card"><div class="card-heading"><div><h2><i class="bi bi-lightning-charge-fill"></i> Quick Actions</h2><p>Manage your appointments</p></div></div><div class="appointment-quick-grid"><a href="#book-appointment"><i class="bi bi-calendar-plus"></i><span>Book an Appointment</span></a><a href="#my-appointments"><i class="bi bi-calendar2-week"></i><span>View My Appointments</span></a></div></section>
                <section class="appointment-help-card"><h3><i class="bi bi-info-circle"></i> Before you book</h3><p>Choose the official who handles your concern, select a preferred time, and include the document or service you need.</p><a href="#book-appointment">Start a request <i class="bi bi-arrow-right"></i></a></section>
            </aside>
            </div>
        </div>
        <footer class="dashboard-footer"><span>Connect. Collaborate. Grow Together.</span><small>(c) 2026 Barangay Inosluban. All rights reserved.</small></footer>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
