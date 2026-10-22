<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$stepNames = [
    1 => 'Personal Information',
    2 => 'Address & Family',
    3 => 'Employment & Education',
    4 => 'ID & Security',
    5 => 'Review & Submit',
];


$fields = [
    'username' => '', 
    'first_name' => '', 
    'middle_name' => '', 
    'last_name' => '',
    'birthdate' => '', 
    'place_of_birth' => '', 
    'gender' => '', 
    'civil_status' => '',
    'nationality' => 'Filipino', 
    'religion' => '', 
    'complete_address' => '', 
    'purok' => '',
    'residency_start_date' => '', 
    'mobile_number' => '', 
    'email' => '', 
    'voters_status' => '',
    'employment_status' => '', 
    'occupation' => '', 
    'educational_attainment' => '',
    'blood_type' => '', 
    'disability_special_needs' => '', 
    'emergency_contact_person' => '',
    'emergency_contact_number' => '', 
    'valid_id_file_path' => '', 
    'profile_image_path' => '',
];

if (!isset($_SESSION['registration'])) {
    $_SESSION['registration'] = ['values' => $fields, 'password_hash' => null];
}
$draft = &$_SESSION['registration'];
$values = array_merge($fields, $draft['values'] ?? []);
$step = max(1, min(5, (int) ($_GET['step'] ?? $_POST['step'] ?? 1)));
$errors = [];

$stepFields = [
    1 => ['username', 'first_name', 'middle_name', 'last_name', 'birthdate', 'place_of_birth', 'gender', 'civil_status', 'nationality', 'religion'],
    2 => ['complete_address', 'purok', 'residency_start_date', 'mobile_number', 'email', 'emergency_contact_person', 'emergency_contact_number'],
    3 => ['voters_status', 'employment_status', 'occupation', 'educational_attainment', 'blood_type', 'disability_special_needs'],
];

function registration_upload(string $field, string $prefix): ?string
{
    if (empty($_FILES[$field]['name'])) {
        return null;
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK || $_FILES[$field]['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Uploaded files must be smaller than 5 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Only JPG, PNG, or PDF files are accepted.');
    }
    $directory = __DIR__ . '/storage/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
        throw new RuntimeException('Unable to prepare secure file storage.');
    }
    $filename = $prefix . '-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('The uploaded file could not be saved.');
    }
    return 'storage/uploads/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? 'next');

    foreach ($stepFields[$step] ?? [] as $field) {
        $values[$field] = trim((string) ($_POST[$field] ?? ''));
    }

    if ($step === 1 && $action !== 'back') {
        if ($values['username'] === '' || !preg_match('/^[A-Za-z0-9._-]{3,100}$/', $values['username'])) {
            $errors[] = 'Username must be 3 to 100 characters and use only letters, numbers, dots, underscores, or hyphens.';
        }
        if ($values['first_name'] === '' || $values['last_name'] === '') {
            $errors[] = 'First name and last name are required.';
        }
        if ($values['birthdate'] === '' || $values['birthdate'] > date('Y-m-d')) {
            $errors[] = 'Please enter a valid birthdate.';
        }
        if ($values['nationality'] === '') {
            $errors[] = 'Nationality is required.';
        }
    } elseif ($step === 2 && $action !== 'back') {
        if ($values['complete_address'] === '') {
            $errors[] = 'Complete address is required.';
        }
        if (
            $values['residency_start_date'] !== ''
            && (
                !DateTime::createFromFormat('!Y-m-d', $values['residency_start_date'])
                || DateTime::createFromFormat('!Y-m-d', $values['residency_start_date'])->format('Y-m-d') !== $values['residency_start_date']
                || $values['residency_start_date'] > date('Y-m-d')
            )
        ) {
            $errors[] = 'Please enter a valid residency start date that is not in the future.';
        }
        if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
    } elseif ($step === 4 && $action !== 'back') {
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');
        if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must be at least 8 characters and include one uppercase letter and one number.';
        }
        if ($password !== $confirmation) {
            $errors[] = 'The password confirmation does not match.';
        }
        if ($errors === []) {
            try {
                $values['valid_id_file_path'] = registration_upload('valid_id', 'valid-id') ?? $values['valid_id_file_path'];
                $values['profile_image_path'] = registration_upload('profile_image', 'profile') ?? $values['profile_image_path'];
                $draft['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
    } elseif ($step === 5 && $action === 'submit') {
        if (($_POST['terms'] ?? '') !== 'accepted') {
            $errors[] = 'You must agree to the terms and conditions before submitting.';
        }
        if ($draft['password_hash'] === null) {
            $errors[] = 'Please complete the account security step first.';
        }
    }

    if ($errors === []) {
        $draft['values'] = $values;
        if ($step === 5 && $action === 'submit') {
            $pdo = db();
            $duplicate = $pdo->prepare(
                'SELECT user_id FROM user_account WHERE username = :username
                 UNION ALL SELECT resident_id FROM resident WHERE email = :email LIMIT 1'
            );
            $duplicate->execute(['username' => $values['username'], 'email' => $values['email']]);
            if ($duplicate->fetch()) {
                $errors[] = 'That username or email address is already registered.';
            } else {
                $pdo->beginTransaction();
                try {
                    $resident = $pdo->prepare(
                        'INSERT INTO resident (
                            username, first_name, middle_name, last_name, birthdate, place_of_birth, gender,
                            civil_status, nationality, religion, complete_address, purok,
                            residency_start_date, mobile_number, email, voters_status,
                            employment_status, occupation, educational_attainment, blood_type,
                            disability_special_needs, emergency_contact_person, emergency_contact_number,
                            valid_id_file_path, profile_image_path
                        ) VALUES (
                            :username, :first_name, NULLIF(:middle_name, \'\'), :last_name, :birthdate,
                            NULLIF(:place_of_birth, \'\'), NULLIF(:gender, \'\'), NULLIF(:civil_status, \'\'),
                            :nationality, NULLIF(:religion, \'\'), :complete_address, NULLIF(:purok, \'\'),
                            CAST(NULLIF(:residency_start_date, \'\') AS DATE), NULLIF(:mobile_number, \'\'),
                            NULLIF(:email, \'\'), NULLIF(:voters_status, \'\'), NULLIF(:employment_status, \'\'),
                            NULLIF(:occupation, \'\'), NULLIF(:educational_attainment, \'\'),
                            NULLIF(:blood_type, \'\'), NULLIF(:disability_special_needs, \'\'),
                            NULLIF(:emergency_contact_person, \'\'), NULLIF(:emergency_contact_number, \'\'),
                            NULLIF(:valid_id_file_path, \'\'), NULLIF(:profile_image_path, \'\')
                        ) RETURNING resident_id'
                    );
                    $resident->execute([
                        'username' => $values['username'],
                        'first_name' => $values['first_name'],
                        'middle_name' => $values['middle_name'],
                        'last_name' => $values['last_name'],
                        'birthdate' => $values['birthdate'],
                        'place_of_birth' => $values['place_of_birth'],
                        'gender' => $values['gender'],
                        'civil_status' => $values['civil_status'],
                        'nationality' => $values['nationality'],
                        'religion' => $values['religion'],
                        'complete_address' => $values['complete_address'],
                        'purok' => $values['purok'],
                        'residency_start_date' => $values['residency_start_date'],
                        'mobile_number' => $values['mobile_number'],
                        'email' => $values['email'],
                        'voters_status' => $values['voters_status'],
                        'employment_status' => $values['employment_status'],
                        'occupation' => $values['occupation'],
                        'educational_attainment' => $values['educational_attainment'],
                        'blood_type' => $values['blood_type'],
                        'disability_special_needs' => $values['disability_special_needs'],
                        'emergency_contact_person' => $values['emergency_contact_person'],
                        'emergency_contact_number' => $values['emergency_contact_number'],
                        'valid_id_file_path' => $values['valid_id_file_path'],
                        'profile_image_path' => $values['profile_image_path'],
                    ]);
                    $account = $pdo->prepare(
                        'INSERT INTO user_account (resident_id, username, password_hash, account_status)
                         VALUES (:resident_id, :username, :password_hash, \'active\')'
                    );
                    $account->execute([
                        'resident_id' => $resident->fetchColumn(),
                        'username' => $values['username'],
                        'password_hash' => $draft['password_hash'],
                    ]);
                    $pdo->commit();
                    unset($_SESSION['registration']);
                    $_SESSION['success'] = 'Your account was created. You can now log in.';
                    header('Location: index.php');
                    exit;
                } catch (Throwable $exception) {
                    $pdo->rollBack();
                    throw $exception;
                }
            }
        } elseif ($action === 'next') {
            $step = min(5, $step + 1);
        } elseif ($action === 'back') {
            $step = max(1, $step - 1);
        }
    }
}

$draft['values'] = $values;
$pageTitle = 'Create an account';
require __DIR__ . '/includes/header.php';
?>
<main class="auth-page registration-page">
    <div class="container py-4 py-lg-5">
        <section class="registration-hero">
            <img src="assets/images/logo.png" alt="Barangay Inosluban logo" class="registration-floating-logo">
            <p class="eyebrow">BARANGAY INOSLUBAN</p>
            <p class="brand-location">LIPA CITY, BATANGAS</p>
            <p class="brand-motto">A United Community<br>for a Progressive Tomorrow</p>
        </section>
        <div class="auth-shell">
            <section class="registration-steps">
                <h2>Create an Account</h2>
                <p>Complete each step to register as a resident of Inosluban.</p>
                <div class="steps-list">
                    <?php foreach ($stepNames as $number => $name): ?>
                        <span class="<?= $number === $step ? 'active' : '' ?>"><b><?= $number ?></b> <?= e($name) ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="steps-help"><i class="bi bi-question-circle"></i><span>Need help?<small>Contact the barangay office</small></span></div>
            </section>

            <section class="form-panel">
                <div class="form-heading">
                    <span class="heading-icon"><i class="bi bi-person-plus"></i></span>
                    <div>
                        <h2><?= e($stepNames[$step]) ?></h2>
                        <p><?= $step === 5 ? 'Check your information before submitting.' : 'Please provide the requested information.' ?></p>
                    </div>
                </div>
                <?php require __DIR__ . '/includes/alerts.php'; ?>

                <form method="post" action="register.php?step=<?= $step ?>" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="step" value="<?= $step ?>">

                    <?php if ($step === 1): ?>
                        <div class="registration-fields">
                            <?php
                            $inputs = [
                                ['username', 'Username', 'Choose a username', 'text', true],
                                ['first_name', 'First Name', 'e.g. Juan', 'text', true],
                                ['middle_name', 'Middle Name', 'e.g. Dela Cruz', 'text', false],
                                ['last_name', 'Last Name', 'e.g. Santos', 'text', true],
                                ['birthdate', 'Birthdate', '', 'date', true],
                                ['place_of_birth', 'Place of Birth', 'City or municipality', 'text', false],
                            ];
                            foreach ($inputs as [$name, $label, $placeholder, $type, $required]):
                            ?>
                                <div class="mb-2">
                                    <label for="<?= $name ?>" class="form-label"><?= $label ?><?= $required ? ' *' : '' ?></label>
                                    <input type="<?= $type ?>" class="form-control" id="<?= $name ?>" name="<?= $name ?>" placeholder="<?= e($placeholder) ?>" value="<?= e($values[$name]) ?>" <?= $required ? 'required' : '' ?>>
                                </div>
                            <?php endforeach; ?>
                            <div class="mb-2"><label for="gender" class="form-label">Gender</label><select class="form-control" id="gender" name="gender"><option value="">Select Gender</option><option>Female</option><option>Male</option><option>Prefer not to say</option></select></div>
                            <div class="mb-2"><label for="civil_status" class="form-label">Civil Status</label><select class="form-control" id="civil_status" name="civil_status"><option value="">Select Civil Status</option><option>Single</option><option>Married</option><option>Widowed</option></select></div>
                            <div class="mb-2"><label for="nationality" class="form-label">Nationality *</label><input type="text" class="form-control" id="nationality" name="nationality" value="<?= e($values['nationality']) ?>" required></div>
                            <div class="mb-2"><label for="religion" class="form-label">Religion</label><input type="text" class="form-control" id="religion" name="religion" value="<?= e($values['religion']) ?>"></div>
                        </div>
                    <?php elseif ($step === 2): ?>
                        <div class="registration-fields">
                            <div class="mb-2"><label for="complete_address" class="form-label">Complete Address *</label><input type="text" class="form-control" id="complete_address" name="complete_address" placeholder="House no., street, barangay, city" value="<?= e($values['complete_address']) ?>" required></div>
                            <div class="mb-2"><label for="purok" class="form-label">Purok</label><input type="text" class="form-control" id="purok" name="purok" value="<?= e($values['purok']) ?>"></div>
                            <div class="mb-2"><label for="residency_start_date" class="form-label">Residency Start Date</label><input type="date" class="form-control" id="residency_start_date" name="residency_start_date" value="<?= e($values['residency_start_date']) ?>"></div>
                            <div class="mb-2"><label for="mobile_number" class="form-label">Mobile Number</label><input type="tel" class="form-control" id="mobile_number" name="mobile_number" value="<?= e($values['mobile_number']) ?>"></div>
                            <div class="mb-2"><label for="email" class="form-label">Email Address</label><input type="email" class="form-control" id="email" name="email" value="<?= e($values['email']) ?>"></div>
                            <div class="mb-2"><label for="emergency_contact_person" class="form-label">Emergency Contact Person</label><input type="text" class="form-control" id="emergency_contact_person" name="emergency_contact_person" value="<?= e($values['emergency_contact_person']) ?>"></div>
                            <div class="mb-2"><label for="emergency_contact_number" class="form-label">Emergency Contact Number</label><input type="tel" class="form-control" id="emergency_contact_number" name="emergency_contact_number" value="<?= e($values['emergency_contact_number']) ?>"></div>
                        </div>
                    <?php elseif ($step === 3): ?>
                        <div class="registration-fields">
                            <div class="mb-2"><label for="voters_status" class="form-label">Voter's Status</label><select class="form-control" id="voters_status" name="voters_status"><option value="">Select Status</option><option>Registered</option><option>Not Registered</option></select></div>
                            <div class="mb-2"><label for="employment_status" class="form-label">Employment Status</label><select class="form-control" id="employment_status" name="employment_status"><option value="">Select Status</option><option>Employed</option><option>Self-employed</option><option>Unemployed</option><option>Student</option><option>Retired</option></select></div>
                            <div class="mb-2"><label for="occupation" class="form-label">Occupation</label><input type="text" class="form-control" id="occupation" name="occupation" value="<?= e($values['occupation']) ?>"></div>
                            <div class="mb-2"><label for="educational_attainment" class="form-label">Educational Attainment</label><input type="text" class="form-control" id="educational_attainment" name="educational_attainment" value="<?= e($values['educational_attainment']) ?>"></div>
                            <div class="mb-2"><label for="blood_type" class="form-label">Blood Type</label><input type="text" class="form-control" id="blood_type" name="blood_type" value="<?= e($values['blood_type']) ?>"></div>
                            <div class="mb-2"><label for="disability_special_needs" class="form-label">Disability / Special Needs</label><input type="text" class="form-control" id="disability_special_needs" name="disability_special_needs" value="<?= e($values['disability_special_needs']) ?>"></div>
                        </div>
                    <?php elseif ($step === 4): ?>
                        <div class="registration-fields">
                            <div class="mb-2"><label for="valid_id" class="form-label">Valid ID</label><input type="file" class="form-control" id="valid_id" name="valid_id" accept=".jpg,.jpeg,.png,.pdf"></div>
                            <div class="mb-2"><label for="profile_image" class="form-label">Profile Image</label><input type="file" class="form-control" id="profile_image" name="profile_image" accept=".jpg,.jpeg,.png"></div>
                            <div class="mb-2"><label for="password" class="form-label">Password *</label><input type="password" class="form-control" id="password" name="password" autocomplete="new-password" required></div>
                            <div class="mb-2"><label for="password_confirmation" class="form-label">Confirm Password *</label><input type="password" class="form-control" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required></div>
                        </div>
                        <p class="form-text">Use at least 8 characters with one uppercase letter and one number.</p>
                    <?php else: ?>
                        <div class="review-summary">
                            <?php foreach ($values as $name => $value): ?>
                                <?php if ($value !== '' && !in_array($name, ['valid_id_file_path', 'profile_image_path'], true)): ?>
                                    <div><strong><?= e(ucwords(str_replace('_', ' ', $name))) ?></strong><span><?= e($value) ?></span></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-check terms-check mt-4">
                            <input class="form-check-input" type="checkbox" id="terms" name="terms" value="accepted" required>
                            <label class="form-check-label" for="terms">I have reviewed my information and agree to the terms and conditions.</label>
                        </div>
                    <?php endif; ?>

                    <div class="wizard-actions">
                        <?php if ($step > 1): ?><button class="btn btn-outline-primary" name="action" value="back" type="submit">Back</button><?php endif; ?>
                        <?php if ($step < 5): ?><button class="btn btn-primary register-next" name="action" value="next" type="submit">Next: <?= e($stepNames[$step + 1]) ?> <i class="bi bi-arrow-right ms-2"></i></button><?php else: ?><button class="btn btn-primary register-next" name="action" value="submit" type="submit">Review &amp; Submit <i class="bi bi-check2 ms-2"></i></button><?php endif; ?>
                    </div>
                </form>
                <p class="text-center mt-4 mb-0 small">Already registered? <a href="index.php">Log in</a></p>
            </section>
        </div>
    </div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
