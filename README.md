<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

# Manabi — bán khóa học theo đợt mở bán (Laravel + Stripe)

Web bán khóa học server-rendered bằng Blade, chống bán quá số lượng bằng
**reserve-with-timeout** (transaction + `lockForUpdate`), Stripe Checkout hosted,
và **webhook là nguồn sự thật**. Đặc tả: [`docs/`](./docs).

## Chạy local

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate     # nếu chưa có .env

# DB (mặc định sqlite) + dữ liệu demo
php artisan migrate:fresh --seed

# Stripe — dùng TEST mode
# .env: STRIPE_SECRET=sk_test_...  STRIPE_WEBHOOK_SECRET=whsec_...
# Forward webhook về local:
#   stripe listen --forward-to localhost:8000/webhooks/stripe

# 3 tiến trình khi phát triển:
npm run dev                 # assets (Vite + Tailwind v4)
php artisan serve           # web
php artisan queue:work      # worker (jobs nền)
php artisan schedule:work   # scheduler (dọn reservation, sync trạng thái đợt)
```

**Tài khoản demo:** buyer `test@example.com` / admin `admin@example.com` — mật khẩu `password`.
Trang: `/courses` (mua), `/my/courses`, `/admin/courses` (admin), `/ui-kit` (component gallery).
Thanh toán test dùng thẻ `4242 4242 4242 4242`; Konbini test để thử luồng async.

## Kiến trúc (tóm tắt)

- **Chống oversell:** `app/Services/ReservationService.php` — mọi thay đổi `slots_taken`
  nằm trong transaction + row lock trên `sale_batches` (NFR-1/BR-1). Mỗi `(user,batch)`
  tối đa 1 order live / 1 reservation active qua **partial unique index** (BR-2).
- **Webhook idempotent:** `StripeEventProcessor` (dedupe theo `event.id`) →
  `PaymentEventHandler` (mọi chuyển trạng thái order, idempotent + audit).
- **Thanh toán:** `App\Payments\PaymentGateway` ⇽ `StripeGateway` (Checkout hosted).
- **Jobs/scheduler:** `ReleaseExpiredReservations`, `SyncBatchStatuses` (mỗi phút).
- **Test:** `php artisan test` — phủ AC-1 (oversell), AC-2, AC-3 (idempotency), AC-4, AC-7, AC-8.

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

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
