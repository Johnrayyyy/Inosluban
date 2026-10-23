<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_staff();
$requestId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
$statement = db()->prepare(
    'SELECT dr.*, r.first_name, r.middle_name, r.last_name, r.complete_address, r.purok, r.resident_id
     FROM document_request dr JOIN resident r ON r.resident_id = dr.resident_id
     WHERE dr.request_id = :request_id AND dr.document_type = \'Barangay Clearance\''
);
$statement->execute(['request_id' => $requestId]);
$request = $statement->fetch();
if (!$request) {
    http_response_code(404);
    exit('Clearance request not found.');
}
staff_audit('CLEARANCE_PREVIEWED', 'document_request', $requestId, ['template' => 'sample']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Barangay Clearance Sample</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{font-family:Georgia,serif;color:#111}.clearance{max-width:760px;margin:2rem auto;padding:3rem;border:8px double #8e0710}.seal{width:76px}.heading{text-align:center}.heading h1{font-size:1.5rem;margin:.2rem}.heading h2{font-size:1.15rem}.details{margin-top:2rem;line-height:2}.signature{display:flex;justify-content:space-between;margin-top:4rem;text-align:center}.no-print{margin-bottom:1rem}@media print{.no-print{display:none}.clearance{margin:0;border:8px double #8e0710}}</style></head><body><div class="no-print text-center"><button class="btn btn-dark" onclick="window.print()">Print sample</button><a class="btn btn-outline-secondary" href="javascript:history.back()">Back</a></div><article class="clearance"><div class="heading"><img class="seal" src="assets/images/logo.png" alt="Barangay seal"><h2>REPUBLIC OF THE PHILIPPINES</h2><h2>BARANGAY INOSLUBAN</h2><p>Lipa City, Batangas</p><hr><h1>BARANGAY CLEARANCE</h1></div><div class="details"><p>TO WHOM IT MAY CONCERN:</p><p>This is to certify that <strong><?= e(trim($request['first_name'] . ' ' . $request['middle_name'] . ' ' . $request['last_name'])) ?></strong>, resident of <strong><?= e($request['complete_address']) ?></strong><?= $request['purok'] ? ', ' . e($request['purok']) : '' ?>, is recorded in the barangay resident database.</p><p>This clearance is issued upon the request of the above-named person for <strong><?= e($request['purpose']) ?></strong>.</p><p>Issued this <?= date('jS') ?> day of <?= date('F Y') ?>.</p></div><div class="signature"><div>________________________<br>Authorized Staff</div><div>________________________<br>Barangay Official</div></div></article></body></html>
