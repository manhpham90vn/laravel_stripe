<?php

namespace App\Services;

use App\Exceptions\CheckoutException;
use App\Exceptions\GatewayException;
use App\Models\Order;
use App\Models\SaleBatch;
use App\Models\User;
use App\Payments\PaymentGateway;
use Illuminate\Support\Facades\Log;

/**
 * Điều phối toàn bộ luồng checkout: giữ chỗ → tạo Stripe Session → trả URL.
 * Mọi lỗi nghiệp vụ ném CheckoutException (với existingOrder nếu là ALREADY_PURCHASED),
 * lỗi kỹ thuật gateway ném GatewayException (có $order để controller redirect đúng chỗ).
 */
class CheckoutService
{
    public function __construct(
        private ReservationService $reservations,
        private PaymentGateway $gateway,
    ) {}

    /**
     * Giữ chỗ và tạo Stripe Checkout Session cho người mua mới.
     * Trả về URL redirect sang trang thanh toán của Stripe.
     *
     * @throws CheckoutException  lỗi nghiệp vụ (hết chỗ / đã mua / chưa mở bán).
     *                            Nếu ALREADY_PURCHASED và tìm được đơn live thì $e->existingOrder != null.
     * @throws GatewayException   lỗi tạo session Stripe ($e->order = đơn vừa tạo, để retry sau).
     */
    public function initiate(SaleBatch $batch, User $user): string
    {
        try {
            $order = $this->reservations->reserve($batch, $user);
        } catch (CheckoutException $e) {
            // ReservationService chỉ biết "đã mua rồi" nhưng không tìm đơn live (không phải việc của nó).
            // Service này tìm đơn live và làm giàu exception — controller chỉ cần redirect, không cần query.
            if ($e->errorCode === 'ALREADY_PURCHASED') {
                $existing = Order::liveFor($batch, $user);
                if ($existing) {
                    throw CheckoutException::alreadyPurchasedWithOrder($existing);
                }
            }
            throw $e;
        }

        return $this->openSession($order);
    }

    /**
     * Tạo lại Stripe Checkout Session cho đơn đang pending/processing (retry).
     * Chỗ đã được giữ rồi — không tạo reservation mới.
     *
     * @throws GatewayException  lỗi tạo session Stripe.
     */
    public function retry(Order $order): string
    {
        $order->loadMissing('saleBatch');

        return $this->openSession($order);
    }

    private function openSession(Order $order): string
    {
        try {
            return $this->gateway->createCheckout($order)->redirectUrl;
        } catch (\Throwable $e) {
            Log::error('Stripe checkout creation failed', [
                'order' => $order->id,
                'error' => $e->getMessage(),
            ]);

            // Wrap thành GatewayException mang $order để controller biết redirect về đơn nào.
            // Chỗ vẫn đang được giữ — người mua có thể retry qua trang orders.show.
            throw new GatewayException($order, previous: $e);
        }
    }
}
