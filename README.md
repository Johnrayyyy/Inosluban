# Barangay Inosluban Resident Portal

A beginner-friendly PHP starter project for a secure login and registration flow.

## Run it with XAMPP

1. Copy this folder to `C:\xampp\htdocs\PHP_project`.
2. Start **Apache** in the XAMPP Control Panel.
3. Open [http://localhost/PHP_project/](http://localhost/PHP_project/) in your browser.
4. Configure the local PostgreSQL connection in `.env.local` (see below), then choose **Create an account** to test registration.

The project uses the PHP version included with XAMPP. Make sure the `pdo_pgsql`
and `curl` extensions are enabled in `php.ini`. Docker deployments install both
extensions in the image.

### Local PostgreSQL database

The XAMPP setup uses an isolated PostgreSQL 18 instance on `127.0.0.1:5433`,
separate from any other PostgreSQL service or remote database. The local
`portal_inosluban` database is initialized from `schema.sql`. Its connection
settings are in the ignored `.env.local` file; the app loads these before the
project `.env`, so local development does not change remote database settings.
Do not commit either environment file.

The local database runs independently of Apache. If you restart Windows, start
the local instance from PowerShell before opening the app:

```powershell
& 'C:\Program Files\PostgreSQL\18\bin\pg_ctl.exe' `
  -D 'C:\xampp\pgsql-local-data' `
  -l 'C:\xampp\pgsql-local.log' `
  -o '-h 127.0.0.1 -p 5433' -w start
```

To stop only this local instance:

```powershell
& 'C:\Program Files\PostgreSQL\18\bin\pg_ctl.exe' `
  -D 'C:\xampp\pgsql-local-data' stop -m fast
```

Document request confirmations and profile verification emails use PHPMailer
over SMTP. Docker deployments install Composer dependencies as part of the
image build. For a local XAMPP setup, install the dependency from the project
folder:

```text
composer install
```

The application loads configuration from Render's `/etc/secrets/.env` file
first, then the project-root `.env` file. Values set in the service's
environment take precedence over both files. For a Render-hosted web service,
open **Environment → Secret Files**, add a file named `.env`, and put your
settings in it. Use the database's **Internal Database URL** when the web
service and database are in the same region:

```text
DATABASE_URL=postgresql://username:password@hostname:5432/database
```

The app also supports individual `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`,
and `DB_PASSWORD` settings instead of `DATABASE_URL`. For local XAMPP, use a
separate PostgreSQL database and put its credentials in `.env.local`; this file
overrides the project `.env` for local development and is ignored by Git. Copy
`.env.local.example`, then change the password to the local database user's
password. Never commit `.env` or `.env.local`, or include them in the Docker
image. For email, set
`SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `MAIL_FROM`, and
`MAIL_FROM_NAME`. For Brevo, use `smtp-relay.brevo.com`, your Brevo SMTP login
and SMTP key, and a sender email verified in Brevo as `MAIL_FROM`. On Render,
the app automatically switches Brevo's configured port `587` to `2525`
because Render blocks outbound SMTP on port `587`. Other SMTP providers keep
using their configured port.

SMS notifications use the PHILSMS API. Set `PHILSMS_API_KEY` to the API key
from your PHILSMS dashboard and `PHILSMS_SENDER_ID` to your approved sender ID.
When a document request is marked ready, the resident gets a short pickup text;
when an admin approves a weather forecast announcement, one short text is sent
to each active resident with a valid Philippine mobile number on file. The
messages use plain ASCII and stay below 160 characters to minimize SMS credits.
Admins can publish and resolve disaster-preparedness alerts from the dedicated
Disaster Preparedness navigation page and send them to active residents. The
analytics dashboard shows active-alert counts and a short alert summary. Each
SMS attempt is recorded for delivery review. SMS is optional; sending is
unavailable until both settings are configured.

The login page's password recovery sends a one-time email link that expires
after one hour. The default application URL is
`https://inosluban.onrender.com`; set `APP_BASE_URL` to the deployed origin (or
its `index.php` URL) when deploying elsewhere. Email links to application routes
are built from that origin. The reset-token table is also included in
`schema.sql` and is created automatically when password recovery is first used.
Document requests can be cancelled by their resident only while Pending. When
staff marks a document request Completed (ready for pickup), the portal emails a
locally generated QR code in the branded email template. The QR opens the
deployed staff verification route. An authenticated staff member scans it,
checks the request details against the person's valid photo ID, then confirms release.
Document pickup tokens are single-use; the portal creates the token table and
adds the Released status constraint automatically for existing databases.
Staff and admins can prepare the supplied Clearance, Indigency, and Residency
PDF originals from a request, edit the printout fields, and open the completed
PDF for printing. Printout edits do not change the resident's submitted record.
The source files live in `storage/document_templates/`, which denies direct web
access; printing is handled by the authenticated staff-only route. Document
request lists show open requests by default, provide completed/released and all
request filters, and paginate ten records at a time. Admin audit logs can be
searched across actor, action, record, table, and details, with Philippine-date
filters.
The admin weather page fetches Open-Meteo current conditions, a seven-day
forecast, and hourly data, with MET Norway as a no-key fallback if Open-Meteo
is unavailable or rate-limited. Docker deployments install PHP cURL for these
connections. The admin weather dashboard reads its latest complete
current/hourly/daily payload from the `weather_snapshot` table; refresh runs
independently of resident or admin sign-in. A successful admin login also
triggers a background refresh from the admin dashboard, with a ten-minute
cooldown and a PostgreSQL advisory lock to prevent overlapping requests or
repeated logins from exhausting provider limits.

To enable the hourly schedule on Render, create a **Cron Job** for this same
repository and deployment branch. Use the same Dockerfile and region as the web
service, set the schedule to `0 * * * *` (hourly, UTC), and set the command to:

```text
php /var/www/html/weather_cron.php
```

Attach the same environment group as the web service, or configure the same
`DATABASE_URL` and weather settings. The cron job does not need SMTP credentials.
It writes the current, hourly, and daily forecast payload to PostgreSQL; the
dashboard displays the latest successful snapshot and reports refresh failures.
After the cron job's first run, the dashboard updates automatically without
anyone pressing a fetch button or being signed in.
Add a second scheduled job at `5 16 * * *` UTC (12:05 AM Philippine time) with
the command:

```text
php /var/www/html/weather_daily_cron.php
```

The daily job archives all weather observations collected during the previous
Philippine calendar day, including temperature, rainfall, wind, cloud cover,
condition summaries, and source timestamps. It is safe to rerun and updates
that day's archive instead of creating duplicates. The admin weather dashboard
shows the archived daily history. Forecast rain and thunderstorms create
pending announcement drafts; only an admin can approve and publish or dismiss
those drafts from Manage Announcements.
The admin Dashboard route opens `admin_analytics.php`. The analytics page
summarizes service, weather-advisory, alert, notification, and staff-time data.
Its monthly activity chart compares document requests, blotter reports, and
appointment submissions. Staff time logs use activity heartbeats and stop
counting after ten minutes without activity; logging out closes the active log.
Hourly weather refreshes are also recorded as observations, with recent saved
advisories imported as initial history for the weather trend.
Its AI outlook sends only aggregated metrics and the latest weather forecast to
Gemini; no resident identities, account usernames, passwords, or contact details
are included. Household analytics summarize member counts, household sizes,
purok distribution, and age bands for active residents plus unlinked household
members. Completing a document request automatically marks its fixed ₱20.00
fee as paid; the dashboard reports these recorded fees as collected revenue.
The deployment applies idempotent database migrations for the payment fields.
The registration terms are a working draft based on Philippine privacy
requirements. Confirm the barangay's privacy contact, retention schedule, and
service-specific data practices with its Data Protection Officer or legal
adviser before relying on them as a final legal policy.

Admins can assign newly created staff as General Staff or BHW. The Household
Registry is available only to admins and BHW accounts. BHW accounts cannot
access document, blotter, or appointment requests. The registry records
household address, purok, an optional house number unique within its purok,
household head, and member roster details; residents can be searched by name
across household rosters. BHWs can link
registered residents or add unregistered people. On successful resident account
creation, the system links an unregistered roster entry only when the full name
and birthdate match exactly one household member. Ambiguous matches are left
unlinked for manual assignment.

## Folder guide

- `index.php` — login page and login processing.
- `register.php` — registration page and validation.
- `dashboard.php` — the first page shown after login.
- `admin_analytics.php` — aggregate admin dashboard and AI outlook.
- `staff_document_templates.php` — staff-only field editing and PDF generation, opened from document request details; request-list View opens the populated PDF in a new tab.
- `logout.php` — safely ends the session.
- `config/` — application settings.
- `includes/bootstrap.php` — starts sessions, connects to SQLite, and contains shared security helpers.
- `includes/` — reusable page layout and alerts.
- `assets/css/` — responsive styling.
- `assets/js/` — small browser enhancements such as show/hide password.
- `assets/images/` — the supplied login reference, `barangay.jpg` page background, and barangay logo.
- `database/` — private SQLite storage (blocked from direct Apache access).
- `storage/document_templates/` — original PDFs, blocked from direct Apache access.

## Security included

- Passwords are stored with `password_hash()` and checked with `password_verify()`.
- Database queries use PDO prepared statements.
- POST forms use a session-based CSRF token.
- Session IDs are regenerated after successful login.
- Output is escaped with `htmlspecialchars()` to reduce XSS risk.
- Server-side validation is applied even when browser validation is bypassed.
- The database folder is denied by Apache.
- Original document templates are denied by Apache and are only used by the staff-authenticated PDF route.

## Growing the project

When adding a feature, keep the same pattern: put database logic in a small helper or service, validate all incoming values on the server, use prepared statements, and reuse the shared header/footer. Add new tables through a migration step once the project becomes larger.
