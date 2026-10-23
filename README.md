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

Only santri can create reports, list their own reports, or toggle identity on a report they own. Staff can view reports and their internal notes; santri responses omit internal notes. When a report hides identity, the owner's name is masked in staff report lists, details, and messages, and `users_id` is omitted from staff payloads.

`pengurus` cannot read or act on reports with category `masalah_pengurus`. The report list, notification summary, and notification list exclude those reports for `pengurus`; detail, messages, status, notes, and escalation endpoints return `403`. `pimpinan` retains full access. `permintaan_identitas` is always `null` for `pengurus`, so the reason, evidence URL, and requester are not sent to them.

After a santri approves an identity request, `pimpinan` receives the reporter's real name in `user.name` (and in notification senders). Message payloads expose `pengirim.id`, `pengirim.name`, and `pengirim.role` so the client can distinguish the sender without seeing hidden names.

Logout is available at `POST /api/auth/logout`.

## Authentication and User Profile API

`POST /api/auth/login` and `GET /api/user` return the same user object shape. For `santri`, `santri` contains `nis` and `telepon_ortu`; for other roles it is `null`. `GET /api/user` remains accessible while `must_change_password` is `true`, so the client can render the profile before redirecting to the change-password page.

Example login response (token redacted):

```json
{
  "access_token": "<redacted>",
  "token_type": "Bearer",
  "user": {
    "id": 3,
    "name": "Ahmad Fauzi",
    "email": "ahmad@santri.test",
    "email_verified_at": null,
    "role": "santri",
    "is_active": true,
    "must_change_password": false,
    "santri": {
      "nis": "20240001",
      "telepon_ortu": "081234567890"
    },
    "created_at": "2026-10-23T10:00:00.000000Z",
    "updated_at": "2026-10-23T10:00:00.000000Z"
  },
  "role": "santri",
  "dashboard": "/dashboard/santri",
  "must_change_password": false,
  "message": "Login berhasil"
}
```

`GET /api/user` returns the `user` object directly, without the token and dashboard fields. New accounts created by `POST /api/admin/accounts` and new santri created by `POST /api/admin/santri/import` have `must_change_password: true`. `POST /api/auth/password` sets it to `false`.

`GET /api/santri` keeps its user-list shape but now includes `nis`, `telepon_ortu`, and a nested `santri` object for each item. `GET /api/santri/{id}` returns the santri record with its `user` relation instead of an empty body.

## Notification API

- `GET /api/notifications/summary` returns the number of reports with updates for the logged-in user. The response includes an `ETag`; send it back as `If-None-Match` to receive `304 Not Modified` while nothing has changed.
- `GET /api/notifications?page=1&per_page=20` returns unread notifications, newest first, as `data.items` with pagination `data.meta` (`current_page`, `per_page`, `total`, `last_page`, `from`, `to`). `per_page` is limited to 100.
- Granularity is one notification per category per report, matching the summary, so `data.meta.total` equals the summary's `pembaruan` value. Each item contains a numeric `id` that is unique per category per report (`laporan_id * 10 + category code`), `tipe` (`laporan_baru`, `pesan_baru`, `status_diubah`, or `permintaan_identitas`), `laporan_id`, `no_laporan`, `kategori`, `waktu` (ISO 8601), `status_baru` (the report status for `laporan_baru`/`status_diubah`, otherwise `null`), `pengirim` (`id`, `name`, `role`, or `null` when identity is hidden), `preview`, and `url` pointing to the report detail endpoint.
- Opening the list does not mark reports as read. Reading the report detail or its messages updates the role-specific read column, so the summary and list stay in sync.

## Dashboard and Santri Import API

The dashboard and santri import endpoints require a Bearer token from a `pimpinan` account:

- `GET /api/dashboard/summary` returns santri and report totals grouped by status and category.
- `GET /api/admin/accounts` lists account details without exposing passwords.
- `PATCH /api/admin/accounts/{userId}/status` toggles an account's active status. Deactivating an account revokes its tokens; the current and last active pimpinan accounts cannot be deactivated.
- `GET /api/admin/santri/export` downloads current santri data as Excel without `id_santri` or `password`, so IDs and credentials are never shown to users.
- `GET /api/admin/santri/template` downloads the Excel template. Export and template use the same columns and column widths.
- `POST /api/admin/santri/import` accepts an `.xlsx` file and an `action` of `preview` or `import`. Preview does not save data; import revalidates the uploaded workbook and saves only if every non-empty row is valid. Upload the same workbook again with `action=import` after reviewing the preview. Rows without `id_santri` are matched to existing santri by `nis` or `email`; unmatched rows are created with an auto-generated ID. Legacy files that still contain `id_santri` or `password` remain supported. Santri missing from the file are not deleted.

The template columns are `name`, `email`, `nis`, `jenis_kelamin`, and `telepon_ortu`. New santri receive the default password `12345678` and must change it after their first login. Existing santri keep their current password. Each import is limited to 500 rows and 5 MB.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
