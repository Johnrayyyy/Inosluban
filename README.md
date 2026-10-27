# Barangay Inosluban Resident Portal

A beginner-friendly PHP starter project for a secure login and registration flow.

## Run it with XAMPP

1. Copy this folder to `C:\xampp\htdocs\PHP_project`.
2. Start **Apache** in the XAMPP Control Panel.
3. Open [http://localhost/PHP_project/](http://localhost/PHP_project/) in your browser.
4. Configure the PostgreSQL connection in `.env`, then choose **Create an account** to test registration.

The project uses the PHP version included with XAMPP. Make sure the `pdo_pgsql` extension is enabled in `php.ini`.

Document request confirmations and profile verification emails use PHPMailer
over SMTP. From the project folder, install the dependency:

```text
composer install
```

The application loads variables from the project-root `.env` file; variables
already provided by the server environment take precedence. For local XAMPP,
copy `.env.example` to `.env` and set the `DB_HOST`, `DB_PORT`, `DB_NAME`,
`DB_USER`, and `DB_PASSWORD` values using Render's external connection details.
For a web service hosted on Render, open its **Environment** settings and add
`DATABASE_URL` with the database's **Internal Database URL** (the service and
database must be in the same region). The app also accepts the individual
`DB_*` variables instead of `DATABASE_URL`, but don't set both forms to
different connection details. Keep `.env` private and never commit it. For
email, set `SMTP_HOST`,
`SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `MAIL_FROM`, and
`MAIL_FROM_NAME`; `SMTP_PASSWORD` must be a Gmail App Password, not the normal
account password, and `MAIL_FROM` should normally match `SMTP_USERNAME`.

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
