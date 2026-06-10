<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ProcessedStripeEvent;
use App\Models\Reservation;
use App\Payments\PaymentGateway;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bộ xử lý áp KẾT QUẢ THANH TOÁN lên đơn hàng — trái tim của luồng tiền.
 *
 * Mọi method ở đây đều IDEMPOTENT và chạy trong TRANSACTION → an toàn khi bị gọi
 * lại do webhook retry hoặc do job reconcile chạy trùng (BR-5 / NFR-2).
 *
 * Đây là CON ĐƯỜNG DUY NHẤT để một đơn rời khỏi `pending` (spec §5.1): controller
 * chỉ tạo đơn `pending` và hủy chủ động; còn lại paid/processing/failed/refunded/
 * disputed đều do các method dưới đây thực hiện, hầu hết kích hoạt từ webhook
 * (nguồn sự thật — D5).
 */
class PaymentEventHandler
{
    /**
     * Bảng chuyển trạng thái HỢP LỆ duy nhất (spec §5.1). Bất kỳ bước nhảy nào
     * không có trong bảng đều bị transition() từ chối — nên các bước vô lý như
     * `canceled → paid` tùy tiện hay refund một đơn `pending` bị chặn TẬN GỐC,
     * không phải dựa vào guard rải rác ở từng method.
     *
     * Đọc: từ-khóa (status hiện tại) → [các status được phép nhảy tới].
     * Lưu ý 2 cạnh "phục hồi" đặc biệt: failed/canceled → paid|refunded chỉ dùng
     * cho reclaim-or-refund khi `succeeded` về muộn (§8.2a), không phải nhảy tùy ý.
     */
    private const ALLOWED = [
        Order::STATUS_PENDING => [Order::STATUS_PROCESSING, Order::STATUS_PAID, Order::STATUS_FAILED, Order::STATUS_CANCELED],
        Order::STATUS_PROCESSING => [Order::STATUS_PAID, Order::STATUS_FAILED, Order::STATUS_CANCELED],
        Order::STATUS_PAID => [Order::STATUS_REFUNDED, Order::STATUS_DISPUTED],
        Order::STATUS_DISPUTED => [Order::STATUS_PAID, Order::STATUS_REFUNDED],
        // failed/canceled are terminal for the normal flow; the only way out is
        // the reclaim-or-refund recovery in markPaid() when a real `succeeded`
        // lands late (spec §8.2a) — reclaim → paid, or auto-refund → refunded.
        Order::STATUS_FAILED => [Order::STATUS_PAID, Order::STATUS_REFUNDED],
        Order::STATUS_CANCELED => [Order::STATUS_PAID, Order::STATUS_REFUNDED],
        Order::STATUS_REFUNDED => [],   // terminal
    ];

    public function __construct(
        private ReservationService $reservations,
        private EnrollmentService $enrollments,
        private AuditLogger $audit,
        private PaymentGateway $gateway,
    ) {}

    /**
     * Chạy $work trong MỘT transaction, và ghi luôn "dấu đã xử lý event" (bảng
     * processed_stripe_events) NGAY ĐẦU transaction đó (payment_solutions §2.8).
     *
     * Mục đích: dedup và side-effect commit/rollback NHƯ MỘT KHỐI — không bao giờ
     * rơi vào cảnh "đã đánh dấu xử lý nhưng chưa làm gì" (hoặc ngược lại). Nếu job
     * lỗi và rollback thì dấu cũng mất theo → Stripe retry lại an toàn.
     *
     * Phân biệt 2 loại lỗi QueryException:
     *  - Trùng dấu (unique violation trên event_id) = event giao trùng → NUỐT êm
     *    (cái đầu tiên đã xử lý rồi).
     *  - Deadlock / mất kết nối → NÉM LẠI để queue retry.
     *
     * $eventId = null khi NGƯỜI GỌI KHÔNG PHẢI webhook (job reconcile / TTL): khi
     * đó không có dấu để ghi — $work chỉ chạy trong transaction của nó, dựa vào
     * tính idempotent theo từng đơn đã cài sẵn trong mỗi handler.
     */
    private function applyEvent(?string $eventId, ?string $eventType, Closure $work): void
    {
        try {
            DB::transaction(function () use ($eventId, $eventType, $work) {
                if ($eventId !== null) {
                    ProcessedStripeEvent::create([
                        'event_id' => $eventId,
                        'type' => $eventType ?? 'unknown',
                        'processed_at' => now(),
                    ]);
                }

                $work();
            });
        } catch (QueryException $e) {
            if ($eventId !== null && $this->isUniqueViolation($e)) {
                return; // duplicate delivery — the first one already applied it
            }

            throw $e;
        }
    }

    /** Detect the marker's primary-key clash across sqlite / mysql / postgres. */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;

        return $sqlState === '23505'                 // Postgres unique_violation
            || ($sqlState === '23000' && $driverCode === 1062) // MySQL ER_DUP_ENTRY
            || ($driverCode === 19 || $driverCode === 2067)    // SQLite CONSTRAINT / CONSTRAINT_UNIQUE
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    /**
     * Chuyển đơn sang trạng thái $to NẾU bước nhảy hợp lệ (theo bảng ALLOWED),
     * đồng thời lưu $attributes và ghi audit log trong cùng một lần.
     *
     * Trả về true/false cho biết bước nhảy CÓ xảy ra không, để caller quyết định
     * chạy side-effect (cấp enrollment, nhả slot…) hay không.
     *
     * Bước nhảy phạm luật → ghi log cảnh báo rồi NUỐT (không ném) để webhook vẫn
     * trả 200 cho Stripe (BR-5). Riêng nhảy "cùng status" là no-op idempotent đã
     * lường trước → im lặng, không log.
     */
    private function transition(
        Order $order,
        string $to,
        array $attributes = [],
        string $actor = 'system',
        ?int $actorId = null,
        array $meta = [],
    ): bool {
        $from = $order->status;

        if (! in_array($to, self::ALLOWED[$from] ?? [], true)) {
            if ($to !== $from) {
                Log::warning('Blocked illegal order transition', [
                    'order_id' => $order->id, 'from' => $from, 'to' => $to,
                ]);
            }

            return false;
        }

        $order->update(['status' => $to] + $attributes);
        $this->audit->record($order, $from, $to, $actor, $actorId, $meta);

        return true;
    }

    /**
     * payment_intent.succeeded → đơn `paid` + cấp enrollment (spec §8.2).
     *
     * Có HAI ĐƯỜNG tùy trạng thái đơn lúc tiền về:
     *
     *  • ĐƯỜNG THƯỜNG (đơn còn pending/processing): chỗ vẫn đang được giữ → chỉ
     *    cần consume reservation + cấp quyền học.
     *
     *  • ĐƯỜNG PHỤC HỒI (đơn đã canceled/failed — chỗ đã nhả): tiền về MUỘN
     *    (§8.2a reclaim-or-refund). Thử GIÀNH LẠI chỗ: còn chỗ → lên paid + cấp
     *    quyền; hết chỗ → REFUND khoản vừa thu. Đây là 2 cạnh duy nhất kéo đơn
     *    chết quay lại sống (xem bảng ALLOWED).
     *
     * Lệnh refund Stripe được HOÃN tới SAU khi transaction commit (biến
     * $refundOrderId) để không giữ row lock trong lúc gọi mạng.
     */
    public function markPaid(Order $order, array $meta = []): void
    {
        $refundOrderId = null;

        $this->applyEvent($meta['event_id'] ?? null, $meta['event_type'] ?? null, function () use ($order, $meta, &$refundOrderId) {
            // Khóa dòng đơn để serialize với các webhook/job chạy song song.
            $order = Order::whereKey($order->id)->lockForUpdate()->with('reservation')->firstOrFail();

            if ($order->status === Order::STATUS_PAID) {
                return; // idempotent — đơn đã paid rồi, không làm gì thêm
            }

            // BR-11 / §2.9: TUYỆT ĐỐI không cấp quyền nếu số tiền Stripe báo về
            // khác snapshot `orders.amount` (server-side). Giữ nguyên trạng thái
            // + log cảnh báo cho ops xử lý tay, thà chặn còn hơn cấp nhầm.
            if (isset($meta['amount']) && (int) $meta['amount'] !== (int) $order->amount) {
                Log::warning('Amount mismatch on succeeded payment — not granting', [
                    'order_id' => $order->id, 'db' => $order->amount, 'stripe' => $meta['amount'],
                ]);

                return;
            }

            $paidAttributes = [
                'paid_at' => now(),
                'payment_method_type' => $meta['payment_method_type'] ?? $order->payment_method_type,
                'stripe_charge_id' => $meta['charge_id'] ?? $order->stripe_charge_id,
                // Replace the Checkout session placeholder with the real PI id so
                // refunds / disputes / reconciliation resolve by PaymentIntent.
                'stripe_payment_intent_id' => $meta['payment_intent_id'] ?? $order->stripe_payment_intent_id,
            ];

            // ĐƯỜNG THƯỜNG — chỗ vẫn đang giữ: consume reservation + cấp quyền.
            if (in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_PROCESSING], true)) {
                if (! $this->transition($order, Order::STATUS_PAID, $paidAttributes, 'webhook', null, $meta)) {
                    return;
                }

                if ($order->reservation) {
                    $this->reservations->consume($order->reservation);
                }

                $order->loadMissing('saleBatch');
                $this->enrollments->grant($order);

                return;
            }

            // ĐƯỜNG PHỤC HỒI (§8.2a) — chỗ đã nhả: thử giành lại, không thì refund.
            if (in_array($order->status, [Order::STATUS_CANCELED, Order::STATUS_FAILED], true)) {
                if ($this->reservations->reclaim($order)) {
                    if ($this->transition($order, Order::STATUS_PAID, $paidAttributes, 'webhook', null, $meta + ['recovery' => 'reclaimed'])) {
                        $order->loadMissing('saleBatch');
                        $this->enrollments->grant($order);
                    }

                    return;
                }

                // Hết chỗ — lưu lại charge ref rồi refund SAU commit; webhook
                // charge.refunded sẽ đưa đơn về `refunded` sau đó.
                $order->update([
                    'stripe_charge_id' => $meta['charge_id'] ?? $order->stripe_charge_id,
                    'stripe_payment_intent_id' => $meta['payment_intent_id'] ?? $order->stripe_payment_intent_id,
                ]);

                if ($order->stripe_charge_id) {
                    $refundOrderId = $order->id;
                } else {
                    Log::error('Late payment on dead order but no charge to refund', [
                        'order_id' => $order->id,
                    ]);
                }
            }
        });

        if ($refundOrderId !== null) {
            Log::warning('Late payment on a sold-out dead order — auto-refunding', [
                'order_id' => $refundOrderId,
            ]);
            $this->gateway->refund(Order::findOrFail($refundOrderId));
        }
    }

    /**
     * checkout.session.completed → chốt lại id PaymentIntent THẬT, và nếu phiên
     * kết thúc mà CHƯA trả tiền (tức là đã đặt voucher async), đẩy đơn sang
     * `processing` để giữ chỗ tới hạn voucher (spec §8.2).
     *
     * Trường hợp trả NGAY (card) đến qua payment_intent.succeeded, không xử lý ở
     * đây — đây chỉ lo ca async (payment_status != 'paid').
     */
    public function onCheckoutCompleted(Order $order, array $meta = []): void
    {
        $this->applyEvent($meta['event_id'] ?? null, $meta['event_type'] ?? null, function () use ($order, $meta) {
            if ($pi = $meta['payment_intent_id'] ?? null) {
                Order::whereKey($order->id)
                    ->where(fn ($q) => $q->whereNull('stripe_payment_intent_id')->orWhere('stripe_payment_intent_id', '!=', $pi))
                    ->update(['stripe_payment_intent_id' => $pi]);
                $order->refresh();
            }

            // Synchronous success arrives via payment_intent.succeeded; only act
            // here for the async case (voucher placed, payment_status unpaid).
            // The inner call passes no event_id so it nests without a 2nd marker.
            if (($meta['payment_status'] ?? 'paid') !== 'paid' && $order->status === Order::STATUS_PENDING) {
                $this->markProcessing($order, ['payment_method_type' => $meta['payment_method_type'] ?? 'konbini']);
            }
        });
    }

    /**
     * payment_intent.processing → voucher async đã đặt, đơn sang `processing`
     * và giữ chỗ tới hạn voucher (spec §7.2). Gọi extendForAsync() để đẩy
     * `reserved_until` từ 15' ra vài ngày — xem giải thích timeline ở hàm đó.
     */
    public function markProcessing(Order $order, array $meta = []): void
    {
        $this->applyEvent($meta['event_id'] ?? null, $meta['event_type'] ?? null, function () use ($order, $meta) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('reservation')->firstOrFail();

            $ok = $this->transition($order, Order::STATUS_PROCESSING, [
                'payment_method_type' => $meta['payment_method_type'] ?? 'konbini',
            ], 'webhook', null, $meta);

            if (! $ok) {
                return;
            }

            $this->reservations->extendForAsync($order);
        });
    }

    /**
     * payment_intent.payment_failed / .canceled → đơn `failed` + nhả chỗ.
     * Lưu ý: paid → failed bị bảng ALLOWED chặn (thất bại đến muộn sau khi đã
     * thành công không được lật ngược); replay failed/canceled cũng là no-op.
     */
    public function markFailed(Order $order, array $meta = []): void
    {
        $this->applyEvent($meta['event_id'] ?? null, $meta['event_type'] ?? null, function () use ($order, $meta) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('reservation')->firstOrFail();

            // paid → failed is rejected by the table (a late failure after
            // success), as are failed/canceled replays.
            if (! $this->transition($order, Order::STATUS_FAILED, [], 'webhook', null, $meta)) {
                return;
            }

            if ($order->reservation) {
                $this->reservations->release($order->reservation, Reservation::STATUS_RELEASED);
            }
        });
    }

    /** Người mua tự bấm hủy đơn đang chờ (spec §9). Actor = 'user'. */
    public function cancel(Order $order, int $actorId): void
    {
        $this->cancelOrder($order, 'user', $actorId);
    }

    /** Hết TTL — hệ thống (job) tự hủy đơn và nhả chỗ (spec §7.3). Actor = 'system'. */
    public function expire(Order $order): void
    {
        $this->cancelOrder($order, 'system', null);
    }

    private function cancelOrder(Order $order, string $actor, ?int $actorId): void
    {
        $canceled = DB::transaction(function () use ($order, $actor, $actorId) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('reservation')->firstOrFail();

            // Only pending/processing orders may be canceled (spec §5.1).
            if (! $this->transition($order, Order::STATUS_CANCELED, [], $actor, $actorId)) {
                return false;
            }

            if ($order->reservation) {
                $this->reservations->release($order->reservation, Reservation::STATUS_RELEASED);
            }

            return true;
        });

        // After the slot is freed, close the Checkout Session so the buyer can't
        // pay a seat they no longer hold (§8.2a). Deferred past the commit so we
        // never hold the row lock across the Stripe call; if a payment slipped in
        // first, reclaim-or-refund in markPaid() is the backstop.
        if ($canceled) {
            $this->gateway->expireCheckout($order);
        }
    }

    /**
     * charge.refunded → đơn `refunded` + thu hồi enrollment (BR-7).
     * CHÍNH SÁCH: KHÔNG tự nhả chỗ cho người khác (tránh dao động số liệu/đối
     * soát) — admin tự quyết mở thêm chỗ nếu muốn.
     */
    public function markRefunded(Order $order, array $meta = []): void
    {
        $this->applyEvent($meta['event_id'] ?? null, $meta['event_type'] ?? null, function () use ($order, $meta) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $this->transition($order, Order::STATUS_REFUNDED, [], 'webhook', null, $meta)) {
                return;
            }

            $this->enrollments->revoke($order);
        });
    }

    /** charge.dispute.created → đơn `disputed`, tiền bị Stripe giữ, chờ kết quả (§5.1, §8.2). */
    public function openDispute(Order $order, array $meta = []): void
    {
        $this->applyEvent($meta['event_id'] ?? null, $meta['event_type'] ?? null, function () use ($order, $meta) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $this->transition($order, Order::STATUS_DISPUTED, [], 'webhook', null, $meta);
        });
    }

    /**
     * charge.dispute.closed → xử theo kết quả (spec §5.1):
     *   won / warning_closed → bên bán giữ được tiền → quay lại `paid` (giữ quyền học)
     *   lost (hoặc khác)     → khách thắng → coi như `refunded`, thu hồi quyền học
     */
    public function closeDispute(Order $order, array $meta = []): void
    {
        $this->applyEvent($meta['event_id'] ?? null, $meta['event_type'] ?? null, function () use ($order, $meta) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $outcome = $meta['dispute_status'] ?? null;

            if (in_array($outcome, ['won', 'warning_closed'], true)) {
                // Only a disputed order can swing back to paid; the table drops
                // anything else (e.g. an already-settled refund).
                $this->transition($order, Order::STATUS_PAID, [], 'webhook', null, $meta);

                return;
            }

            if (! $this->transition($order, Order::STATUS_REFUNDED, [], 'webhook', null, $meta)) {
                return;
            }

            $this->enrollments->revoke($order);
        });
    }
}
