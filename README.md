# Barangay Inosluban Resident Portal

A beginner-friendly PHP starter project for a secure login and registration flow.

## Run it with XAMPP

1. Copy this folder to `C:\xampp\htdocs\PHP_project`.
2. Start **Apache** in the XAMPP Control Panel.
3. Open [http://localhost/PHP_project/](http://localhost/PHP_project/) in your browser.
4. Configure the PostgreSQL connection in `.env`, then choose **Create an account** to test registration.

The project uses the PHP version included with XAMPP. Make sure the `pdo_pgsql` extension is enabled in `php.ini`.

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

## Folder guide

- `index.php` — login page and login processing.
- `register.php` — registration page and validation.
- `dashboard.php` — the first page shown after login.
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
