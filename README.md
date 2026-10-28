# Barangay Inosluban Resident Portal

A beginner-friendly PHP starter project for a secure login and registration flow.

## Run it with XAMPP

1. Copy this folder to `C:\xampp\htdocs\PHP_project`.
2. Start **Apache** in the XAMPP Control Panel.
3. Open [http://localhost/PHP_project/](http://localhost/PHP_project/) in your browser.
4. Configure the PostgreSQL connection in `.env`, then choose **Create an account** to test registration.

The project uses the PHP version included with XAMPP. Make sure the `pdo_pgsql`
and `curl` extensions are enabled in `php.ini`. Docker deployments install both
extensions in the image.

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
and `DB_PASSWORD` settings instead of `DATABASE_URL`. For local XAMPP, create
a project-root `.env` file and use Render's external database connection
details. Never commit `.env` or include it in the Docker image. For email, set
`SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `MAIL_FROM`, and
`MAIL_FROM_NAME`. For Brevo, use `smtp-relay.brevo.com`, your Brevo SMTP login
and SMTP key, and a sender email verified in Brevo as `MAIL_FROM`. On Render,
the app automatically switches Brevo's configured port `587` to `2525`
because Render blocks outbound SMTP on port `587`. Other SMTP providers keep
using their configured port.

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
The admin Dashboard route opens `admin_analytics.php`. The analytics page
summarizes service, weather-advisory, alert, notification, and staff-time data.
Hourly weather refreshes are also recorded as observations, with recent saved
advisories imported as initial history for the weather trend.
Its AI outlook sends only aggregated metrics and the latest weather forecast to
Gemini; no resident identities, account usernames, passwords, or contact details
are included. Revenue is shown as untracked until payment collection is added.
The registration terms are a working draft based on Philippine privacy
requirements. Confirm the barangay's privacy contact, retention schedule, and
service-specific data practices with its Data Protection Officer or legal
adviser before relying on them as a final legal policy.

## Folder guide

- `index.php` — login page and login processing.
- `register.php` — registration page and validation.
- `dashboard.php` — the first page shown after login.
- `admin_analytics.php` — aggregate admin dashboard and AI outlook.
- `logout.php` — safely ends the session.
- `config/` — application settings.
- `includes/bootstrap.php` — starts sessions, connects to SQLite, and contains shared security helpers.
- `includes/` — reusable page layout and alerts.
- `assets/css/` — responsive styling.
- `assets/js/` — small browser enhancements such as show/hide password.
- `assets/images/` — the supplied login reference, `barangay.jpg` page background, and barangay logo.
- `database/` — private SQLite storage (blocked from direct Apache access).

## Security included

- Passwords are stored with `password_hash()` and checked with `password_verify()`.
- Database queries use PDO prepared statements.
- POST forms use a session-based CSRF token.
- Session IDs are regenerated after successful login.
- Output is escaped with `htmlspecialchars()` to reduce XSS risk.
- Server-side validation is applied even when browser validation is bypassed.
- The database folder is denied by Apache.

## Growing the project

When adding a feature, keep the same pattern: put database logic in a small helper or service, validate all incoming values on the server, use prepared statements, and reuse the shared header/footer. Add new tables through a migration step once the project becomes larger.
