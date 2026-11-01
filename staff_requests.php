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
if ($type === 'documents') {
    require_once __DIR__ . '/includes/document_pickup.php';
    require_once __DIR__ . '/includes/document_pickup_email.php';
    require_once __DIR__ . '/config/mailer.php';
    ensure_document_pickup_schema();
}
$selectedId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT) ?: null;
$statusView = (string) ($_GET['status_view'] ?? 'active');
if (!in_array($statusView, ['active', 'completed', 'all'], true)) {
    $statusView = 'active';
}
$currentPage = max(1, (int) (filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
$pageSize = 10;
$message = '';
$mailWarning = '';
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
        if ($type === 'documents' && $newStatus === 'Released') {
            throw new RuntimeException('Document requests can only be marked released after scanning the resident pickup QR code.');
        }
        $set = $type === 'documents' ? 'status = :status, assigned_staff_id = :staff_id, remarks = NULLIF(:notes, \'\')'
            : ($type === 'blotter' ? 'status = :status, assigned_staff_id = :staff_id, resolution_notes = NULLIF(:notes, \'\'), date_resolved = CASE WHEN :resolved_status IN (\'Resolved\', \'Closed\') THEN CURRENT_TIMESTAMP ELSE date_resolved END'
            : 'status = :status, assigned_staff_id = :staff_id');
        $statement = db()->prepare("UPDATE {$config['table']} SET {$set} WHERE {$config['id']} = :record_id");
        $parameters = [
            'status' => $newStatus,
            'staff_id' => (int) ($_SESSION['user']['staff_id'] ?? 0) ?: null,
            'record_id' => $recordId,
        ];
        if ($type === 'documents' || $type === 'blotter') {
            $parameters['notes'] = $notes;
        }
        if ($type === 'blotter') {
            $parameters['resolved_status'] = $newStatus;
        }
        $statement->execute($parameters);
        if ($type === 'documents') {
            if ($newStatus === 'Completed') {
                $pickupStatement = db()->prepare(
                    'SELECT dr.document_type, dr.name, r.email
                     FROM document_request dr
                     JOIN resident r ON r.resident_id = dr.resident_id
                     WHERE dr.request_id = :request_id AND dr.status = :status'
                );
                $pickupStatement->execute(['request_id' => $recordId, 'status' => $newStatus]);
                $pickupDetails = $pickupStatement->fetch();
                if ($pickupDetails) {
                    $existingToken = db()->prepare('SELECT 1 FROM document_pickup_token WHERE request_id = :request_id');
                    $existingToken->execute(['request_id' => $recordId]);
                    if ($existingToken->fetchColumn()) {
                        $message = 'Request is ready for pickup. The existing QR code remains valid.';
                    } else {
                        $pickup = create_document_pickup_token((int) $recordId);
                        $emailAddress = trim((string) ($pickupDetails['email'] ?? ''));
                        if ($emailAddress !== '' && filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
                            $emailSent = sendEmail(
                                $emailAddress,
                                'Your document is ready for pickup',
                                document_pickup_ready_email_html(
                                    [
                                        'request_id' => $recordId,
                                        'document_type' => $pickupDetails['document_type'],
                                        'name' => $pickupDetails['name'],
                                    ],
                                    $pickup['url']
                                ),
                                [[
                                    'content' => $pickup['png'],
                                    'cid' => 'document-pickup-qr',
                                    'name' => 'document-pickup-qr.png',
                                    'mime' => 'image/png',
                                ]]
                            );
                            if ($emailSent) {
                                $message = 'Request marked ready. The pickup QR code was emailed to the resident.';
                            } else {
                                $removeFailedToken = db()->prepare('DELETE FROM document_pickup_token WHERE request_id = :request_id');
                                $removeFailedToken->execute(['request_id' => $recordId]);
                                $mailWarning = 'Request marked ready, but the QR email could not be sent. Check the email configuration and save this status again to resend.';
                                error_log('Document pickup QR email was not sent for request #' . (int) $recordId);
                            }
                        } else {
                            $removeToken = db()->prepare('DELETE FROM document_pickup_token WHERE request_id = :request_id');
                            $removeToken->execute(['request_id' => $recordId]);
                            $mailWarning = 'Request marked ready, but the resident has no valid email address on file.';
                            error_log('Document pickup QR email skipped because no valid resident email exists for request #' . (int) $recordId);
                        }
                    }
                }
            } else {
                $removePickupToken = db()->prepare('DELETE FROM document_pickup_token WHERE request_id = :request_id');
                $removePickupToken->execute(['request_id' => $recordId]);
            }
        }
        staff_audit('REQUEST_STATUS_UPDATED', $config['table'], $recordId, ['status' => $newStatus, 'notes' => $notes]);
        if ($message === '') {
            $message = 'Request updated and recorded in the audit log.';
        }
        $selectedId = $recordId;
    } catch (Throwable $exception) {
        error_log('Staff request update error: ' . $exception->getMessage());
        $errors[] = $exception->getMessage();
    }
}
if ($type === 'documents') {
    $statusCondition = match ($statusView) {
        'completed' => "dr.status IN ('Completed', 'Released')",
        'all' => 'TRUE',
        default => "dr.status NOT IN ('Completed', 'Released', 'Rejected', 'Cancelled')",
    };
    $countStatement = db()->query("SELECT COUNT(*) FROM document_request dr WHERE {$statusCondition}");
    $totalRows = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRows / $pageSize));
    $currentPage = min($currentPage, $totalPages);
    $statement = db()->prepare(
        "SELECT dr.request_id AS record_id, dr.document_type AS request_type, dr.purpose,
                dr.request_date, dr.status, r.first_name, r.last_name, r.complete_address,
                r.residency_status, r.valid_id_file_path, r.resident_id
         FROM document_request dr
         JOIN resident r ON r.resident_id = dr.resident_id
         WHERE {$statusCondition}
         ORDER BY dr.request_date DESC
         LIMIT :page_size OFFSET :offset"
    );
    $statement->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
    $statement->bindValue(':offset', ($currentPage - 1) * $pageSize, PDO::PARAM_INT);
    $statement->execute();
    $rows = $statement->fetchAll();
} elseif ($type === 'blotter') {
    $rows = db()->query("SELECT b.report_id AS record_id, b.category AS request_type, b.report_title AS purpose, b.report_date AS request_date, b.status, b.location, b.latitude, b.longitude, r.first_name, r.last_name, r.complete_address, r.residency_status, r.valid_id_file_path, r.resident_id FROM blotter b LEFT JOIN resident r ON r.resident_id = b.resident_id ORDER BY b.report_date DESC")->fetchAll();
    $totalRows = count($rows);
    $totalPages = 1;
} else {
    $rows = db()->query("SELECT a.appointment_id AS record_id, a.service_type AS request_type, a.notes AS purpose, a.schedule AS request_date, a.status, r.first_name, r.last_name, r.complete_address, r.residency_status, r.valid_id_file_path, r.resident_id FROM appointment a JOIN resident r ON r.resident_id = a.resident_id ORDER BY a.schedule DESC")->fetchAll();
    $totalRows = count($rows);
    $totalPages = 1;
}
$pageUrl = static fn (int $page): string => 'staff_requests.php?type=' . rawurlencode($type)
    . '&status_view=' . rawurlencode($statusView) . '&page=' . $page;
$statusClass = static fn (string $status): string => match ($status) {
    'Pending' => 'staff-status-pending',
    'Processing', 'Under Review', 'In Progress' => 'staff-status-progress',
    'Approved', 'Completed', 'Released', 'Resolved', 'Closed' => 'staff-status-complete',
    'Rejected' => 'staff-status-rejected',
    'Cancelled', 'Missed' => 'staff-status-inactive',
    default => 'staff-status-unknown',
};
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
<?php if ($type === 'blotter'): ?><link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"><?php endif; ?>
<main class="dashboard-page staff-page">
    <aside class="dashboard-sidebar"><a class="dashboard-brand" href="staff_dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Staff Portal</small></span></a><?php require __DIR__ . '/includes/staff_nav.php'; ?></aside>
    <section class="dashboard-content">
        <header class="dashboard-topbar"><div class="dashboard-search"><i class="bi bi-search"></i><input type="search" placeholder="Search by resident, tracking number, or request type..." aria-label="Search requests"></div><div class="dashboard-user"><span class="user-avatar"><i class="bi bi-person-fill"></i></span><span><strong><?= e($_SESSION['user']['full_name']) ?></strong><small>Staff</small></span></div></header>
        <div class="staff-main"><div class="staff-hero compact"><div><p class="eyebrow">APPROVAL WORKSPACE</p><h1><?= e($config['title']) ?></h1><p>Review resident submissions and update their processing status.</p></div></div>
            <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php if ($mailWarning !== ''): ?><div class="alert alert-warning"><?= e($mailWarning) ?></div><?php endif; ?><?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>
            <div class="staff-request-layout"><section class="staff-panel request-table-panel"><div class="staff-tabs"><a class="<?= $type === 'documents' ? 'active' : '' ?>" href="staff_requests.php?type=documents">Documents</a><a class="<?= $type === 'blotter' ? 'active' : '' ?>" href="staff_requests.php?type=blotter">Blotter</a><a class="<?= $type === 'appointments' ? 'active' : '' ?>" href="staff_requests.php?type=appointments">Appointments</a></div>
                <?php if ($type === 'documents'): ?><form method="get" class="request-status-filter"><input type="hidden" name="type" value="documents"><label for="status-view"><i class="bi bi-funnel" aria-hidden="true"></i><span>Filter by status</span></label><select id="status-view" name="status_view" class="form-control"><option value="active" <?= $statusView === 'active' ? 'selected' : '' ?>>Open requests</option><option value="completed" <?= $statusView === 'completed' ? 'selected' : '' ?>>Completed / released</option><option value="all" <?= $statusView === 'all' ? 'selected' : '' ?>>All requests</option></select><button class="btn btn-primary btn-sm" type="submit">Apply</button></form><?php endif; ?>
                <div class="table-responsive"><table class="staff-table"><thead><tr><th>Reference</th><th>Resident</th><th>Type</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td>#<?= (int) $row['record_id'] ?></td><td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td><td><?= e($row['request_type']) ?></td><td><?= e((new DateTime($row['request_date']))->format('M j, Y g:i A')) ?></td><td><span class="staff-status <?= e($statusClass((string) $row['status'])) ?>"><?= e($row['status']) ?></span></td><td><?php if ($type === 'documents'): ?><?php if (!in_array($row['status'], ['Cancelled', 'Rejected'], true)): ?><a class="btn btn-sm btn-outline-primary" href="staff_document_templates.php?id=<?= (int) $row['record_id'] ?>" target="_blank" rel="noopener"><i class="bi bi-eye" aria-hidden="true"></i> View</a> <?php endif; ?><a class="btn btn-sm btn-light" href="<?= e($pageUrl($currentPage)) ?>&amp;id=<?= (int) $row['record_id'] ?>">Details</a><?php else: ?><a class="btn btn-sm btn-outline-primary" href="<?= e($pageUrl($currentPage)) ?>&amp;id=<?= (int) $row['record_id'] ?>">View</a><?php endif; ?></td></tr><?php endforeach; ?><?php if ($rows === []): ?><tr><td colspan="6">No requests found.</td></tr><?php endif; ?></tbody></table></div>
                <?php if ($type === 'documents'): ?><nav class="request-pagination" aria-label="Document request pages"><span>Showing <?= $totalRows === 0 ? 0 : (($currentPage - 1) * $pageSize + 1) ?>–<?= min($currentPage * $pageSize, $totalRows) ?> of <?= $totalRows ?></span><div><?php if ($currentPage > 1): ?><a class="btn btn-sm btn-outline-primary" href="<?= e($pageUrl($currentPage - 1)) ?>">Previous</a><?php endif; ?><span>Page <?= $currentPage ?> of <?= $totalPages ?></span><?php if ($currentPage < $totalPages): ?><a class="btn btn-sm btn-outline-primary" href="<?= e($pageUrl($currentPage + 1)) ?>">Next</a><?php endif; ?></div></nav><?php endif; ?>
                </section>
                <?php if ($selected): ?><aside class="staff-panel staff-detail"><div class="staff-detail-heading"><h2>Request details</h2><a href="<?= e($pageUrl($currentPage)) ?>" aria-label="Close details">&times;</a></div><details open><summary>Resident verification</summary><p><strong><?= e($selected['first_name'] . ' ' . $selected['last_name']) ?></strong></p><p><?= e($selected['complete_address']) ?></p><span class="verification-badge"><i class="bi bi-check-circle"></i> <?= e($selected['residency_status'] === 'Active' ? 'Verified resident record' : 'Review residency status') ?></span></details><details open><summary>Submitted information</summary><p><strong><?= e($selected['request_type']) ?></strong></p><p><?= nl2br(e((string) $selected['purpose'])) ?></p><?php if ($type === 'blotter'): ?><p><strong>Reported location:</strong> <?= e((string) ($selected['location'] ?? 'Location not provided')) ?></p><?php if (is_numeric($selected['latitude'] ?? null) && is_numeric($selected['longitude'] ?? null)): ?><p><strong>Resident's map pin:</strong> <?= e((string) $selected['latitude']) ?>, <?= e((string) $selected['longitude']) ?></p><?php $pinLatitude = (float) $selected['latitude']; $pinLongitude = (float) $selected['longitude']; if ($pinLatitude >= INOSLUBAN_MAP_BOUNDS['south'] && $pinLatitude <= INOSLUBAN_MAP_BOUNDS['north'] && $pinLongitude >= INOSLUBAN_MAP_BOUNDS['west'] && $pinLongitude <= INOSLUBAN_MAP_BOUNDS['east']): ?><div id="staff-blotter-map" class="staff-blotter-map" aria-label="Map showing the exact incident location" data-latitude="<?= e((string) $pinLatitude) ?>" data-longitude="<?= e((string) $pinLongitude) ?>" data-south="<?= e((string) INOSLUBAN_MAP_BOUNDS['south']) ?>" data-north="<?= e((string) INOSLUBAN_MAP_BOUNDS['north']) ?>" data-west="<?= e((string) INOSLUBAN_MAP_BOUNDS['west']) ?>" data-east="<?= e((string) INOSLUBAN_MAP_BOUNDS['east']) ?>"></div><?php else: ?><p class="text-warning">This saved pin is outside the Inosluban map area, so it cannot be shown on the restricted map.</p><?php endif; ?><?php else: ?><p>No map pin was saved for this report.</p><?php endif; ?><?php endif; ?><p>Status: <span class="staff-status <?= e($statusClass((string) $selected['status'])) ?>"><?= e($selected['status']) ?></span></p></details><?php if ($type === 'documents' && $selected['status'] === 'Released'): ?><div class="alert alert-success">Released after pickup QR verification.</div><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="record_id" value="<?= (int) $selected['record_id'] ?>"><label class="form-label" for="status">Update status</label><select class="form-control" id="status" name="status"><?php foreach ($config['status'] as $status): ?><option <?= $status === $selected['status'] ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select><label class="form-label mt-3" for="notes">Staff notes</label><textarea class="form-control" id="notes" name="notes" rows="3"></textarea><button class="btn btn-primary w-100 mt-3" type="submit">Save and log action</button><?php if ($type === 'documents'): ?><a class="btn btn-outline-primary w-100 mt-2" href="staff_document_templates.php?id=<?= (int) $selected['record_id'] ?>&amp;edit=1" target="_blank" rel="noopener"><i class="bi bi-pencil-square"></i> Edit PDF fields</a><?php endif; ?></form><?php endif; ?></aside><?php endif; ?></div>
        </div>
    </section>
</main>
<?php if ($type === 'blotter'): ?><script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
