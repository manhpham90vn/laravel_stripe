<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessStripeEvent;
use App\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/stripe — endpoint "máy gọi máy" DUY NHẤT. Không auth/CSRF; được
 * bảo vệ bằng XÁC THỰC CHỮ KÝ (spec §8.1, §11). Webhook là nguồn sự thật cho
 * trạng thái thanh toán (D5).
 *
 * Luồng: verify chữ ký → đẩy sang queued job → trả 200 thật nhanh, để Stripe
 * không timeout và retry (jobs_and_scheduler §4, §7). Mọi xử lý nặng nằm ở job.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway)
    {
        try {
            // Chữ ký sai → ném exception (xử lý ở catch).
            $event = $gateway->constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature'),
            );
        } catch (\Throwable $e) {
            Log::warning('Stripe webhook signature verification failed', ['error' => $e->getMessage()]);

            return response('Invalid signature', 400); // không xử lý payload không tin được
        }

        // Đẩy việc xử lý ra khỏi luồng request để trả 200 ngay.
        ProcessStripeEvent::dispatch($event);

        return response('ok', 200);
    }
}
