# National ID Pre-Verification

This module is an additive, non-official pre-verification flow for physical
PVC PhilID and printed paper ePhilID submissions. It does not replace PhilSys
Check, PSA/NIDAS authorization, or staff identity review.

## Architecture

The browser uploads two still images to `register.php` or
`profile_id_verify.php`: the ID front and back. PHP validates the files, writes
random restrictive temporary files, and calls the private FastAPI service at
`NATIONAL_ID_SERVICE_URL`. The browser never calls Python directly. Temporary
files are deleted in cleanup logic after the service call. Raw images are not
stored by the current flow.

the service performs bounded image validation, QR decoding, and limited
front-side OCR cross-checking. No selfie or face comparison is collected or
processed. Unknown or unsupported QR payloads are routed to staff review; the
implementation does not claim cryptographic PSA authenticity or confirm that
the person submitting the ID is its holder.

The official PhilSys public advisory states that the QR includes selected
demographic details and a photograph and uses public-private key cryptography:
<https://philsys.gov.ph/public-advisory-10/>.

## Step-by-step verification process

The module is a local pre-verification workflow. It is not a PSA, PhilSys,
NIDAS, or cryptographic identity verification.

### 1. Resident opens registration step 4

`register.php` presents ID verification choices. The resident can use the
existing manual valid-ID upload or select the National ID method.

At this step:

1. The resident chooses `PhilID` or `ePhilID`.
2. The resident checks the privacy consent checkbox.
3. The resident supplies front and back ID images.
4. The resident enters the password and submits step 4.

The consent and National ID method checks are in `register.php`.
Consent is not optional for the automated National ID path.

### 2. PHP validates and preserves the wizard state

`register.php` calls `national_id_verify_uploads()` from
`includes/national_id.php` unless the resident has already received a service
result and confirmed the extracted details.

Before calling Python, PHP:

- checks that both upload fields exist;
- rejects upload errors and incomplete uploads;
- requires an uploaded file;
- verifies that each file is JPG or PNG;
- verifies minimum dimensions and maximum pixel count;
- verifies the configured maximum file size;
- creates a private temporary directory;
- copies each upload to a random restricted filename;
- sends the two temporary files to the FastAPI `/verify-id` endpoint;
- deletes the temporary copies in `finally`, including after failures.

The image directory is:

```text
<system temporary directory>\inosluban-national-id
```

PHP does not retain the raw image files after the request is complete.

### 3. PHP calls FastAPI

`national_id_verify_uploads()` performs the cURL request:

```text
POST NATIONAL_ID_SERVICE_URL/verify-id
multipart fields:
- front_image
- back_image
- verification_id, if supplied
- service_token, if supplied by the caller
```

The PHP client sets a connect timeout and request timeout from
`NATIONAL_ID_SERVICE_TIMEOUT`. It disables redirects and expects JSON.
The response is rejected if it is empty, malformed, larger than 200,000 bytes,
uses a non-2xx HTTP status, or does not match the expected response shape.

The browser never calls FastAPI directly. Only PHP uploads the resident images.

### 4. FastAPI validates each image

`national_id_service/app.py` reads the two uploaded files and applies limits:

- `NATIONAL_ID_MAX_IMAGE_BYTES`;
- `NATIONAL_ID_MAX_PIXELS`;
- minimum width and height.

Invalid or unreadable images raise an HTTP 422 response with a controlled
`invalid_image_*` detail code. The service does not expose file paths or
resident data in that response.

### 5. FastAPI decodes the back-side QR

The back image is converted to grayscale and adaptive-threshold variants.
The service reads QR/barcode results with `zxingcpp`.

Only a JSON QR payload containing the required PCN and last-name fields is
accepted. Limited recognized fields include:

- PCN (`pcn`);
- last name (`lastName`, `surname`, `familyName`, `n_l`);
- first name (`firstName`, `givenName`, `n_f`);
- middle name (`middleName`, `n_m`);
- birthdate (`birthdate`, `dateOfBirth`, `dob`, `bd`);
- gender.

Unknown, encrypted, signed, malformed, or unsupported QR data is not
interpreted. It produces:

```text
status = PENDING_STAFF_REVIEW
review_reason = QR_UNREADABLE_OR_UNSUPPORTED
```

This does not mean that the resident's physical ID is invalid.

### 6. FastAPI checks the front image

If a supported QR payload was decoded, the service:

1. runs limited OCR against the front image;
2. extracts alphanumeric PCN candidates;
3. compares normalized OCR text with the QR PCN;
4. searches normalized OCR text for the QR last name.

The results are stored as tri-state values:

- `true`: local check passed;
- `false`: local check failed or mismatched;
- `null`: result unavailable or inconclusive.

These checks are not cryptographic verification.

### 7. No selfie or face comparison

The flow processes only the front and back ID images. It does not collect a
selfie, perform face matching, or determine whether the person submitting the
ID is the person pictured on it. Staff may still need to review an uncertain
QR/OCR result.

### 8. PHP determines the result state

FastAPI returns one of these module statuses:

- `PRE_VERIFIED`: no configured check produced a review reason;
- `PENDING_STAFF_REVIEW`: QR or OCR results are uncertain;
- `RETRY_REQUIRED`: the PHP/Python result indicates that clearer images are needed.

If all reasons are clear, the status is `PRE_VERIFIED`.
If any review reason remains, the status is `PENDING_STAFF_REVIEW`.
The module never returns an automated permanent rejection.

The resident's review page asks the resident to confirm any extracted details.
Until this confirmation is made, the verification is not saved to the resident
account's final registration transaction.

### 9. Resident confirms extracted details

For the first National ID submission, PHP keeps the resident on registration
step 4 and displays the extracted details.

The resident must submit step 4 again and confirm the details. Confirmation
copies allowed extracted fields into the registration wizard. It does not make
the local result an official PSA verification.

Without confirmation, the wizard does not insert the National ID verification
record.

### 10. Registration transaction stores the result

When the resident finalizes registration, PHP inserts the resident and account
inside the existing PostgreSQL transaction.

For the National ID path, the transaction inserts:

- resident ID;
- service verification ID;
- selected National ID format;
- module status;
- masked PCN;
- QR/OCR flags;
- review reasons;
- consent timestamp;
- consent version;
- processing timestamp.

The consent version for front-and-back-only processing is `national-id-v2`.
The transaction rolls back if the National ID insert or resident/account insert
fails.

Raw front and back images are not inserted into the database.

### 11. Staff reviews uncertain submissions

`staff_national_id_review.php` lists rows whose status is
`PENDING_STAFF_REVIEW`.

The page requires general staff or administrator access and the existing
face-authenticated staff session.

A staff decision requires:

- valid CSRF token;
- a valid verification row;
- `Approved` or `Rejected`;
- a review reason between 5 and 1000 characters;
- a pending review status.

The decision updates the row to `STAFF_APPROVED` or `STAFF_REJECTED`.
The staff member and timestamp are recorded.
An audit entry is written for `NATIONAL_ID_REVIEW_APPROVED` or
`NATIONAL_ID_REVIEW_REJECTED`.

## Dependencies and startup

Pinned service dependencies are in
[`national_id_service/requirements-national-id.txt`](../../national_id_service/requirements-national-id.txt).
The target service runtime is Python 3.13. Install and run it locally from the
repository root:

```powershell
py -3.13 -m venv .venv-national-id
.\.venv-national-id\Scripts\python.exe -m pip install -r national_id_service\requirements-national-id.txt
.\.venv-national-id\Scripts\python.exe -m uvicorn national_id_service.app:app --host 127.0.0.1 --port 8000
```

### Deploy the Python verifier on Render

The root [`render.yaml`](../../render.yaml) creates a separate Render private
service for the verifier. In Render, create a Blueprint from this repository
and sync the Blueprint. It builds
[`national_id_service/Dockerfile`](../../national_id_service/Dockerfile) with
`national_id_service/` as the Docker context, so PHP sources, uploads, and local
virtual environments are not sent in the verifier's build context. The service
listens on port `8000`, has no public URL, and uses the `1c-2g` plan for the
image-processing workload. The Blueprint defaults to Singapore; if your PHP
service is already deployed in another region, change the verifier's `region`
in `render.yaml` to match before syncing.
Check Render's current pricing before deploying; this is a paid compute plan.

The Blueprint also creates the `inosluban-national-id-auth` environment group
with a generated `NATIONAL_ID_SERVICE_TOKEN`, and attaches it to the private
service. Configure the PHP web service as follows:

1. Keep PHP and the verifier in the same Render workspace and region so they
   can use Render's private network.
2. Attach the `inosluban-national-id-auth` environment group to the PHP service
   so both services receive the same token.
3. Set the PHP service's `NATIONAL_ID_SERVICE_URL` to
   `http://<private-service-address>`, using the verifier's **Service Address**
   from its Render **Connect** panel (include port `8000` if it is not shown).
4. Set `NATIONAL_ID_SERVICE_TIMEOUT` to `60` on PHP to allow time for OCR.
   Keep PHP's `NATIONAL_ID_MAX_IMAGE_BYTES` and `NATIONAL_ID_MAX_PIXELS` at
   `5000000` and `12000000`, matching the verifier.
5. Deploy PHP after setting its environment variables.

Do not expose `/verify-id` as a public web service. If PHP is hosted outside
Render, it cannot reach this private service; use a private network connection
instead of making the image-verification API public. The verifier does not
connect to PostgreSQL or retain submitted images; PHP sends temporary files and
stores only the verification result.

The verifier requires its service token when
`NATIONAL_ID_REQUIRE_SERVICE_TOKEN=true`. Its `/health` endpoint returns HTTP
200 only when QR/OCR dependencies and required service configuration are ready.
The pinned RapidOCR package includes the model files; startup loads them into
memory, so wait for the service to finish starting before testing submissions.

## Configuration names

Only variable names are listed here; values must remain private:

- `NATIONAL_ID_SERVICE_URL`
- `NATIONAL_ID_SERVICE_TOKEN`
- `NATIONAL_ID_REQUIRE_SERVICE_TOKEN`
- `NATIONAL_ID_SERVICE_TIMEOUT`
- `NATIONAL_ID_MAX_IMAGE_BYTES`
- `NATIONAL_ID_MAX_PIXELS`
- `NATIONAL_ID_RETENTION_DAYS`
- `NATIONAL_ID_RETENTION_KEY`
- `NATIONAL_ID_PRIVACY_CONTACT`
- `NATIONAL_ID_PRIVACY_PURPOSE`
- `NATIONAL_ID_PRIVACY_RETENTION_TEXT`

Retention is disabled when `NATIONAL_ID_RETENTION_DAYS` is zero. A production
retention period, DPO/contact details, lawful basis, and any source-image
encryption key require explicit barangay/DPO approval. No raw image retention
implementation is enabled by default.

## Database migration

Apply the additive migration only to the intended PostgreSQL database:

```powershell
psql -U <database-user> -d <database-name> -v ON_ERROR_STOP=1 -f database\migrations\001_national_id_verification.sql
```

The matching rollback is
[`001_national_id_verification.rollback.sql`](../../database/migrations/001_national_id_verification.rollback.sql).
Disable the
feature first and use rollback only when the operator intentionally accepts
removing all National ID verification records. Existing resident rows,
`valid_id_file_path`, uploads, and face-auth tables are not changed.

## Status flow

- `PROCESSING`: reserved for future asynchronous processing.
- `PRE_VERIFIED`: configured local checks passed; not official PSA verification.
- `PENDING_STAFF_REVIEW`: uncertainty, unsupported QR, mismatch, or service issue.
- `RETRY_REQUIRED`: clearer images are required.
- `STAFF_APPROVED` / `STAFF_REJECTED`: authorized staff decision with reason.
- `OFFICIAL_CHECK_RECORDED`: reserved for a separately recorded authorized official check.

Pending cases are available at `staff_national_id_review.php`. Staff decisions
require the existing staff face-authenticated session, CSRF, a reason, and an
audit-log entry.

### Resident service access

Residents can submit new document requests once their status is `PRE_VERIFIED`,
`STAFF_APPROVED`, or `OFFICIAL_CHECK_RECORDED`. This local pre-verification is
not official PSA verification. Residents can still view and track existing
requests before verification. Appointment and blotter access remain available
to any signed-in resident and do not depend on National ID status.

## Validation

```powershell
python -m py_compile national_id_service\app.py
python -m unittest tests.test_national_id_service
php -l register.php
php -l includes\national_id.php
php -l staff_national_id_review.php
```

## Debugging checklist

Debug in this order. A later step may be unreachable if an earlier step fails.

### A. Check the PHP host

Confirm PHP can access FastAPI from the same host:

```powershell
Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:8000/health" -TimeoutSec 5
```

Expected JSON:

```json
{"status":"ok","ready":"true"}
```

`ready=false` or `ready=degraded` means the process is reachable but one or more
model libraries is unavailable. `Connection refused` means the service is not
running or is bound to another address/network namespace.

If Apache cannot reach `127.0.0.1:8000`, check `NATIONAL_ID_SERVICE_URL`
without printing or committing its configured value.

### B. Check the Python environment

Use the dedicated environment, not `.venv-face`:

```powershell
.\.venv-national-id\Scripts\python.exe --version
.\.venv-national-id\Scripts\python.exe -c "import cv2, zxingcpp, fastapi; print('imports ok')"
.\.venv-national-id\Scripts\python.exe -m pip check
```

Install or repair the pinned requirements:

```powershell
.\.venv-national-id\Scripts\python.exe -m pip install -r national_id_service\requirements-national-id.txt
```

Compile and test:

```powershell
.\.venv-national-id\Scripts\python.exe -m py_compile national_id_service\app.py
.\.venv-national-id\Scripts\python.exe -m unittest tests.test_national_id_service
```

### C. Start FastAPI and watch startup logs

```powershell
.\.venv-national-id\Scripts\python.exe -m uvicorn national_id_service.app:app --host 127.0.0.1 --port 8000
```

If startup exits, inspect the final Python traceback first. Common failure
points are missing packages, incompatible NumPy/OpenCV builds, model download
failure, or insufficient memory.

Do not include uploaded National ID images or PCN values in reports or logs.

### D. Check the browser submission

In registration step 4:

1. confirm the National ID format is selected;
2. confirm the consent checkbox is checked;
3. confirm both image inputs contain JPG or PNG files;
4. submit the step once and wait for the page to remain on the result screen;
5. confirm extracted details, then submit again to store the result.

Submit only synthetic or public test images during debugging.

### E. Classify the result

| Observation | Likely area | First check |
|---|---|---|
| `National ID consent is required` | `register.php` | Consent checkbox value and POST handling |
| `National ID images must be JPG or PNG` | PHP upload validation | MIME type of the three fixtures |
| `National ID images exceed...` | PHP upload limits | Size, pixels, and configured limits |
| `National ID verification is temporarily unavailable` | PHP/cURL connection | `/health`, service URL, network, timeout |
| `invalid_image_*` | FastAPI image validation | Dimensions, file size, JPEG/PNG decode |
| `QR_REQUIRES_STAFF_REVIEW` | QR decoding | Back image, QR payload, lighting, rotation |
| `PENDING_STAFF_REVIEW` | QR or OCR check | Review reasons returned by FastAPI |
| `PRE_VERIFICATION_COMPLETE` | Local checks | Confirm that UI text still says this is not official verification |
| 500 on `staff_national_id_review.php` | Database query/schema | PHP/Apache log and migration state |
| No row in the staff queue | Registration transaction | Resident confirmed extracted details and transaction committed |

### F. Check PostgreSQL

Use the explicit PostgreSQL 18 client path if `psql` is not on `PATH`:

```powershell
& "C:\Program Files\PostgreSQL\18\bin\psql.exe" --version
& "C:\Program Files\PostgreSQL\18\bin\psql.exe" -U <database-user> -d <database-name> -c "\d national_id_verification"
```

Use placeholders rather than embedding or printing credentials. Run migration
tests only against a disposable development database.

Apply or remove the migration only after the feature is intentionally enabled
or disabled:

```powershell
& "C:\Program Files\PostgreSQL\18\bin\psql.exe" -U <database-user> -d <database-name> -v ON_ERROR_STOP=1 -f database\migrations\001_national_id_verification.sql
& "C:\Program Files\PostgreSQL\18\bin\psql.exe" -U <database-user> -d <database-name> -v ON_ERROR_STOP=1 -f database\migrations\001_national_id_verification.rollback.sql
```

Rollback deletes all National ID verification rows. It does not drop resident,
valid-ID, or face-authentication data.

### G. Read only sanitized application errors

PHP logs cURL failures, staff-review errors, and service validation failures.
When reporting an error:

- record the timestamp and HTTP status;
- record the page or endpoint;
- record the result/status code;
- do not paste raw resident data, PCNs, uploaded images, or environment values;
- inspect Apache/PHP logs locally instead of sharing their contents publicly.

## Service-token integration

PHP sends `NATIONAL_ID_SERVICE_TOKEN` as the multipart `service_token` field,
which FastAPI reads as a form field. Keep the service private and do not expose
the verification endpoint publicly.

Production readiness still requires target-Python dependency installation,
camera/image fixtures using synthetic or public data, PostgreSQL migration
tests, HTTPS browser tests, staff authorization tests, and privacy approval.
