<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/includes/households.php';
require_bhw_or_admin();
ensure_household_schema();

$errors = [];
$message = '';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create_household') {
            $address = trim((string) ($_POST['address'] ?? ''));
            $purok = trim((string) ($_POST['purok'] ?? ''));
            if ($address === '' || $purok === '') {
                throw new RuntimeException('Household address and purok are required.');
            }
            $insert = $pdo->prepare(
                'INSERT INTO household_record (address, purok)
                 VALUES (:address, :purok) RETURNING household_id'
            );
            $insert->execute(['address' => $address, 'purok' => $purok]);
            $householdId = (int) $insert->fetchColumn();
            staff_audit('HOUSEHOLD_CREATED', 'household_record', $householdId, ['purok' => $purok]);
            $message = 'Household created.';
        } elseif ($action === 'update_household') {
            $householdId = filter_var($_POST['household_id'] ?? '', FILTER_VALIDATE_INT);
            $address = trim((string) ($_POST['address'] ?? ''));
            $purok = trim((string) ($_POST['purok'] ?? ''));
            if (!$householdId || $address === '' || $purok === '') {
                throw new RuntimeException('Select a household and provide its address and purok.');
            }
            $update = $pdo->prepare(
                'UPDATE household_record SET address = :address, purok = :purok
                 WHERE household_id = :household_id'
            );
            $update->execute(['address' => $address, 'purok' => $purok, 'household_id' => $householdId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The selected household could not be found.');
            }
            staff_audit('HOUSEHOLD_UPDATED', 'household_record', $householdId, ['purok' => $purok]);
            $message = 'Household details updated.';
        } elseif ($action === 'add_member') {
            $householdId = filter_var($_POST['household_id'] ?? '', FILTER_VALIDATE_INT);
            $registeredResidentId = filter_var($_POST['resident_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
            $relationship = trim((string) ($_POST['relationship_to_head'] ?? ''));
            $isHead = isset($_POST['is_household_head']);
            if (!$householdId || $relationship === '') {
                throw new RuntimeException('Select a household and provide the member’s relationship to the head.');
            }
            $householdCheck = $pdo->prepare('SELECT 1 FROM household_record WHERE household_id = :household_id');
            $householdCheck->execute(['household_id' => $householdId]);
            if (!$householdCheck->fetchColumn()) {
                throw new RuntimeException('The selected household could not be found.');
            }

            if ($registeredResidentId) {
                $residentQuery = $pdo->prepare(
                    'SELECT resident_id, household_id, first_name, middle_name, last_name, birthdate,
                            gender, mobile_number
                     FROM resident WHERE resident_id = :resident_id'
                );
                $residentQuery->execute(['resident_id' => $registeredResidentId]);
                $resident = $residentQuery->fetch();
                if (!$resident || ($resident['household_id'] !== null && (int) $resident['household_id'] !== $householdId)) {
                    throw new RuntimeException('The selected resident is unavailable or already belongs to another household.');
                }
                $memberValues = [
                    'resident_id' => $registeredResidentId,
                    'first_name' => $resident['first_name'],
                    'middle_name' => $resident['middle_name'],
                    'last_name' => $resident['last_name'],
                    'birthdate' => $resident['birthdate'],
                    'gender' => $resident['gender'],
                    'contact_number' => $resident['mobile_number'],
                ];
            } else {
                $memberValues = [
                    'resident_id' => null,
                    'first_name' => trim((string) ($_POST['first_name'] ?? '')),
                    'middle_name' => trim((string) ($_POST['middle_name'] ?? '')),
                    'last_name' => trim((string) ($_POST['last_name'] ?? '')),
                    'birthdate' => trim((string) ($_POST['birthdate'] ?? '')),
                    'gender' => trim((string) ($_POST['gender'] ?? '')),
                    'contact_number' => trim((string) ($_POST['contact_number'] ?? '')),
                ];
                $parsedBirthdate = DateTime::createFromFormat('!Y-m-d', $memberValues['birthdate']);
                if (
                    $memberValues['first_name'] === ''
                    || $memberValues['last_name'] === ''
                    || !$parsedBirthdate
                    || $parsedBirthdate->format('Y-m-d') !== $memberValues['birthdate']
                    || $memberValues['birthdate'] > date('Y-m-d')
                ) {
                    throw new RuntimeException('Enter the unregistered member’s first name, last name, and valid birthdate.');
                }
                $duplicate = $pdo->prepare(
                    "SELECT 1 FROM household_member
                     WHERE household_id = :household_id
                       AND resident_id IS NULL
                       AND LOWER(BTRIM(first_name)) = LOWER(BTRIM(:first_name))
                       AND LOWER(BTRIM(COALESCE(middle_name, ''))) = LOWER(BTRIM(:middle_name))
                       AND LOWER(BTRIM(last_name)) = LOWER(BTRIM(:last_name))
                       AND birthdate = CAST(:birthdate AS DATE)"
                );
                $duplicate->execute([
                    'household_id' => $householdId,
                    'first_name' => $memberValues['first_name'],
                    'middle_name' => $memberValues['middle_name'],
                    'last_name' => $memberValues['last_name'],
                    'birthdate' => $memberValues['birthdate'],
                ]);
                if ($duplicate->fetchColumn()) {
                    throw new RuntimeException('This household already has an unregistered member with the same name and birthdate.');
                }
            }

            $pdo->beginTransaction();
            try {
                if ($isHead) {
                    $clearHead = $pdo->prepare('UPDATE household_member SET is_household_head = FALSE WHERE household_id = :household_id');
                    $clearHead->execute(['household_id' => $householdId]);
                }
                $insertMember = $pdo->prepare(
                    'INSERT INTO household_member (
                        household_id, resident_id, first_name, middle_name, last_name, birthdate,
                        gender, relationship_to_head, contact_number, is_household_head
                     ) VALUES (
                        :household_id, :resident_id, :first_name, NULLIF(:middle_name, \'\'),
                        :last_name, :birthdate, NULLIF(:gender, \'\'), :relationship_to_head,
                        NULLIF(:contact_number, \'\'), :is_household_head
                     ) RETURNING member_id'
                );
                $insertMember->execute($memberValues + [
                    'household_id' => $householdId,
                    'relationship_to_head' => $relationship,
                    'is_household_head' => $isHead ? 1 : 0,
                ]);
                $memberId = (int) $insertMember->fetchColumn();
                if ($registeredResidentId) {
                    $linkResident = $pdo->prepare(
                        'UPDATE resident SET household_id = :household_id, updated_at = CURRENT_TIMESTAMP
                         WHERE resident_id = :resident_id'
                    );
                    $linkResident->execute(['household_id' => $householdId, 'resident_id' => $registeredResidentId]);
                }
                if ($isHead) {
                    $setHead = $pdo->prepare(
                        'UPDATE household_record SET household_head_resident_id = :resident_id
                         WHERE household_id = :household_id'
                    );
                    $setHead->execute(['resident_id' => $registeredResidentId, 'household_id' => $householdId]);
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            staff_audit('HOUSEHOLD_MEMBER_ADDED', 'household_member', $memberId, [
                'household_id' => $householdId,
                'resident_id' => $registeredResidentId,
                'is_household_head' => $isHead,
            ]);
            $message = 'Household member added.';
        } elseif ($action === 'remove_member') {
            $memberId = filter_var($_POST['member_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$memberId) {
                throw new RuntimeException('Select a valid household member.');
            }
            $memberQuery = $pdo->prepare(
                'SELECT household_id, resident_id,
                        CASE WHEN is_household_head THEN 1 ELSE 0 END AS is_household_head
                 FROM household_member WHERE member_id = :member_id'
            );
            $memberQuery->execute(['member_id' => $memberId]);
            $member = $memberQuery->fetch();
            if (!$member) {
                throw new RuntimeException('The selected household member could not be found.');
            }
            $pdo->beginTransaction();
            try {
                if ($member['resident_id'] !== null) {
                    $unlink = $pdo->prepare(
                        'UPDATE resident SET household_id = NULL, updated_at = CURRENT_TIMESTAMP
                         WHERE resident_id = :resident_id AND household_id = :household_id'
                    );
                    $unlink->execute([
                        'resident_id' => $member['resident_id'],
                        'household_id' => $member['household_id'],
                    ]);
                }
                $delete = $pdo->prepare('DELETE FROM household_member WHERE member_id = :member_id');
                $delete->execute(['member_id' => $memberId]);
                if ($member['is_household_head']) {
                    $clearHead = $pdo->prepare(
                        'UPDATE household_record SET household_head_resident_id = NULL
                         WHERE household_id = :household_id'
                    );
                    $clearHead->execute(['household_id' => $member['household_id']]);
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            staff_audit('HOUSEHOLD_MEMBER_REMOVED', 'household_member', $memberId, ['household_id' => $member['household_id']]);
            $message = 'Household member removed.';
        } else {
            throw new RuntimeException('Unknown household action.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Household registry update failed: ' . $exception->getMessage());
        $errors[] = $exception->getMessage();
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$householdStatement = $pdo->prepare(
    "SELECT h.household_id, h.address, h.purok, h.household_head_resident_id, h.created_at,
            COALESCE(
                NULLIF(CONCAT_WS(' ', head.first_name, head.middle_name, head.last_name), ''),
                NULLIF(CONCAT_WS(' ', head_member.first_name, head_member.middle_name, head_member.last_name), '')
            ) AS head_name,
            COUNT(hm.member_id) AS member_count
     FROM household_record h
     LEFT JOIN resident head ON head.resident_id = h.household_head_resident_id
     LEFT JOIN household_member head_member
        ON head_member.household_id = h.household_id AND head_member.is_household_head
     LEFT JOIN household_member hm ON hm.household_id = h.household_id
     WHERE (:search = '' OR h.address ILIKE :address_search OR h.purok ILIKE :purok_search
            OR CONCAT_WS(' ', head.first_name, head.last_name) ILIKE :head_search
            OR CONCAT_WS(' ', head_member.first_name, head_member.last_name) ILIKE :member_head_search)
     GROUP BY h.household_id, head.first_name, head.middle_name, head.last_name,
              head_member.first_name, head_member.middle_name, head_member.last_name
     ORDER BY h.purok, h.address, h.household_id"
);
$pattern = '%' . $search . '%';
$householdStatement->execute([
    'search' => $search,
    'address_search' => $pattern,
    'purok_search' => $pattern,
    'head_search' => $pattern,
    'member_head_search' => $pattern,
]);
$households = $householdStatement->fetchAll();
$memberStatement = $pdo->prepare(
    "SELECT hm.member_id, hm.household_id, hm.resident_id, hm.first_name, hm.middle_name,
            hm.last_name, hm.birthdate, hm.gender, hm.relationship_to_head,
            hm.contact_number, CASE WHEN hm.is_household_head THEN 1 ELSE 0 END AS is_household_head
     FROM household_member hm
     WHERE hm.household_id = :household_id
     ORDER BY hm.is_household_head DESC, hm.last_name, hm.first_name"
);
$unassignedResidents = $pdo->query(
    "SELECT r.resident_id, r.first_name, r.middle_name, r.last_name, r.birthdate, r.household_id
     FROM resident r
     WHERE r.household_id IS NULL
       AND NOT EXISTS (SELECT 1 FROM household_member hm WHERE hm.resident_id = r.resident_id)
     ORDER BY r.last_name, r.first_name"
)->fetchAll();
$pageTitle = 'Household registry';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page staff-page">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="<?= ($_SESSION['user']['role'] ?? '') === 'admin' ? 'admin_analytics.php' : 'staff_dashboard.php' ?>"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small><?= ($_SESSION['user']['role'] ?? '') === 'admin' ? 'Admin Portal' : 'BHW Portal' ?></small></span></a>
        <?php require __DIR__ . '/includes/staff_nav.php'; ?>
    </aside>
    <section class="dashboard-content">
        <div class="staff-main">
            <div class="staff-hero compact"><div><p class="eyebrow">BARANGAY HEALTH WORKERS</p><h1>Household registry</h1><p>Maintain household details and resident rosters. Unregistered members can be matched safely when they create an account.</p></div></div>
            <?php if ($message !== ''): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
            <?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>
            <div class="staff-request-layout">
                <section class="staff-panel p-4">
                    <h2 class="h5">Add household</h2>
                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_household">
                        <div class="col-12"><label class="form-label" for="new-address">Household address</label><input class="form-control" id="new-address" name="address" required></div>
                        <div class="col-12"><label class="form-label" for="new-purok">Purok</label><input class="form-control" id="new-purok" name="purok" required></div>
                        <div class="col-12"><button class="btn btn-primary" type="submit">Create household</button></div>
                    </form>
                </section>
                <section class="staff-panel p-4">
                    <h2 class="h5">Find households</h2>
                    <form method="get" class="d-flex gap-2"><input class="form-control" name="q" value="<?= e($search) ?>" placeholder="Search address, purok, or household head"><button class="btn btn-primary" type="submit">Search</button><?php if ($search !== ''): ?><a class="btn btn-light" href="staff_households.php">Clear</a><?php endif; ?></form>
                    <p class="text-muted mt-3 mb-0"><?= count($households) ?> household<?= count($households) === 1 ? '' : 's' ?> found</p>
                </section>
            </div>
            <div class="d-grid gap-3 mt-4">
                <?php foreach ($households as $household): ?>
                    <?php $memberStatement->execute(['household_id' => $household['household_id']]); $members = $memberStatement->fetchAll(); ?>
                    <section class="staff-panel p-4">
                        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                            <div><p class="eyebrow mb-1">HOUSEHOLD #<?= (int) $household['household_id'] ?></p><h2 class="h5 mb-1"><?= e($household['address']) ?></h2><p class="text-muted mb-0"><?= e($household['purok']) ?> · <?= (int) $household['member_count'] ?> listed members · Head: <?= e((string) ($household['head_name'] ?: 'Not assigned')) ?> · Created <?= e((new DateTime((string) $household['created_at']))->format('M j, Y')) ?></p></div>
                            <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_household"><input type="hidden" name="household_id" value="<?= (int) $household['household_id'] ?>">
                                <input class="form-control form-control-sm" name="address" value="<?= e($household['address']) ?>" aria-label="Household address" required>
                                <input class="form-control form-control-sm" name="purok" value="<?= e($household['purok']) ?>" aria-label="Purok" required>
                                <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                            </form>
                        </div>
                        <div class="table-responsive mt-3"><table class="staff-table"><thead><tr><th>Member</th><th>Birthdate</th><th>Gender</th><th>Relationship</th><th>Contact</th><th>Record</th><th></th></tr></thead><tbody>
                            <?php foreach ($members as $member): ?>
                                <tr><td><?= e(trim(implode(' ', array_filter([$member['first_name'], $member['middle_name'], $member['last_name']])))) ?><?= $member['is_household_head'] ? ' · Head' : '' ?></td><td><?= e((new DateTime((string) $member['birthdate']))->format('M j, Y')) ?></td><td><?= e((string) ($member['gender'] ?: '—')) ?></td><td><?= e($member['relationship_to_head']) ?></td><td><?= e((string) ($member['contact_number'] ?: '—')) ?></td><td><?= $member['resident_id'] ? 'Registered' : 'Not registered' ?></td><td><form method="post" onsubmit="return confirm('Remove this member from the household roster?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove_member"><input type="hidden" name="member_id" value="<?= (int) $member['member_id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Remove</button></form></td></tr>
                            <?php endforeach; ?>
                            <?php if ($members === []): ?><tr><td colspan="7">No members listed yet.</td></tr><?php endif; ?>
                        </tbody></table></div>
                        <details class="mt-3"><summary class="fw-semibold">Add a household member</summary>
                            <form method="post" class="row g-3 mt-1">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_member"><input type="hidden" name="household_id" value="<?= (int) $household['household_id'] ?>">
                                <?php if ($unassignedResidents !== []): ?><div class="col-12"><label class="form-label" for="resident-<?= (int) $household['household_id'] ?>">Link registered resident (optional)</label><select class="form-control" id="resident-<?= (int) $household['household_id'] ?>" name="resident_id"><option value="">Add an unregistered household member instead</option><?php foreach ($unassignedResidents as $resident): ?><option value="<?= (int) $resident['resident_id'] ?>"><?= e(trim(implode(' ', array_filter([$resident['first_name'], $resident['middle_name'], $resident['last_name']])))) ?> · <?= e($resident['birthdate']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                                <div class="col-md-4"><label class="form-label" for="first-<?= (int) $household['household_id'] ?>">First name</label><input class="form-control" id="first-<?= (int) $household['household_id'] ?>" name="first_name"></div>
                                <div class="col-md-4"><label class="form-label" for="middle-<?= (int) $household['household_id'] ?>">Middle name</label><input class="form-control" id="middle-<?= (int) $household['household_id'] ?>" name="middle_name"></div>
                                <div class="col-md-4"><label class="form-label" for="last-<?= (int) $household['household_id'] ?>">Last name</label><input class="form-control" id="last-<?= (int) $household['household_id'] ?>" name="last_name"></div>
                                <div class="col-md-4"><label class="form-label" for="birthdate-<?= (int) $household['household_id'] ?>">Birthdate</label><input class="form-control" id="birthdate-<?= (int) $household['household_id'] ?>" type="date" name="birthdate"></div>
                                <div class="col-md-4"><label class="form-label" for="gender-<?= (int) $household['household_id'] ?>">Gender</label><input class="form-control" id="gender-<?= (int) $household['household_id'] ?>" name="gender"></div>
                                <div class="col-md-4"><label class="form-label" for="relationship-<?= (int) $household['household_id'] ?>">Relationship to head</label><input class="form-control" id="relationship-<?= (int) $household['household_id'] ?>" name="relationship_to_head" placeholder="e.g. Spouse, Child, Parent" required></div>
                                <div class="col-md-6"><label class="form-label" for="contact-<?= (int) $household['household_id'] ?>">Contact number</label><input class="form-control" id="contact-<?= (int) $household['household_id'] ?>" name="contact_number"></div>
                                <div class="col-md-6 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_household_head"> <span class="form-check-label">Mark as household head</span></label></div>
                                <div class="col-12"><button class="btn btn-primary" type="submit">Add member</button></div>
                            </form>
                        </details>
                    </section>
                <?php endforeach; ?>
                <?php if ($households === []): ?><section class="staff-panel p-4 text-center text-muted">No households found. Create the first household above.</section><?php endif; ?>
            </div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
