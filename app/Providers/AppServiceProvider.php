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
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, fn () => new StripeGateway(
            (string) config('payment.stripe.secret'),
            config('payment.stripe.webhook_secret'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Throttle checkout so a single user can't spam "Mua" and hoard slots
        // (payment_solutions §1.2 review #7). Keyed per authenticated user,
        // falling back to IP for safety.
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(
            (int) config('payment.rate_limit.checkout_per_minute')
        )->by($request->user()?->id ?: $request->ip()));
    }
}
