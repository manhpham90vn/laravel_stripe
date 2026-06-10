<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Order;
use Illuminate\Database\QueryException;

/**
 * Cấp / thu hồi quyền học (enrollment) cho người mua.
 *
 * Cấp quyền là idempotent (an toàn khi gọi lại do webhook retry — NFR-2, BR-4):
 * hai chốt chặn ở tầng DB là `unique(order_id)` và partial unique index theo
 * (user, course) đang active. Nhờ vậy dù webhook `payment_intent.succeeded` về
 * trùng nhiều lần thì mỗi đơn cũng chỉ sinh đúng 1 enrollment.
 */
class EnrollmentService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Cấp quyền học khi đơn chuyển sang `paid`. Trả về enrollment (mới tạo hoặc
     * đã có sẵn). Có 2 lớp chống cấp trùng:
     *  1. Kiểm tra trước theo `order_id` — đường thường, rẻ.
     *  2. Bắt lỗi unique violation — phòng khi hai webhook chạy SONG SONG cùng
     *     lọt qua bước (1) rồi mới đua nhau insert; DB từ chối cái thứ hai.
     */
    public function grant(Order $order): ?Enrollment
    {
        // Đơn này đã được cấp quyền chưa? (an toàn khi gọi lại)
        if ($existing = Enrollment::where('order_id', $order->id)->first()) {
            return $existing;
        }

        try {
            $enrollment = Enrollment::create([
                'user_id' => $order->user_id,
                'course_id' => $order->saleBatch->course_id,
                'sale_batch_id' => $order->sale_batch_id,
                'order_id' => $order->id,
                'status' => Enrollment::STATUS_ACTIVE,
                'granted_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Đụng unique index (cấp song song / user đã có enrollment active cho
            // course này). Coi như đã cấp rồi → trả về bản ghi hiện có.
            return Enrollment::where('order_id', $order->id)->first();
        }

        $this->audit->record($enrollment, null, Enrollment::STATUS_ACTIVE, 'webhook', null, [
            'order_id' => $order->id,
        ]);

        return $enrollment;
    }

    /**
     * Thu hồi quyền học khi đơn bị refund / thua dispute (BR-7). Idempotent:
     * chỉ thu hồi enrollment đang `active`; gọi lại trên đơn đã revoke là no-op.
     */
    public function revoke(Order $order): void
    {
        $enrollment = Enrollment::where('order_id', $order->id)
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->first();

        if (! $enrollment) {
            return; // không có enrollment active → đã thu hồi hoặc chưa từng cấp
        }

        $enrollment->update(['status' => Enrollment::STATUS_REVOKED]);
        $this->audit->record($enrollment, Enrollment::STATUS_ACTIVE, Enrollment::STATUS_REVOKED, 'webhook', null, [
            'order_id' => $order->id,
        ]);
    }
}
