<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Local Development (Docker)

This project is configured for Docker with Laravel Sail.

- Start containers: `./vendor/bin/sail up -d`
- Stop containers: `./vendor/bin/sail down`
- Run migrations: `./vendor/bin/sail artisan migrate`
- Run Vite in Docker: `./vendor/bin/sail npm run dev -- --host`

Service URLs:

- App: `http://localhost:8000`
- Mailpit: `http://localhost:8025`

### Yandex Reviews Headless Scraper (Playwright)

Run everything inside Docker (`sail`), not on host macOS.

1. Recreate containers to apply Playwright env vars:
   - `./vendor/bin/sail down`
   - `./vendor/bin/sail up -d --build`
2. Install Node packages + Chromium inside `laravel.test`:
   - `./vendor/bin/sail bash -lc "cd /var/www/html && bash scripts/yandex-reviews/install-playwright.sh --skip-deps"`
3. Install Linux system dependencies for Playwright (once, as root):
   - `./vendor/bin/sail exec -u root laravel.test bash -lc "cd /var/www/html && bash scripts/yandex-reviews/install-playwright.sh --only-deps"`
4. Clear Laravel config cache after env changes:
   - `./vendor/bin/sail artisan config:clear`

Smoke test (Chromium launch check inside container):

- `./vendor/bin/sail bash -lc "cd /var/www/html && node scripts/yandex-reviews/smoke.mjs"`

Manual parser run inside container:

- `./vendor/bin/sail bash -lc 'cd /var/www/html && node scripts/yandex-reviews/scrape.mjs --url "https://yandex.ru/maps/org/samoye_populyarnoye_kafe/1010501395/reviews/" --max-scroll-steps 20 --timeout-ms 120000'`

Useful env vars:

- `PLAYWRIGHT_BROWSERS_PATH=/var/www/html/storage/ms-playwright`
- `YANDEX_SCRAPER_BROWSERS_PATH=/var/www/html/storage/ms-playwright`
- `YANDEX_SCRAPER_RETRIES=2`
- `YANDEX_SCRAPER_TIMEOUT_MS=180000`

### DBeaver MySQL Connection

Use these settings in DBeaver:

- Engine: `MySQL`
- Host: `127.0.0.1`
- Port: `3307`
- Database: `laravel`
- User: `sail`
- Password: `password`

If port `3307` is already occupied, change `FORWARD_DB_PORT` in `.env` and restart containers.

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

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
