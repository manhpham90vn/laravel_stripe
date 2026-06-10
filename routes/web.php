<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\MyCourseController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|----------------------------------------------------------------------------
| Công khai (cho người mua, server-rendered Blade — spec §9)
|----------------------------------------------------------------------------
*/
Route::get('/', fn () => redirect()->route('courses.index'));
Route::get('/courses', [CourseController::class, 'index'])->name('courses.index');
Route::get('/courses/{slug}', [CourseController::class, 'show'])->name('courses.show');
Route::get('/batches/{id}', [BatchController::class, 'show'])->name('batches.show');

/*
|----------------------------------------------------------------------------
| Authentication
|----------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->name('register.store');
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');

/*
|----------------------------------------------------------------------------
| Hành động của người mua (yêu cầu đăng nhập)
|----------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::post('/batches/{id}/checkout', [CheckoutController::class, 'store'])
        ->middleware('throttle:checkout')   // §1.2: chặn ôm chỗ bằng cách checkout liên tục
        ->name('checkout.store');
    Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{id}/pay', [CheckoutController::class, 'pay'])
        ->middleware('throttle:checkout')
        ->name('orders.pay');
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::get('/my/courses', [MyCourseController::class, 'index'])->name('my.courses');
});

/*
|----------------------------------------------------------------------------
| Webhook Stripe (máy gọi máy — không auth/CSRF, xác thực bằng chữ ký §8.1)
|----------------------------------------------------------------------------
*/
Route::post('/webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');

/*
|----------------------------------------------------------------------------
| Admin (auth + vai trò admin — spec §11)
|----------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/courses', [Admin\CourseController::class, 'index'])->name('courses.index');
    Route::get('/courses/create', [Admin\CourseController::class, 'create'])->name('courses.create');
    Route::post('/courses', [Admin\CourseController::class, 'store'])->name('courses.store');
    Route::get('/courses/{course}/edit', [Admin\CourseController::class, 'edit'])->name('courses.edit');
    Route::put('/courses/{course}', [Admin\CourseController::class, 'update'])->name('courses.update');

    Route::get('/courses/{course}/batches', [Admin\BatchController::class, 'index'])->name('courses.batches.index');
    Route::post('/courses/{course}/batches', [Admin\BatchController::class, 'store'])->name('courses.batches.store');
    Route::patch('/batches/{batch}', [Admin\BatchController::class, 'update'])->name('batches.update');
    Route::get('/batches/{batch}/stats', [Admin\StatsController::class, 'show'])->name('batches.stats');

    Route::post('/orders/{order}/refund', [Admin\RefundController::class, 'store'])->name('orders.refund');
});

// Component gallery (dev reference).
Route::get('/ui-kit', fn () => view('ui-kit'));
