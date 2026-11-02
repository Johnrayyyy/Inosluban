<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';

require_staff();
ensure_staff_tables();
ensure_staff_leave_schema();

$isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
$userId = (int) $_SESSION['user']['id'];
$leaveTypes = ['Annual', 'Sick', 'Personal', 'Other'];
$errors = [];
$message = (string) ($_SESSION['staff_leave_message'] ?? '');
unset($_SESSION['staff_leave_message']);

$statusFilter = $_GET['status'] ?? 'all';
if (!is_string($statusFilter) || !in_array($statusFilter, ['all', 'Pending', 'Approved', 'Rejected', 'Withdrawn'], true)) {
    $statusFilter = 'all';
}
$currentPage = max(1, (int) (filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
$pageSize = 10;
$philippineTimeZone = new DateTimeZone(APP_TIMEZONE);
$earliestLeaveDate = (new DateTimeImmutable('today', $philippineTimeZone))->modify('+3 days');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token((string) ($_POST['csrf_token'] ?? ''));
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'submit_leave' && !$isAdmin) {
            $leaveType = (string) ($_POST['leave_type'] ?? '');
            $startDate = (string) ($_POST['start_date'] ?? '');
            $endDate = (string) ($_POST['end_date'] ?? '');
            $reason = trim((string) ($_POST['reason'] ?? ''));
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, $philippineTimeZone);
            $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, $philippineTimeZone);

            if (!in_array($leaveType, $leaveTypes, true)) {
                throw new RuntimeException('Choose a valid leave type.');
            }
            if (!$start || $start->format('Y-m-d') !== $startDate || !$end || $end->format('Y-m-d') !== $endDate) {
                throw new RuntimeException('Enter valid leave dates.');
            }
            if ($start < $earliestLeaveDate) {
                throw new RuntimeException('Leave must be filed at least 3 calendar days before the first day of absence.');
            }
            if ($end < $start) {
                throw new RuntimeException('The end date must be on or after the start date.');
            }
            if (strlen($reason) < 10 || strlen($reason) > 2000) {
                throw new RuntimeException('Provide a reason between 10 and 2,000 characters.');
            }

            $pdo = db();
            $pdo->beginTransaction();
            $userLock = $pdo->prepare('SELECT user_id FROM user_account WHERE user_id = :user_id FOR UPDATE');
            $userLock->execute(['user_id' => $userId]);
            $overlap = $pdo->prepare(
                "SELECT 1
                 FROM staff_leave_request
                 WHERE user_id = :user_id
                   AND status IN ('Pending', 'Approved')
                   AND start_date <= CAST(:end_date AS DATE)
                   AND end_date >= CAST(:start_date AS DATE)
                 LIMIT 1
                 FOR UPDATE"
            );
            $overlap->execute([
                'user_id' => $userId,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
            if ($overlap->fetchColumn()) {
                throw new RuntimeException('You already have a pending or approved leave request that overlaps these dates.');
            }
            $insert = $pdo->prepare(
                'INSERT INTO staff_leave_request (user_id, leave_type, start_date, end_date, reason)
                 VALUES (:user_id, :leave_type, CAST(:start_date AS DATE), CAST(:end_date AS DATE), :reason)
                 RETURNING leave_request_id'
            );
            $insert->execute([
                'user_id' => $userId,
                'leave_type' => $leaveType,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'reason' => $reason,
            ]);
            $requestId = (int) $insert->fetchColumn();
            staff_audit('STAFF_LEAVE_REQUEST_SUBMITTED', 'staff_leave_request', $requestId, [
                'leave_type' => $leaveType,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
            $pdo->commit();
            $_SESSION['staff_leave_message'] = 'Your leave request has been submitted for administrator review.';
            header('Location: staff_leave.php');
            exit;
        }

        if ($action === 'withdraw_leave' && !$isAdmin) {
            $requestId = filter_var($_POST['leave_request_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$requestId || $requestId < 1) {
                throw new RuntimeException('Select a valid leave request.');
            }
            $pdo = db();
            $pdo->beginTransaction();
            $withdraw = $pdo->prepare(
                "UPDATE staff_leave_request
                 SET status = 'Withdrawn'
                 WHERE leave_request_id = :request_id
                   AND user_id = :user_id
                   AND status = 'Pending'
                 RETURNING start_date, end_date"
            );
            $withdraw->execute(['request_id' => $requestId, 'user_id' => $userId]);
            $request = $withdraw->fetch();
            if (!$request) {
                throw new RuntimeException('This request can no longer be withdrawn.');
            }
            staff_audit('STAFF_LEAVE_REQUEST_WITHDRAWN', 'staff_leave_request', (int) $requestId, [
                'start_date' => $request['start_date'],
                'end_date' => $request['end_date'],
            ]);
            $pdo->commit();
            $_SESSION['staff_leave_message'] = 'Your pending leave request was withdrawn.';
            header('Location: staff_leave.php');
            exit;
        }

        if ($action === 'review_leave' && $isAdmin) {
            $requestId = filter_var($_POST['leave_request_id'] ?? '', FILTER_VALIDATE_INT);
            $decision = (string) ($_POST['decision'] ?? '');
            $adminNote = trim((string) ($_POST['admin_note'] ?? ''));
            if (!$requestId || $requestId < 1 || !in_array($decision, ['Approved', 'Rejected'], true)) {
                throw new RuntimeException('Choose a valid leave request and decision.');
            }
            if (strlen($adminNote) > 1000) {
                throw new RuntimeException('The administrator note cannot exceed 1,000 characters.');
            }

            $pdo = db();
            $pdo->beginTransaction();
            $requestLookup = $pdo->prepare(
                "SELECT user_id, start_date, end_date
                 FROM staff_leave_request
                 WHERE leave_request_id = :request_id AND status = 'Pending'
                 FOR UPDATE"
            );
            $requestLookup->execute(['request_id' => $requestId]);
            $request = $requestLookup->fetch();
            if (!$request) {
                throw new RuntimeException('This request has already been reviewed or no longer exists.');
            }

            if ($decision === 'Approved') {
                $userLock = $pdo->prepare('SELECT user_id FROM user_account WHERE user_id = :user_id FOR UPDATE');
                $userLock->execute(['user_id' => (int) $request['user_id']]);
                $overlap = $pdo->prepare(
                    "SELECT 1
                     FROM staff_leave_request
                     WHERE user_id = :user_id
                       AND leave_request_id <> :request_id
                       AND status = 'Approved'
                       AND start_date <= CAST(:end_date AS DATE)
                       AND end_date >= CAST(:start_date AS DATE)
                     LIMIT 1"
                );
                $overlap->execute([
                    'user_id' => (int) $request['user_id'],
                    'request_id' => (int) $requestId,
                    'start_date' => $request['start_date'],
                    'end_date' => $request['end_date'],
                ]);
                if ($overlap->fetchColumn()) {
                    throw new RuntimeException('This leave overlaps another approved request for the same staff member.');
                }
            }

            $review = $pdo->prepare(
                "UPDATE staff_leave_request
                 SET status = :status,
                     reviewed_by = :reviewed_by,
                     reviewed_at = CURRENT_TIMESTAMP,
                     admin_note = NULLIF(:admin_note, '')
                 WHERE leave_request_id = :request_id AND status = 'Pending'"
            );
            $review->execute([
                'status' => $decision,
                'reviewed_by' => $userId,
                'admin_note' => $adminNote,
                'request_id' => (int) $requestId,
            ]);
            if ($review->rowCount() !== 1) {
                throw new RuntimeException('The leave request changed while being reviewed. Refresh and try again.');
            }
            staff_audit('STAFF_LEAVE_REQUEST_' . strtoupper($decision), 'staff_leave_request', (int) $requestId, [
                'start_date' => $request['start_date'],
                'end_date' => $request['end_date'],
                'admin_note' => $adminNote,
            ]);
            $pdo->commit();
            $_SESSION['staff_leave_message'] = 'Leave request ' . strtolower($decision) . '.';
            header('Location: staff_leave.php?status=Pending');
            exit;
        }

        throw new RuntimeException('This action is not available for your account.');
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof PDOException) {
            error_log('Staff leave database error: ' . $exception->getMessage());
            $errors[] = 'The leave request could not be saved because of a database error. Please try again.';
        } elseif ($exception instanceof RuntimeException) {
            $errors[] = $exception->getMessage();
        } else {
            error_log('Staff leave request error: ' . $exception->getMessage());
            $errors[] = 'The leave request could not be saved. Please try again.';
        }
    }
}

$conditions = [];
$parameters = [];
if (!$isAdmin) {
    $conditions[] = 'lr.user_id = :user_id';
    $parameters['user_id'] = $userId;
}
if ($statusFilter !== 'all') {
    $conditions[] = 'lr.status = :status';
    $parameters['status'] = $statusFilter;
}
$whereClause = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
$countStatement = db()->prepare("SELECT COUNT(*) FROM staff_leave_request lr{$whereClause}");
$countStatement->execute($parameters);
$totalRows = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $pageSize));
$currentPage = min($currentPage, $totalPages);
$requestStatement = db()->prepare(
    "SELECT lr.leave_request_id, lr.user_id, lr.leave_type, lr.start_date, lr.end_date,
            lr.reason, lr.status, lr.requested_at, lr.reviewed_at, lr.admin_note,
            s.first_name, s.last_name, s.position
     FROM staff_leave_request lr
     JOIN staff s ON s.user_id = lr.user_id{$whereClause}
     ORDER BY CASE lr.status WHEN 'Pending' THEN 0 ELSE 1 END,
              lr.start_date DESC, lr.requested_at DESC
     LIMIT :page_size OFFSET :offset"
);
foreach ($parameters as $name => $value) {
    $requestStatement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$requestStatement->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
$requestStatement->bindValue(':offset', ($currentPage - 1) * $pageSize, PDO::PARAM_INT);
$requestStatement->execute();
$leaveRequests = $requestStatement->fetchAll();

$pendingCount = $isAdmin
    ? (int) db()->query("SELECT COUNT(*) FROM staff_leave_request WHERE status = 'Pending'")->fetchColumn()
    : 0;
$pageTitle = $isAdmin ? 'Staff leave requests' : 'File leave';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page staff-page staff-leave-page">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="<?= $isAdmin ? 'admin_analytics.php' : 'staff_dashboard.php' ?>"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small><?= $isAdmin ? 'Admin Portal' : 'Staff Portal' ?></small></span></a>
        <?php require __DIR__ . '/includes/staff_nav.php'; ?>
    </aside>
    <section class="dashboard-content">
        <div class="staff-main">
            <header class="staff-leave-heading">
                <div><p class="eyebrow"><?= $isAdmin ? 'STAFF ADMINISTRATION' : 'STAFF SERVICES' ?></p><h1><?= $isAdmin ? 'Leave requests' : 'Leave management' ?></h1><p><?= $isAdmin ? 'Review staff leave requests and record clear, consistent decisions.' : 'Submit planned leave and keep track of administrator decisions.' ?></p></div>
                <?php if ($isAdmin): ?><span class="staff-leave-pending"><i class="bi bi-hourglass-split"></i><?= number_format($pendingCount) ?> pending</span><?php else: ?><span class="staff-leave-policy"><i class="bi bi-calendar-check"></i> File at least 3 calendar days ahead</span><?php endif; ?>
            </header>

            <?php if ($message !== ''): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
            <?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>

            <?php if (!$isAdmin): ?>
                <section class="staff-panel staff-leave-form-panel">
                    <div class="staff-leave-section-heading"><span><i class="bi bi-calendar2-plus"></i></span><div><h2>New leave request</h2><p>Requests must be filed at least 3 calendar days before the first day of leave.</p></div></div>
                    <form method="post" class="staff-leave-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="submit_leave">
                        <label>Leave type<select class="form-control" name="leave_type" required><option value="">Select type</option><?php foreach ($leaveTypes as $leaveType): ?><option value="<?= e($leaveType) ?>"><?= e($leaveType) ?></option><?php endforeach; ?></select></label>
                        <label>First day of leave<input class="form-control" type="date" name="start_date" min="<?= e($earliestLeaveDate->format('Y-m-d')) ?>" required></label>
                        <label>Last day of leave<input class="form-control" type="date" name="end_date" min="<?= e($earliestLeaveDate->format('Y-m-d')) ?>" required></label>
                        <label class="staff-leave-reason">Reason<textarea class="form-control" name="reason" minlength="10" maxlength="2000" rows="4" placeholder="Provide enough detail for the administrator to review your request." required></textarea><small>10–2,000 characters. Please avoid including unnecessary sensitive medical details.</small></label>
                        <div class="staff-leave-form-footer"><span><i class="bi bi-info-circle"></i> Submission does not mean the leave is approved.</span><button class="btn btn-primary" type="submit"><i class="bi bi-send"></i> Submit for approval</button></div>
                    </form>
                </section>
            <?php endif; ?>

            <section class="staff-panel staff-leave-list-panel">
                <div class="staff-leave-list-heading"><div><p class="eyebrow"><?= $isAdmin ? 'REVIEW QUEUE' : 'YOUR RECORD' ?></p><h2><?= $isAdmin ? 'Staff leave requests' : 'Your leave requests' ?></h2><p><?= $isAdmin ? 'Pending requests appear first. Review each request before the leave dates.' : 'Your requests and their latest approval status.' ?></p></div>
                    <form method="get" class="staff-leave-filter"><label for="leave-status">Status</label><select class="form-control" id="leave-status" name="status"><option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All requests</option><?php foreach (['Pending', 'Approved', 'Rejected', 'Withdrawn'] as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select><button class="btn btn-outline-primary" type="submit">Filter</button></form>
                </div>
                <?php if ($leaveRequests === []): ?>
                    <div class="staff-leave-empty"><i class="bi bi-calendar2-x"></i><strong>No leave requests found</strong><span><?= $isAdmin ? 'There are no requests matching this status.' : 'Submit a request above when you need planned leave.' ?></span></div>
                <?php else: ?>
                    <div class="staff-leave-request-list">
                        <?php foreach ($leaveRequests as $request): ?>
                            <?php
                            $start = new DateTimeImmutable((string) $request['start_date'], $philippineTimeZone);
                            $end = new DateTimeImmutable((string) $request['end_date'], $philippineTimeZone);
                            $dateLabel = $start->format('M j, Y') . ($start == $end ? '' : ' – ' . $end->format('M j, Y'));
                            $statusClass = strtolower((string) $request['status']);
                            ?>
                            <article class="staff-leave-request-card">
                                <div class="staff-leave-request-top">
                                    <div class="staff-leave-request-title"><span class="staff-leave-type-icon"><i class="bi bi-calendar2-heart"></i></span><div><h3><?= e($request['leave_type']) ?> leave</h3><span><?= e($dateLabel) ?></span></div></div>
                                    <span class="staff-leave-status status-<?= e($statusClass) ?>"><?= e($request['status']) ?></span>
                                </div>
                                <?php if ($isAdmin): ?><p class="staff-leave-requester"><i class="bi bi-person"></i><?= e(trim($request['first_name'] . ' ' . $request['last_name'])) ?><?= $request['position'] ? ' · ' . e($request['position']) : '' ?></p><?php endif; ?>
                                <p class="staff-leave-reason-text"><?= nl2br(e($request['reason'])) ?></p>
                                <div class="staff-leave-request-meta"><span>Filed <?= e((new DateTimeImmutable((string) $request['requested_at']))->setTimezone($philippineTimeZone)->format('M j, Y g:i A')) ?></span><?php if ($request['reviewed_at']): ?><span>Reviewed <?= e((new DateTimeImmutable((string) $request['reviewed_at']))->setTimezone($philippineTimeZone)->format('M j, Y')) ?></span><?php endif; ?></div>
                                <?php if ($request['admin_note']): ?><div class="staff-leave-admin-note"><strong>Administrator note</strong><span><?= nl2br(e($request['admin_note'])) ?></span></div><?php endif; ?>
                                <?php if ($isAdmin && $request['status'] === 'Pending'): ?>
                                    <form method="post" class="staff-leave-review-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="review_leave">
                                        <input type="hidden" name="leave_request_id" value="<?= (int) $request['leave_request_id'] ?>">
                                        <label class="staff-leave-admin-note-field">Note to staff <textarea class="form-control" name="admin_note" maxlength="1000" rows="2" placeholder="Optional approval or rejection note"></textarea></label>
                                        <div><button class="btn btn-success" name="decision" value="Approved" type="submit"><i class="bi bi-check2-circle"></i> Approve</button><button class="btn btn-outline-danger" name="decision" value="Rejected" type="submit"><i class="bi bi-x-circle"></i> Reject</button></div>
                                    </form>
                                <?php elseif (!$isAdmin && $request['status'] === 'Pending'): ?>
                                    <form method="post" class="staff-leave-withdraw-form" onsubmit="return confirm('Withdraw this pending leave request?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="withdraw_leave"><input type="hidden" name="leave_request_id" value="<?= (int) $request['leave_request_id'] ?>"><button class="btn btn-sm btn-outline-secondary" type="submit">Withdraw request</button></form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <nav class="request-pagination" aria-label="Leave request pages"><span>Showing <?= (($currentPage - 1) * $pageSize + 1) ?>–<?= min($currentPage * $pageSize, $totalRows) ?> of <?= number_format($totalRows) ?> requests</span><div><?php if ($currentPage > 1): ?><a class="btn btn-sm btn-outline-primary" href="<?= e('staff_leave.php?' . http_build_query(['status' => $statusFilter, 'page' => $currentPage - 1])) ?>">Previous</a><?php endif; ?><span>Page <?= $currentPage ?> of <?= $totalPages ?></span><?php if ($currentPage < $totalPages): ?><a class="btn btn-sm btn-outline-primary" href="<?= e('staff_leave.php?' . http_build_query(['status' => $statusFilter, 'page' => $currentPage + 1])) ?>">Next</a><?php endif; ?></div></nav>
                <?php endif; ?>
            </section>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
