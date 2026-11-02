<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/staff.php';
require_admin();
ensure_admin_schema();
ensure_staff_tables();

$errors = [];
$searchInput = $_GET['search'] ?? '';
$search = is_string($searchInput) ? trim($searchInput) : '';
if (!is_string($searchInput)) {
    $errors[] = 'Search text must be a single value.';
}
$search = function_exists('mb_substr') ? mb_substr($search, 0, 150) : substr($search, 0, 150);
$dateFromInput = $_GET['date_from'] ?? '';
$dateToInput = $_GET['date_to'] ?? '';
$dateFrom = is_string($dateFromInput) ? trim($dateFromInput) : '';
$dateTo = is_string($dateToInput) ? trim($dateToInput) : '';
if (!is_string($dateFromInput) || !is_string($dateToInput)) {
    $errors[] = 'Date filters must be single date values.';
}
$conditions = [];
$parameters = [];

foreach (['date_from' => $dateFrom, 'date_to' => $dateTo] as $field => $dateValue) {
    if ($dateValue === '') {
        continue;
    }
    $parsedDate = DateTime::createFromFormat('!Y-m-d', $dateValue);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $dateValue) {
        $errors[] = 'Enter valid dates using the date picker.';
        continue;
    }
    if ($field === 'date_from') {
        $conditions[] = "CAST(a.action_timestamp AT TIME ZONE 'Asia/Manila' AS DATE) >= CAST(:date_from AS DATE)";
        $parameters['date_from'] = $dateValue;
    } else {
        $conditions[] = "CAST(a.action_timestamp AT TIME ZONE 'Asia/Manila' AS DATE) <= CAST(:date_to AS DATE)";
        $parameters['date_to'] = $dateValue;
    }
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $errors[] = 'The start date must be on or before the end date.';
    $conditions = array_values(array_filter(
        $conditions,
        static fn (string $condition): bool => !str_contains($condition, ':date_from') && !str_contains($condition, ':date_to')
    ));
    unset($parameters['date_from'], $parameters['date_to']);
}
if ($search !== '') {
    $conditions[] = "(
        COALESCE(s.first_name || ' ' || s.last_name, u.username, 'Deleted account') ILIKE :search_actor
        OR COALESCE(u.username, '') ILIKE :search_username
        OR to_char(a.action_timestamp AT TIME ZONE 'Asia/Manila', 'YYYY-MM-DD HH24:MI:SS') ILIKE :search_timestamp
        OR a.action ILIKE :search_action
        OR a.audit_id::text ILIKE :search_audit_id
        OR COALESCE(a.user_id::text, '') ILIKE :search_user_id
        OR COALESCE(a.affected_table, '') ILIKE :search_table
        OR COALESCE(a.affected_record_id, '') ILIKE :search_record
        OR COALESCE(a.details::text, '') ILIKE :search_details
    )";
    $like = '%' . $search . '%';
    foreach (['actor', 'username', 'timestamp', 'action', 'audit_id', 'user_id', 'table', 'record', 'details'] as $field) {
        $parameters['search_' . $field] = $like;
    }
}

$whereClause = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
$pageSize = 10;
$currentPage = max(1, (int) (filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
$countStatement = db()->prepare(
    "SELECT COUNT(*)
     FROM audit_log a
     LEFT JOIN user_account u ON u.user_id = a.user_id
     LEFT JOIN staff s ON s.user_id = u.user_id{$whereClause}"
);
$countStatement->execute($parameters);
$totalRows = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $pageSize));
$currentPage = min($currentPage, $totalPages);
$logsStatement = db()->prepare(
    "SELECT a.audit_id, a.action, a.affected_table, a.affected_record_id, a.details,
            a.action_timestamp AT TIME ZONE 'Asia/Manila' AS action_timestamp_local,
            COALESCE(s.first_name || ' ' || s.last_name, u.username, 'Deleted account') AS actor
     FROM audit_log a
     LEFT JOIN user_account u ON u.user_id = a.user_id
     LEFT JOIN staff s ON s.user_id = u.user_id{$whereClause}
     ORDER BY a.action_timestamp DESC
     LIMIT :page_size OFFSET :offset"
);
foreach ($parameters as $name => $value) {
    $logsStatement->bindValue(':' . $name, $value);
}
$logsStatement->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
$logsStatement->bindValue(':offset', ($currentPage - 1) * $pageSize, PDO::PARAM_INT);
$logsStatement->execute();
$auditLogs = $logsStatement->fetchAll();

$timeStaffInput = $_GET['time_staff_id'] ?? '';
$timeStaffId = $timeStaffInput === '' ? null : filter_var($timeStaffInput, FILTER_VALIDATE_INT);
if ($timeStaffInput !== '' && (!$timeStaffId || $timeStaffId < 1)) {
    $errors[] = 'Select a valid staff member for the time sheet.';
    $timeStaffId = null;
}
$timeStaffStatement = db()->query(
    "SELECT s.staff_id, s.first_name, s.last_name, s.position,
            COUNT(t.time_log_id) AS shifts,
            COALESCE(SUM(COALESCE(
                t.total_seconds,
                FLOOR(EXTRACT(EPOCH FROM (
                    COALESCE(
                        t.clock_out,
                        LEAST(CURRENT_TIMESTAMP, COALESCE(t.last_activity_at, t.clock_in) + INTERVAL '10 minutes')
                    ) - t.clock_in
                )))::INT
            )), 0) AS total_seconds,
            COUNT(t.time_log_id) FILTER (WHERE t.clock_out IS NULL) AS active_sessions
     FROM staff s
     LEFT JOIN staff_time_log t ON t.user_id = s.user_id
     GROUP BY s.staff_id, s.first_name, s.last_name, s.position
     ORDER BY s.last_name, s.first_name"
);
$timeStaffMembers = $timeStaffStatement->fetchAll();
$selectedTimeStaff = null;
foreach ($timeStaffMembers as $member) {
    if ((int) $member['staff_id'] === $timeStaffId) {
        $selectedTimeStaff = $member;
        break;
    }
}
if ($timeStaffId !== null && $selectedTimeStaff === null) {
    $errors[] = 'The selected staff member could not be found.';
    $timeStaffId = null;
}
$timePageSize = 10;
$timeCurrentPage = max(1, (int) (filter_var($_GET['time_page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
$timeCountStatement = db()->prepare(
    'SELECT COUNT(*)
     FROM staff_time_log t
     JOIN staff s ON s.user_id = t.user_id
     WHERE s.staff_id = COALESCE(:staff_id, s.staff_id)'
);
$timeCountStatement->bindValue(':staff_id', $timeStaffId, $timeStaffId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
$timeCountStatement->execute();
$timeTotalRows = (int) $timeCountStatement->fetchColumn();
$timeTotalPages = max(1, (int) ceil($timeTotalRows / $timePageSize));
$timeCurrentPage = min($timeCurrentPage, $timeTotalPages);
$timeLogsStatement = db()->prepare(
    "SELECT t.clock_in AT TIME ZONE 'Asia/Manila' AS clock_in_local,
            t.clock_out AT TIME ZONE 'Asia/Manila' AS clock_out_local,
            COALESCE(
                t.total_seconds,
                FLOOR(EXTRACT(EPOCH FROM (
                    COALESCE(
                        t.clock_out,
                        LEAST(CURRENT_TIMESTAMP, COALESCE(t.last_activity_at, t.clock_in) + INTERVAL '10 minutes')
                    ) - t.clock_in
                )))::INT
            ) AS total_seconds,
            s.first_name, s.last_name, s.position
     FROM staff_time_log t
     JOIN staff s ON s.user_id = t.user_id
            WHERE s.staff_id = COALESCE(:staff_id, s.staff_id)
     ORDER BY t.clock_in DESC
     LIMIT :page_size OFFSET :offset"
);
$timeLogsStatement->bindValue(':staff_id', $timeStaffId, $timeStaffId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
$timeLogsStatement->bindValue(':page_size', $timePageSize, PDO::PARAM_INT);
$timeLogsStatement->bindValue(':offset', ($timeCurrentPage - 1) * $timePageSize, PDO::PARAM_INT);
$timeLogsStatement->execute();
$timeLogs = $timeLogsStatement->fetchAll();
$timePageParameters = ['time_staff_id' => $timeStaffId];
$philippineTimeZone = new DateTimeZone(APP_TIMEZONE);
$pageParameters = array_filter([
    'search' => $search,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
], static fn (string $value): bool => $value !== '');
$auditPageUrl = static fn (int $page): string => 'admin_audit.php?' . http_build_query(
    $pageParameters + $timePageParameters + ['time_page' => $timeCurrentPage, 'page' => $page]
);
$timePageUrl = static fn (int $page): string => 'admin_audit.php?' . http_build_query(
    $pageParameters + ['page' => $currentPage, 'time_page' => $page] + $timePageParameters
);

$pageTitle = 'Audit and time logs';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page staff-page"><aside class="dashboard-sidebar"><a class="dashboard-brand" href="staff_dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Admin Portal</small></span></a><?php require __DIR__ . '/includes/staff_nav.php'; ?></aside><section class="dashboard-content"><div class="staff-main"><div class="staff-hero compact"><div><p class="eyebrow">SECURITY &amp; ACCOUNTABILITY</p><h1>Audit &amp; Time Logs</h1><p>Review staff attendance and every recorded processing action.</p></div></div>
    <section class="staff-panel p-3 mb-3 admin-timesheet-panel">
        <div class="admin-timesheet-heading"><div><h2 class="h5">Staff time sheets</h2><p>Choose a staff member to review their individual attendance record.</p></div></div>
        <form method="get" class="audit-filters admin-timesheet-filter">
            <?php foreach (['search' => $search, 'date_from' => $dateFrom, 'date_to' => $dateTo] as $filterName => $filterValue): ?>
                <?php if ($filterValue !== ''): ?><input type="hidden" name="<?= e($filterName) ?>" value="<?= e($filterValue) ?>"><?php endif; ?>
            <?php endforeach; ?>
            <label for="time-staff-filter">Filter by staff name
                <select class="form-control" id="time-staff-filter" name="time_staff_id">
                    <option value="">All staff — view individual sheets below</option>
                    <?php foreach ($timeStaffMembers as $member): ?>
                        <option value="<?= (int) $member['staff_id'] ?>" <?= $timeStaffId === (int) $member['staff_id'] ? 'selected' : '' ?>><?= e(trim($member['first_name'] . ' ' . $member['last_name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i> View time sheet</button>
            <?php if ($timeStaffId !== null): ?><a class="btn btn-light" href="<?= e('admin_audit.php?' . http_build_query($pageParameters)) ?>">All staff</a><?php endif; ?>
        </form>

        <?php if ($selectedTimeStaff === null): ?>
            <div class="admin-timesheet-staff-grid">
                <?php foreach ($timeStaffMembers as $member): ?>
                    <?php
                    $staffName = trim($member['first_name'] . ' ' . $member['last_name']);
                    $staffHours = intdiv((int) $member['total_seconds'], 3600);
                    $staffMinutes = intdiv((int) $member['total_seconds'] % 3600, 60);
                    ?>
                    <article class="admin-timesheet-staff-card">
                        <span class="admin-timesheet-avatar"><?= e(strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1))) ?></span>
                        <div class="admin-timesheet-staff-info"><strong><?= e($staffName) ?></strong><small><?= e($member['position'] ?: 'Staff') ?></small></div>
                        <div class="admin-timesheet-staff-stats"><span><strong><?= number_format((int) $member['shifts']) ?></strong><small>shifts</small></span><span><strong><?= number_format($staffHours) ?>h <?= number_format($staffMinutes) ?>m</strong><small>total recorded</small></span><?php if ((int) $member['active_sessions'] > 0): ?><span class="admin-timesheet-active"><i class="bi bi-circle-fill"></i> Active now</span><?php endif; ?></div>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e('admin_audit.php?' . http_build_query($pageParameters + ['time_staff_id' => (int) $member['staff_id']])) ?>">Open time sheet <i class="bi bi-arrow-right"></i></a>
                    </article>
                <?php endforeach; ?>
                <?php if ($timeStaffMembers === []): ?><p class="analytics-empty">No staff accounts are available yet.</p><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="admin-timesheet-selected">
                <span class="admin-timesheet-avatar"><?= e(strtoupper(substr($selectedTimeStaff['first_name'], 0, 1) . substr($selectedTimeStaff['last_name'], 0, 1))) ?></span>
                <div><small>INDIVIDUAL TIME SHEET</small><h3><?= e(trim($selectedTimeStaff['first_name'] . ' ' . $selectedTimeStaff['last_name'])) ?></h3><span><?= e($selectedTimeStaff['position'] ?: 'Staff') ?> · <?= number_format((int) $selectedTimeStaff['shifts']) ?> shifts · <?= number_format(intdiv((int) $selectedTimeStaff['total_seconds'], 3600)) ?>h <?= number_format(intdiv((int) $selectedTimeStaff['total_seconds'] % 3600, 60)) ?>m total</span></div>
            </div>
            <div class="table-responsive"><table class="staff-table admin-timesheet-table"><thead><tr><th>Work date</th><th>Clock in</th><th>Clock out</th><th>Time worked</th><th>Session</th></tr></thead><tbody>
                <?php foreach ($timeLogs as $log): ?>
                    <?php
                    $totalSeconds = $log['total_seconds'] === null ? null : max(0, (int) $log['total_seconds']);
                    $clockIn = new DateTimeImmutable((string) $log['clock_in_local'], $philippineTimeZone);
                    ?>
                    <tr><td><?= e($clockIn->format('M j, Y')) ?></td><td><?= e($clockIn->format('g:i:s A')) ?></td><td><?= $log['clock_out_local'] !== null ? e((new DateTimeImmutable((string) $log['clock_out_local'], $philippineTimeZone))->format('g:i:s A')) : '—' ?></td><td><?= $totalSeconds === null ? 'Calculating' : e(sprintf('%02d:%02d:%02d', intdiv($totalSeconds, 3600), intdiv($totalSeconds % 3600, 60), $totalSeconds % 60)) ?></td><td><?= $log['clock_out_local'] !== null ? '<span class="staff-status staff-status-complete">Completed</span>' : '<span class="staff-status staff-status-progress">Active</span>' ?></td></tr>
                <?php endforeach; ?>
                <?php if ($timeLogs === []): ?><tr><td colspan="5">No time-sheet entries found for this staff member.</td></tr><?php endif; ?>
            </tbody></table></div>
            <nav class="request-pagination" aria-label="Staff time sheet pages"><span>Showing <?= $timeTotalRows === 0 ? 0 : (($timeCurrentPage - 1) * $timePageSize + 1) ?>–<?= min($timeCurrentPage * $timePageSize, $timeTotalRows) ?> of <?= $timeTotalRows ?> shifts</span><div><?php if ($timeCurrentPage > 1): ?><a class="btn btn-sm btn-outline-primary" href="<?= e($timePageUrl($timeCurrentPage - 1)) ?>">Previous</a><?php endif; ?><span>Page <?= $timeCurrentPage ?> of <?= $timeTotalPages ?></span><?php if ($timeCurrentPage < $timeTotalPages): ?><a class="btn btn-sm btn-outline-primary" href="<?= e($timePageUrl($timeCurrentPage + 1)) ?>">Next</a><?php endif; ?></div></nav>
        <?php endif; ?>
    </section>
    <section class="staff-panel p-3"><h2 class="h5">Processing audit trail</h2><form method="get" class="audit-filters"><input type="hidden" name="time_page" value="<?= $timeCurrentPage ?>"><?php if ($timeStaffId !== null): ?><input type="hidden" name="time_staff_id" value="<?= $timeStaffId ?>"><?php endif; ?><label class="audit-search-field" for="audit-search">Search time, actor, user ID, log ID, action, record, table, or details<input id="audit-search" class="form-control" type="search" name="search" maxlength="150" value="<?= e($search) ?>" placeholder="e.g. staff name, REQUEST_STATUS_UPDATED, #24"></label><label for="audit-from">From (Philippine date)<input id="audit-from" class="form-control" type="date" name="date_from" value="<?= e($dateFrom) ?>"></label><label for="audit-to">To (Philippine date)<input id="audit-to" class="form-control" type="date" name="date_to" value="<?= e($dateTo) ?>"></label><button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Search logs</button><a class="btn btn-light" href="admin_audit.php">Clear</a></form>
        <?php foreach ($errors as $error): ?><div class="alert alert-warning"><?= e($error) ?></div><?php endforeach; ?>
        <p class="audit-results-count"><?= $totalRows ?> matching log<?= $totalRows === 1 ? '' : 's' ?> · newest first</p>
        <div class="table-responsive"><table class="staff-table"><thead><tr><th>Log #</th><th>Time (Philippine Time)</th><th>Actor</th><th>Action</th><th>Record</th><th>Details</th></tr></thead><tbody><?php foreach ($auditLogs as $log): ?><tr><td>#<?= (int) $log['audit_id'] ?></td><td><?= e((new DateTimeImmutable((string) $log['action_timestamp_local'], $philippineTimeZone))->format('M j, Y g:i:s A')) ?></td><td><?= e($log['actor']) ?></td><td><?= e($log['action']) ?></td><td><?= e(($log['affected_table'] ?? 'record') . ' #' . ($log['affected_record_id'] ?? '')) ?></td><td><?= e((string) json_encode($log['details'])) ?></td></tr><?php endforeach; ?><?php if ($auditLogs === []): ?><tr><td colspan="6">No audit entries match these filters.</td></tr><?php endif; ?></tbody></table></div>
        <nav class="request-pagination" aria-label="Audit log pages"><span>Showing <?= $totalRows === 0 ? 0 : (($currentPage - 1) * $pageSize + 1) ?>–<?= min($currentPage * $pageSize, $totalRows) ?> of <?= $totalRows ?></span><div><?php if ($currentPage > 1): ?><a class="btn btn-sm btn-outline-primary" href="<?= e($auditPageUrl($currentPage - 1)) ?>">Previous</a><?php endif; ?><span>Page <?= $currentPage ?> of <?= $totalPages ?></span><?php if ($currentPage < $totalPages): ?><a class="btn btn-sm btn-outline-primary" href="<?= e($auditPageUrl($currentPage + 1)) ?>">Next</a><?php endif; ?></div></nav>
    </section>
</div></section></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
