<?php

namespace App\Providers;

use App\Payments\PaymentGateway;
use App\Payments\StripeGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Đăng ký service. Bind interface PaymentGateway → StripeGateway (singleton),
     * tiêm secret/webhook-secret từ config. Nhờ bind ở một chỗ này mà toàn bộ
     * code chỉ phụ thuộc interface và dễ mock trong test.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, fn () => new StripeGateway(
            (string) config('payment.stripe.secret'),
            config('payment.stripe.webhook_secret'),
        ));
    }

    /**
     * Khởi động service. Định nghĩa rate limiter 'checkout' (gắn ở route
     * /checkout và /orders/{id}/pay).
     */
    public function boot(): void
    {
        // Giới hạn tần suất checkout để 1 user không spam "Mua" ôm chỗ
        // (payment_solutions §1.2 review #7). Khóa theo user đăng nhập, không có
        // thì fallback theo IP cho an toàn.
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(
            (int) config('payment.rate_limit.checkout_per_minute')
        )->by($request->user()?->id ?: $request->ip()));
    }
}
