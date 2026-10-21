<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## Report API Identifiers

Every `{laporan}` path segment in the report API is the numeric `id` primary key from the report response/database, not `no_laporan`. For example, use `/api/reports/12/status` to update the report whose `id` is `12`.

Only santri can create reports, list their own reports, or toggle identity on a report they own. Staff can view reports and their internal notes; santri responses omit internal notes. When a report hides identity, the owner's name is masked in staff report lists, details, and messages.

Logout is available at `POST /api/auth/logout`.

## Dashboard and Santri Import API

The dashboard and santri import endpoints require a Bearer token from a `pimpinan` account:

- `GET /api/dashboard/summary` returns santri and report totals grouped by status and category.
- `GET /api/admin/accounts` lists account details without exposing passwords.
- `PATCH /api/admin/accounts/{userId}/status` toggles an account's active status. Deactivating an account revokes its tokens; the current and last active pimpinan accounts cannot be deactivated.
- `GET /api/admin/santri/export` downloads current santri data as Excel, including `id_santri` for updates and a blank password column.
- `GET /api/admin/santri/template` downloads the Excel template.
- `POST /api/admin/santri/import` accepts an `.xlsx` file and an `action` of `preview` or `import`. Preview does not save data; import revalidates the uploaded workbook and saves only if every non-empty row is valid. Upload the same workbook again with `action=import` after reviewing the preview. Files exported with `id_santri` update matching santri; rows without that ID create new santri. Santri missing from the file are not deleted.

The template columns remain `name`, `email`, `nis`, `jenis_kelamin`, `telepon_ortu`, and `password`. Password is required for new santri and must contain at least 4 characters; leave it blank for existing santri to retain the current password. Newly created santri are not required to change their password at first login. Each import is limited to 500 rows and 5 MB.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
