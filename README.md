# Admin Starter

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)
![Tailwind CSS](https://img.shields.io/badge/Tailwind-v4-38BDF8?logo=tailwindcss&logoColor=white)
![PHPUnit](https://img.shields.io/badge/PHPUnit-12-44A833)
![Tests](https://img.shields.io/badge/tests-37%20passing-brightgreen)
![License](https://img.shields.io/badge/license-MIT-blue)

A **single-tenant Laravel admin panel starter kit**. It ships authentication (2FA + email verification), a flat role & permission system with a management UI, audit logging, and operational tooling — on a modern, **Bootstrap/jQuery-free** admin theme (Tailwind v4 + Preline). Add your own domain models on top and go.

## Features

- **Authentication** — login, logout, password reset, email verification, and optional enforced **TOTP two-factor** with recovery codes.
- **Authorization** — roles + permissions (many-to-many via `role_user`), a super-admin bypass, a **Roles & Permissions management UI**, and permission-gated routes.
- **User management** — CRUD, enable/disable, lock/unlock, role assignment.
- **Audit logging** — `AuditLogger` service + facade, with a filterable log viewer.
- **Ops tooling** — log viewer, queue monitor (jobs/failed jobs with retry & flush), and a maintenance-mode toggle.
- **Account settings** — profile, password, light/dark theme, 2FA management.
- **Reusable components** — `x-admin-v2.*` Blade components: cards, datatables, modals, offcanvas, alerts, form inputs, stat cards, status badges.

## Tech stack

- **Laravel 13** · **PHP 8.3+**
- **Laravel Sanctum** — API token auth
- **pragmarx/google2fa** + **bacon/bacon-qr-code** — two-factor
- **Tailwind CSS v4**, **Preline UI**, **DataTables**, **Choices.js**, **Lucide**, **SweetAlert2** — bundled with **Vite**
- **mews/purifier** — HTML sanitization for WYSIWYG input
- **Sentry** — optional error tracking
- **PHPUnit 12**, **Larastan** (PHPStan level 5), **Laravel Pint**

## Requirements

- PHP 8.3+ and Composer 2
- Node.js 20+
- A database — SQLite by default; MySQL/PostgreSQL supported

## Installation

```bash
git clone <your-repo> my-app && cd my-app

composer install          # Windows: composer install --prefer-source  (see Notes)
npm install

cp .env.example .env
php artisan key:generate

touch database/database.sqlite   # default SQLite database
php artisan migrate --seed

npm run build                    # or `npm run dev` for HMR
php artisan serve
```

Sign in at `/login` with the seeded super admin:

| Email | Password |
|-------|----------|
| `admin@example.com` | `password` |

> Change these immediately — see `database/seeders/UserSeeder.php`.

## Roles & permissions

- Seeded roles: **super-admin** (bypasses every authorization check), **admin**, **user**.
- Permissions live in `app/Enums/Permission.php` and are attached to roles via the **Roles & Permissions** screen (or `RolePermissionSeeder`). Built-in roles cannot be deleted.
- Check access in code:
  ```php
  $user->hasPermission('users.view');
  $user->hasRole('admin');          // or hasRole(['admin', 'user'])
  $user->isSuperAdmin();
  ```
- Gate a route/controller with `Gate::authorize('viewAny-audit-logs')` or a policy ability — super-admins are allowed through automatically via `Gate::before`.

## Conventions & best practices

- **No Bootstrap, no jQuery.** Use Preline (`HSOverlay`), the DataTables native API, Choices.js, the global `Alert.*` helpers (SweetAlert2), and Lucide icons.
- **Lucide icons are tree-shaken** — every `data-lucide="..."` must be registered in the import list in `resources/js/admin-v2/vendor.js`, or it won't render. Add new icons there.
- **New admin pages** — build with `x-admin-v2.*` components, register routes inside the `admin` group in `routes/web.php`, and gate them with permissions.
- **Two-factor enforcement** — set `AUTH_TWO_FACTOR_REQUIRED=true` to require all users to enrol before using the panel.
- **Audit meaningful actions** with the `AuditLogger` facade.
- Run `vendor/bin/pint` before committing; CI also runs PHPStan (level 5) and the test suite.

## Testing

```bash
php artisan test
```

37 tests cover authentication (login, password reset, email verification, 2FA enrolment + challenge), admin access control, user roles, and role management. CI (GitHub Actions) runs Pint, PHPStan, `composer audit`, the build, and the test suite.

## Notes

- **Windows + Composer** — extracting packages that contain symlinks (e.g. `sentry/sentry-laravel`) can fail with *"A required privilege is not held by the client."* Use `composer install --prefer-source`, or enable Windows Developer Mode.
- A few packages inherited from the source project remain installed but unused — `motomedialab/smtp2go`, `league/csv`, `league/flysystem-aws-s3-v3`. Remove them if your project doesn't need SMTP2Go email tracking, CSV handling, or S3 storage.

## License

Released under the [MIT License](https://opensource.org/licenses/MIT).
