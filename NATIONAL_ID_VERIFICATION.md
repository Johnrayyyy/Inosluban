# Philippine National ID pre-verification

This module is an additive, non-official pre-verification flow for physical
PVC PhilID and printed paper ePhilID submissions. It does not replace PhilSys
Check, PSA/NIDAS authorization, or staff identity review.

## Architecture

The browser uploads three still images to `register.php` only. PHP validates
the files, writes random restrictive temporary files, and calls the private
FastAPI service at `NATIONAL_ID_SERVICE_URL`. The browser never calls Python
directly. Temporary files are deleted in cleanup logic after the service call.
Raw images are not stored by the current flow.

the service performs bounded image validation, QR decoding, limited front-side
OCR cross-checking, MediaPipe face presence/quality checks, and InsightFace
ArcFace one-to-one comparison. Unknown or unsupported QR payloads are routed
to staff review; the implementation does not claim cryptographic PSA
authenticity.

The official PhilSys public advisory states that the QR includes selected
demographic details and a photograph and uses public-private key cryptography:
<https://philsys.gov.ph/public-advisory-10/>.

## Dependencies and startup

Pinned service dependencies are in
[`national_id_service/requirements-national-id.txt`](national_id_service/requirements-national-id.txt).
The target service runtime is Python 3.13. Install and run it locally from the
repository root:

```powershell
py -3.13 -m venv .venv-national-id
.\.venv-national-id\Scripts\python.exe -m pip install -r national_id_service\requirements-national-id.txt
.\.venv-national-id\Scripts\python.exe -m uvicorn national_id_service.app:app --host 127.0.0.1 --port 8000
```

On Render, deploy the National ID service only as a private service or within a
separate private network. PHP must use the private service URL and a configured
service token. Do not publish the verification API through a public web service.

First startup may download model data for RapidOCR and InsightFace (`buffalo_l`).
Plan for
substantial CPU, RAM, disk, and model-cache space; verify these requirements
on the target Python/Docker image before production use.

## Configuration names

Only variable names are listed here; values must remain private:

- `NATIONAL_ID_SERVICE_URL`
- `NATIONAL_ID_SERVICE_TOKEN`
- `NATIONAL_ID_SERVICE_TIMEOUT`
- `NATIONAL_ID_MAX_IMAGE_BYTES`
- `NATIONAL_ID_MAX_PIXELS`
- `NATIONAL_ID_FACE_MODEL`
- `NATIONAL_ID_FACE_DISTANCE_METRIC`
- `NATIONAL_ID_FACE_THRESHOLD`
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
`database\migrations\001_national_id_verification.rollback.sql`. Disable the
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

## Validation

```powershell
python -m py_compile national_id_service\app.py
python -m unittest tests.test_national_id_service
php -l register.php
php -l includes\national_id.php
php -l staff_national_id_review.php
```

Production readiness still requires target-Python dependency installation,
camera/image fixtures using synthetic or public data, PostgreSQL migration
tests, HTTPS browser tests, staff authorization tests, and privacy approval.
