# Absensi Pegawai TK/SD

Laravel attendance administration for TK and SD units. It manages employee master data, imports fingerprint logs and leave requests, processes attendance against schedules and calendars, and provides period/yearly reports with XLSX exports.

## Stack

- PHP 8.2+, Laravel 12, Livewire 3, Blade, Tailwind CSS, and Vite
- MySQL or MariaDB for deployment; SQLite in-memory for the automated test suite
- PhpSpreadsheet for Excel import and export
- Apache/DirectAdmin-compatible deployment; production does not require Node.js, Redis, a queue worker, Docker, or a WebSocket server

## Local Setup

1. Install PHP dependencies and frontend packages:

   ```sh
   composer install
   npm ci
   ```

2. Create the environment file and application key:

   ```sh
   cp .env.example .env
   php artisan key:generate
   ```

   On Windows PowerShell, use `Copy-Item .env.example .env` instead of `cp`.

3. Set `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env` for a local MySQL/MariaDB database. The `DatabaseSeeder` creates the TK and SD unit records.

4. Apply migrations and seed the unit records:

   ```sh
   php artisan migrate --seed
   ```

   `migrate:fresh` drops all tables; use it only against a disposable database.

5. Start the application and Vite during development:

   ```sh
   npm run dev
   php artisan serve
   ```

## Tests and Production Assets

The test configuration in `phpunit.xml` uses SQLite in-memory and does not require a running MySQL server.

```sh
php artisan test
npm run build
```

The production assets are written to `public/build`. Do not run `npm run dev` in production. See [DEPLOYMENT_DIRECTADMIN.md](DEPLOYMENT_DIRECTADMIN.md) for hosting setup.

## Application Routes

- `/dashboard`
- `/employees/manage`
- `/fingerprint-import` and `/leave-import`
- `/work-calendar`
- `/rekap-periode`, `/rekap-tahunan`, and `/rekap/pegawai/{employee}`
- Protected JSON/report/export endpoints are listed by `php artisan route:list`.

All administration and reporting pages require authentication. Import and attendance source records remain read-only during reporting and export.# Rekap_Absensi

## Kalender Operasional Sekolah

The existing `/work-calendar` page manages manual school decisions, candidate national holidays, and date-scoped schedule profiles. A national-calendar sync uses the documented Nager.Date public-holidays endpoint (`/PublicHolidays/{year}/ID`). It provides public-holiday candidates only; it does not supply Indonesian collective-leave decisions or authoritative Ramadan dates. Admins must compare each candidate with an official government notice, provide its `.go.id` reference to mark the source verified, and separately create a school calendar entry to change attendance processing. Pending or verified API events never directly alter attendance.

`NAGER_DATE_BASE_URL` and `NAGER_DATE_COUNTRY` in `.env` configure the public endpoint and country code; no API key is required. Run `php artisan migrate` to add the national-event staging and schedule-period tables without resetting existing data. Schedule periods reuse the existing day-of-week schedule profiles, are scoped by unit (one or both) and employee category, and reject overlapping active rules of equal scope. Their saved attendance times are snapshots; changing a calendar or period does not automatically rewrite historical attendance records. The daily attendance processor uses the explicit calendar override first, then an active scoped schedule period, then the regular TK/SD schedule; Sunday and TK Saturday are routine rest days, and SD's fifth Saturday is a rest day unless an explicit school calendar decision overrides it.# Rekap_Absensi
