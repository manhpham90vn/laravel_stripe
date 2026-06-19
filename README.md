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
