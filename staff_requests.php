<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_staff();
ensure_staff_tables();
$type = (string) ($_GET['type'] ?? 'documents');
$config = [
    'documents' => ['title' => 'Document Request Approvals', 'table' => 'document_request', 'id' => 'request_id', 'status' => ['Pending', 'Processing', 'Approved', 'Rejected', 'Completed']],
    'blotter' => ['title' => 'Blotter Approval', 'table' => 'blotter', 'id' => 'report_id', 'status' => ['Pending', 'Under Review', 'In Progress', 'Resolved', 'Closed', 'Rejected']],
    'appointments' => ['title' => 'Appointment Approval', 'table' => 'appointment', 'id' => 'appointment_id', 'status' => ['Pending', 'Approved', 'Completed', 'Cancelled', 'Missed']],
][$type] ?? null;
if ($config === null) {
    http_response_code(404);
    exit('Unknown staff module.');
}
$selectedId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT) ?: null;
$message = '';
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        $recordId = filter_var($_POST['record_id'] ?? '', FILTER_VALIDATE_INT);
        $newStatus = (string) ($_POST['status'] ?? '');
        if (!$recordId || !in_array($newStatus, $config['status'], true)) {
            throw new RuntimeException('Invalid request status.');
        }
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $set = $type === 'documents' ? 'status = :status, assigned_staff_id = :staff_id, remarks = NULLIF(:notes, \'\')'
            : ($type === 'blotter' ? 'status = :status, assigned_staff_id = :staff_id, resolution_notes = NULLIF(:notes, \'\'), date_resolved = CASE WHEN :status IN (\'Resolved\', \'Closed\') THEN CURRENT_TIMESTAMP ELSE date_resolved END'
            : 'status = :status, assigned_staff_id = :staff_id');
        $statement = db()->prepare("UPDATE {$config['table']} SET {$set} WHERE {$config['id']} = :record_id");
        $statement->execute(['status' => $newStatus, 'staff_id' => (int) ($_SESSION['user']['staff_id'] ?? 0) ?: null, 'notes' => $notes, 'record_id' => $recordId]);
        staff_audit('REQUEST_STATUS_UPDATED', $config['table'], $recordId, ['status' => $newStatus, 'notes' => $notes]);
        $message = 'Request updated and recorded in the audit log.';
        $selectedId = $recordId;
    } catch (Throwable $exception) {
        error_log('Staff request update error: ' . $exception->getMessage());
        $errors[] = $exception->getMessage();
    }
}
if ($type === 'documents') {
    $rows = db()->query("SELECT dr.request_id AS record_id, dr.document_type AS request_type, dr.purpose, dr.request_date, dr.status, r.first_name, r.last_name, r.complete_address, r.residency_status, r.valid_id_file_path, r.resident_id FROM document_request dr JOIN resident r ON r.resident_id = dr.resident_id ORDER BY dr.request_date DESC")->fetchAll();
} elseif ($type === 'blotter') {
    $rows = db()->query("SELECT b.report_id AS record_id, b.category AS request_type, b.report_title AS purpose, b.report_date AS request_date, b.status, r.first_name, r.last_name, r.complete_address, r.residency_status, r.valid_id_file_path, r.resident_id FROM blotter b LEFT JOIN resident r ON r.resident_id = b.resident_id ORDER BY b.report_date DESC")->fetchAll();
} else {
    $rows = db()->query("SELECT a.appointment_id AS record_id, a.service_type AS request_type, a.notes AS purpose, a.schedule AS request_date, a.status, r.first_name, r.last_name, r.complete_address, r.residency_status, r.valid_id_file_path, r.resident_id FROM appointment a JOIN resident r ON r.resident_id = a.resident_id ORDER BY a.schedule DESC")->fetchAll();
}
$selected = null;
foreach ($rows as $row) {
    if ((int) $row['record_id'] === $selectedId) {
        $selected = $row;
        break;
    }
}
$pageTitle = $config['title'];
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page staff-page">
    <aside class="dashboard-sidebar"><a class="dashboard-brand" href="staff_dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Staff Portal</small></span></a><?php require __DIR__ . '/includes/staff_nav.php'; ?></aside>
    <section class="dashboard-content">
        <header class="dashboard-topbar"><div class="dashboard-search"><i class="bi bi-search"></i><input type="search" placeholder="Search by resident, tracking number, or request type..." aria-label="Search requests"></div><div class="dashboard-user"><span class="user-avatar"><i class="bi bi-person-fill"></i></span><span><strong><?= e($_SESSION['user']['full_name']) ?></strong><small>Staff</small></span></div></header>
        <div class="staff-main"><div class="staff-hero compact"><div><p class="eyebrow">APPROVAL WORKSPACE</p><h1><?= e($config['title']) ?></h1><p>Review resident submissions and update their processing status.</p></div></div>
            <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>
            <div class="staff-request-layout"><section class="staff-panel request-table-panel"><div class="staff-tabs"><a class="<?= $type === 'documents' ? 'active' : '' ?>" href="staff_requests.php?type=documents">Documents</a><a class="<?= $type === 'blotter' ? 'active' : '' ?>" href="staff_requests.php?type=blotter">Blotter</a><a class="<?= $type === 'appointments' ? 'active' : '' ?>" href="staff_requests.php?type=appointments">Appointments</a></div><div class="table-responsive"><table class="staff-table"><thead><tr><th>Reference</th><th>Resident</th><th>Type</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td>#<?= (int) $row['record_id'] ?></td><td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td><td><?= e($row['request_type']) ?></td><td><?= e((new DateTime($row['request_date']))->format('M j, Y g:i A')) ?></td><td><span class="staff-status"><?= e($row['status']) ?></span></td><td><a class="btn btn-sm btn-outline-primary" href="staff_requests.php?type=<?= e($type) ?>&id=<?= (int) $row['record_id'] ?>">View</a></td></tr><?php endforeach; ?><?php if ($rows === []): ?><tr><td colspan="6">No requests found.</td></tr><?php endif; ?></tbody></table></div></section>
                <?php if ($selected): ?><aside class="staff-panel staff-detail"><div class="staff-detail-heading"><h2>Request details</h2><a href="staff_requests.php?type=<?= e($type) ?>" aria-label="Close details">&times;</a></div><details open><summary>Resident verification</summary><p><strong><?= e($selected['first_name'] . ' ' . $selected['last_name']) ?></strong></p><p><?= e($selected['complete_address']) ?></p><span class="verification-badge"><i class="bi bi-check-circle"></i> <?= e($selected['residency_status'] === 'Active' ? 'Verified resident record' : 'Review residency status') ?></span></details><details open><summary>Submitted information</summary><p><strong><?= e($selected['request_type']) ?></strong></p><p><?= nl2br(e((string) $selected['purpose'])) ?></p><p>Status: <?= e($selected['status']) ?></p></details><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="record_id" value="<?= (int) $selected['record_id'] ?>"><label class="form-label" for="status">Update status</label><select class="form-control" id="status" name="status"><?php foreach ($config['status'] as $status): ?><option <?= $status === $selected['status'] ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select><label class="form-label mt-3" for="notes">Staff notes</label><textarea class="form-control" id="notes" name="notes" rows="3"></textarea><button class="btn btn-primary w-100 mt-3" type="submit">Save and log action</button><?php if ($type === 'documents' && $selected['request_type'] === 'Barangay Clearance'): ?><a class="btn btn-outline-primary w-100 mt-2" target="_blank" href="staff_clearance.php?id=<?= (int) $selected['record_id'] ?>">Preview clearance sample</a><?php endif; ?></form></aside><?php endif; ?></div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
