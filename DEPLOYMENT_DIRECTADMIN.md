# DirectAdmin Deployment

This guide targets Apache-based DirectAdmin shared hosting. It does not require Docker, Redis, a daemon, a queue worker, Supervisor, WebSockets, or Node.js on the production server.

## 1. Server Requirements

- PHP 8.2 or newer, selected for the domain in DirectAdmin
- PHP extensions required by Laravel and PhpSpreadsheet: `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `mbstring`, `openssl`, `pcre`, `PDO`, `pdo_mysql`, `session`, `simplexml`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zip`, and `zlib`
- MySQL or MariaDB database and database user
- Composer 2 on the host, or a deployment archive that already contains production `vendor/`
- Apache rewrite support (`mod_rewrite`) and HTTPS for the production domain

The automated suite currently uses SQLite in-memory. It does not certify MySQL/MariaDB compatibility; verify migrations and critical flows against a staging database using the target engine before production use.

## 2. Build Assets Before Upload

On a development/build machine with Node.js installed:

```sh
npm ci
npm run build
```

Upload the generated `public/build` directory with the application. Production requests use these built assets; Node.js and `npm run dev` are not needed on the hosting account.

## 3. Upload and Document Root

1. Upload the application into a private directory outside the public web root where possible. Do not upload `.env`, local database files, `node_modules`, test output, or development credentials.
2. Configure the domain's document root to the application's `public` directory, for example `domains/example.test/public_html` pointing at the project `public/` directory through DirectAdmin's domain setup.
3. Do not expose the project root, `vendor`, `storage`, `bootstrap`, or `.env` through the web server.
4. Keep Laravel's `public/.htaccess` in place and ensure Apache rewrite support is enabled.
5. Confirm the domain serves `public/index.php` and the `public/build` assets over HTTPS.

## 4. Composer and Environment

From the project root using the hosting terminal:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
```

Create `.env` from `.env.example` and set values privately:

```dotenv
APP_NAME="Absensi TK/SD"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://your-domain.example

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password

SESSION_DRIVER=file
CACHE_STORE=file
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
```

Use the exact database host/name/user assigned by DirectAdmin. Generate the application key once, on the server:

```sh
php artisan key:generate
```

Do not put real credentials in `.env.example`, documentation, source control, or deployment logs. Keep `.env` private and never replace an existing production `APP_KEY` during routine deploys.

## 5. Database Setup

Create a MySQL/MariaDB database and a dedicated user through DirectAdmin, then grant that user access to that database. Run schema migrations non-destructively:

```sh
php artisan migrate --force
php artisan db:seed --class=UnitSeeder --force
```

The unit seeder is idempotent and creates TK/SD when absent. Never run `migrate:fresh` on production: it drops all tables and data. Take a verified database backup before deployment migrations.

## 6. Writable Directories

The PHP/web-server account must be able to write to:

- `storage/`
- `bootstrap/cache/`

Use the narrowest owner/group permissions supported by the hosting account (commonly owner-writable directories, not world-writable permissions). The import services read uploaded temporary files and do not publish them as public assets. No `storage:link` is required by the current application.

## 7. Cache and Deployment Commands

After `.env`, dependencies, assets, and database are ready:

```sh
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Run cache commands from the project root. Clear and rebuild these caches after changing environment/config/routes/views:

```sh
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

There is no required queue worker: `.env.example` selects synchronous queue processing. Do not enable database/Redis queue drivers unless the deployment process is intentionally changed and operated accordingly.

## 8. Apache and DirectAdmin Checklist

- Domain document root points to `public/`, not the project root.
- HTTPS is enabled and `APP_URL` uses the HTTPS origin.
- PHP 8.2+ and required extensions are enabled for both web requests and CLI.
- `public/build/manifest.json` exists and the generated files are accessible.
- `storage/` and `bootstrap/cache/` are writable by the PHP account.
- `.env` is outside public access; `APP_DEBUG=false` and a unique `APP_KEY` is configured.
- Database credentials use a dedicated, least-privilege account.
- Production database backups are taken before schema changes.
- Registration is reviewed/disabled if open account registration is not part of the deployment policy.
- No test database, sample attendance rows, or development files are deployed.

## 9. Troubleshooting

- **500 / blank page:** inspect Laravel logs in `storage/logs/laravel.log`; do not enable debug output on a public production site. Confirm writable directories and `APP_KEY`.
- **CSS/JS missing:** rebuild `public/build` and upload the complete directory; confirm `APP_URL` and the domain document root.
- **Database connection error:** verify DirectAdmin's database host, database name, user grants, PHP `pdo_mysql`, and that the same `.env` is used by CLI and Apache.
- **Route or view changes not appearing:** run `php artisan optimize:clear`, then rebuild route/view caches.
- **Excel import rejected:** confirm the file type, the 20 MB request validation limit, enabled PHP upload limits, and that `upload_max_filesize`/`post_max_size` allow the request.
- **Login/session problems:** confirm HTTPS and the appropriate `SESSION_SECURE_COOKIE`, `SESSION_DOMAIN`, and file permissions for the deployment domain.

Do not use `migrate:fresh` as a production troubleshooting step. Use `php artisan migrate:status` and a reviewed forward migration instead.