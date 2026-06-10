<?php

namespace App\Http\Controllers;

use App\Exceptions\CheckoutException;
use App\Models\Order;
use App\Models\SaleBatch;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    public function __construct(
        private ReservationService $reservations,
        private PaymentGateway $gateway,
    ) {}

    /**
     * POST /batches/{id}/checkout — giữ chỗ, tạo đơn, rồi redirect sang Stripe
     * Checkout (spec §7.1). Lỗi nghiệp vụ thì redirect-back kèm flash (PRG, §9).
     */
    public function store(Request $request, int $id)
    {
        $batch = SaleBatch::findOrFail($id);

        try {
            $order = $this->reservations->reserve($batch, $request->user());
        } catch (CheckoutException $e) {
            // Người mua đã có sẵn một đơn live cho đợt này (vd họ bắt đầu checkout,
            // bấm Back rồi bấm Mua lại). Đưa họ về đúng đơn đó để tiếp tục trả hoặc
            // hủy — không để rơi vào ngõ cụt.
            if ($e->errorCode === 'ALREADY_PURCHASED'
                && $existing = $this->liveOrderFor($batch, $request->user())) {
                $message = $existing->status === Order::STATUS_PAID
                    ? 'Bạn đã mua đợt này rồi.'
                    : 'Bạn đang có đơn chưa hoàn tất cho đợt này. Tiếp tục thanh toán hoặc hủy đơn bên dưới.';

                return redirect()->route('orders.show', $existing)->with('status', $message);
            }

            return redirect()
                ->route('batches.show', $batch)
                ->with('error', $e->getMessage());
        }

        return $this->startCheckout($order, $batch);
    }

    /**
     * POST /orders/{id}/pay — tạo lại Checkout Session cho một đơn đang
     * pending/processing (vd retry sau lỗi gateway tạm thời, spec §12). Chỗ đã
     * được giữ rồi nên KHÔNG tạo reservation mới. Status khác → 409.
     */
    public function pay(Request $request, int $id)
    {
        $order = Order::with('saleBatch')->findOrFail($id);
        $this->authorize('view', $order);

        abort_unless(in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_PROCESSING], true), 409);

        return $this->startCheckout($order, $order->saleBatch);
    }

    /** Đơn còn "sống" của người mua cho đợt này (BR-2), nếu có. */
    private function liveOrderFor(SaleBatch $batch, User $user): ?Order
    {
        return Order::where('sale_batch_id', $batch->id)
            ->where('user_id', $user->id)
            ->whereIn('status', Order::LIVE_STATUSES)
            ->latest('id')
            ->first();
    }

    /** Tạo session hosted rồi redirect; lỗi thì giữ đơn pending để retry. */
    private function startCheckout(Order $order, SaleBatch $batch)
    {
        try {
            $session = $this->gateway->createCheckout($order);
        } catch (\Throwable $e) {
            Log::error('Stripe checkout creation failed', ['order' => $order->id, 'error' => $e->getMessage()]);

            // Chỗ vẫn được giữ (job TTL sẽ nhả nếu bỏ); người mua có thể thử lại.
            return redirect()
                ->route('orders.show', $order)
                ->with('error', 'Không khởi tạo được thanh toán. Vui lòng thử lại.');
        }

        return redirect()->away($session->redirectUrl);
    }
}
