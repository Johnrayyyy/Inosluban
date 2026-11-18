# Barangay Inosluban Portal - Project Structure

This layout preserves all existing Apache URLs and Core PHP entry points.
Do not move root PHP pages or alter `.env`, `.venv-*`, `vendor/`,
`storage/models/`, database data files, or uploaded resident files during
routine source organization.

## Directory responsibilities

| Path | Responsibility |
| --- | --- |
| `*.php` at repository root | Public page, form, or API entry point |
| `includes/` | Shared bootstrap, authorization, database helpers, email, and domain logic |
| `config/` | Application configuration and mail configuration |
| `assets/css/` | Stylesheets |
| `assets/js/` | Browser JavaScript |
| `assets/images/` | Static portal images |
| `templates/emails/` | Outgoing HTML email templates |
| `storage/` | Protected application files |
| `storage/models/` | Protected face model files |
| `database/migrations/` | Additive, reversible database migrations |
| `national_id_service/` | Private FastAPI National ID verification service |
| `tests/` | Python service unit tests |
| `docs/features/` | Feature implementation, operation, and debugging guides |
| `vendor/` | Composer dependencies; not source-controlled application code |
| `.venv-face/` | Existing staff face-verifier virtual environment |
| `.venv-national-id/` | National ID FastAPI virtual environment |

## PHP placement rules

1. Keep browser-accessible entry files in the repository root.
2. Put reusable PHP functions in `includes/`.
3. Put environment-only values and defaults in `config/config.php`.
4. Put email HTML in `templates/emails/`.
5. Put feature SQL migrations in `database/migrations/`.
6. Do not create direct public URLs for protected files under `storage/`.
7. Do not move `face_verifier.py`, `face_verify.php`, `face_enroll.php`, or
   `includes/face_auth.php` unless a direct dependency requires it.

## National ID feature files

- Browser form: `register.php`
- Staff review: `staff_national_id_review.php`
- PHP integration: `includes/national_id.php`
- Database migration: `database/migrations/`
- FastAPI service: `national_id_service/app.py`
- Service dependencies: `national_id_service/requirements-national-id.txt`
- Service Docker image: `national_id_service/Dockerfile`
- Render private-service Blueprint: `render.yaml`
- Unit tests: `tests/test_national_id_service.py`
- Operations guide: `docs/features/NATIONAL_ID_VERIFICATION.md`

## Document pickup files

- Staff scanner: `staff_pickup_scanner.php`
- Scanner JSON endpoint: `staff_pickup_scan.php`
- Manual verification: `staff_verify_document.php`
- QR generation and release transaction: `includes/document_pickup.php`
- Pickup email: `includes/document_pickup_email.php`
- Request status UI: `document_request_status.php`

## Naming and change rules

- Use descriptive lowercase Python module names.
- Use lowercase snake-case for Python test names.
- Use descriptive PHP file names matching existing routing conventions.
- Keep one feature or domain responsible per include file.
- Do not place credentials, private keys, resident exports, or database dumps in
  source folders.
- Add migrations rather than editing existing table definitions as the first
  database change when the feature is additive.
