<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/includes/document_pickup.php';
require_staff();
ensure_staff_tables();
ensure_document_pickup_schema();
header('Referrer-Policy: no-referrer');

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    exit('Pickup verification code is invalid.');
}
$tokenHash = hash('sha256', $token);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lookup = $pdo->prepare(
            "SELECT dr.request_id, dr.status
             FROM document_pickup_token pt
             JOIN document_request dr ON dr.request_id = pt.request_id
             WHERE pt.token_hash = :token_hash AND dr.status = 'Completed'
             FOR UPDATE OF pt, dr"
        );
        $lookup->execute(['token_hash' => $tokenHash]);
        $request = $lookup->fetch();
        if (!$request) {
            $pdo->rollBack();
            $error = 'This pickup code is invalid, already used, or no longer active.';
        } else {
            $release = $pdo->prepare(
                "UPDATE document_request
                 SET status = 'Released'
                 WHERE request_id = :request_id AND status = 'Completed'"
            );
            $release->execute(['request_id' => $request['request_id']]);
            if ($release->rowCount() !== 1) {
                $pdo->rollBack();
                $error = 'The request could not be released. Refresh and verify its current status.';
            } else {
                $removeToken = $pdo->prepare('DELETE FROM document_pickup_token WHERE request_id = :request_id');
                $removeToken->execute(['request_id' => $request['request_id']]);
                staff_audit('DOCUMENT_RELEASED_BY_PICKUP_QR', 'document_request', (int) $request['request_id']);
                $pdo->commit();
                $message = 'Pickup verified. The document request is now marked Released.';
            }
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Document pickup QR verification error: ' . $exception->getMessage());
        $error = 'Verification could not be completed. Please try again or check the request manually.';
    }
}

$statement = db()->prepare(
    "SELECT dr.request_id, dr.document_type, dr.name, dr.birthday, dr.purok_address,
            dr.status, dr.request_date, r.first_name, r.middle_name, r.last_name,
            r.residency_status
     FROM document_pickup_token pt
     JOIN document_request dr ON dr.request_id = pt.request_id
     JOIN resident r ON r.resident_id = dr.resident_id
     WHERE pt.token_hash = :token_hash AND dr.status = 'Completed'"
);
$statement->execute(['token_hash' => $tokenHash]);
$request = $statement->fetch();

$pageTitle = 'Verify document pickup';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page staff-page">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="staff_dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Staff Portal</small></span></a>
        <?php require __DIR__ . '/includes/staff_nav.php'; ?>
    </aside>
    <section class="dashboard-content">
        <header class="dashboard-topbar"><div class="dashboard-search"><i class="bi bi-shield-check"></i><span>Secure pickup verification</span></div><div class="dashboard-user"><span class="user-avatar"><i class="bi bi-person-fill"></i></span><span><strong><?= e($_SESSION['user']['full_name']) ?></strong><small>Staff</small></span></div></header>
        <div class="staff-main">
            <div class="staff-hero compact"><div><p class="eyebrow">DOCUMENT PICKUP</p><h1>Verify requester</h1><p>Match the person present with a valid photo ID before releasing the document.</p></div></div>
            <?php if ($message !== ''): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
            <?php if ($request): ?>
                <section class="staff-panel pickup-verification-card">
                    <span class="verification-badge"><i class="bi bi-shield-check"></i> Valid pickup QR</span>
                    <h2><?= e($request['name']) ?></h2>
                    <dl>
                        <div><dt>Request</dt><dd>REQ-<?= date('Y') ?>-<?= str_pad((string) $request['request_id'], 4, '0', STR_PAD_LEFT) ?></dd></div>
                        <div><dt>Document</dt><dd><?= e($request['document_type']) ?></dd></div>
                        <div><dt>Birthday</dt><dd><?= e((new DateTime($request['birthday']))->format('M j, Y')) ?></dd></div>
                        <div><dt>Address on request</dt><dd><?= e($request['purok_address']) ?></dd></div>
                        <div><dt>Resident record</dt><dd><?= e(trim(implode(' ', array_filter([$request['first_name'], $request['middle_name'], $request['last_name']])))) ?> · <?= e($request['residency_status']) ?></dd></div>
                    </dl>
                    <p class="alert alert-warning">Compare the name and photo on the person's valid ID with the request details above. Do not release the document if they do not match.</p>
                    <?php if ($request['status'] === 'Completed'): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="token" value="<?= e($token) ?>">
                            <button class="btn btn-primary" type="submit">Confirm identity and mark document released</button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php elseif ($message === ''): ?>
                <div class="alert alert-danger">This pickup code is invalid, already used, or the document is no longer ready for pickup.</div>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
