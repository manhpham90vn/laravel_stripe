<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Order;
use Illuminate\Database\QueryException;

/**
 * Grants/revokes course access. Granting is idempotent: unique(order_id) plus
 * the active (user, course) partial index are the last line of defence against
 * double-granting on webhook retries (NFR-2, BR-4).
 */
class EnrollmentService
{
    public function __construct(private AuditLogger $audit) {}

    public function grant(Order $order): ?Enrollment
    {
        // Already granted for this order? (retry-safe)
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
            // Unique violation (concurrent grant / already enrolled in course).
            return Enrollment::where('order_id', $order->id)->first();
        }

        $this->audit->record($enrollment, null, Enrollment::STATUS_ACTIVE, 'webhook', null, [
            'order_id' => $order->id,
        ]);

        return $enrollment;
    }

    public function revoke(Order $order): void
    {
        $enrollment = Enrollment::where('order_id', $order->id)
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->first();

        if (! $enrollment) {
            return;
        }

        $enrollment->update(['status' => Enrollment::STATUS_REVOKED]);
        $this->audit->record($enrollment, Enrollment::STATUS_ACTIVE, Enrollment::STATUS_REVOKED, 'webhook', null, [
            'order_id' => $order->id,
        ]);
    }
}
