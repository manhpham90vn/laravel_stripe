<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\SaleBatch;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds example orders for the buyer demo account — one per status so that
 * /orders/1..4 each preview a state — plus the matching active enrollments
 * shown on /my/courses. Each order sits on a distinct (user, batch) so the
 * BR-2 partial unique indexes are respected. Run after CourseCatalogSeeder.
 */
class DemoOrdersSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'test@example.com')->firstOrFail();

        $laravel = Course::where('slug', 'laravel-from-zero')->firstOrFail();
        $design = Course::where('slug', 'ui-design-systems')->firstOrFail();

        $b1 = $laravel->batches()->where('name', 'like', 'Đợt 1%')->firstOrFail();   // on_sale
        $b2 = $laravel->batches()->where('name', 'like', 'Đợt 2%')->firstOrFail();   // scheduled
        $b3 = $laravel->batches()->where('name', 'like', 'Đợt thử%')->firstOrFail(); // closed
        $b4 = $design->batches()->firstOrFail();                                     // on_sale

        // #1 paid (card) — on b1 → enrollment
        $o1 = $this->order($user, $b1, Order::STATUS_PAID, 'card', Reservation::STATUS_CONSUMED, paidAt: now()->subDays(19));
        // #2 pending (card) — on b2, active hold
        $this->order($user, $b2, Order::STATUS_PENDING, 'card', Reservation::STATUS_ACTIVE, reservedUntil: now()->addMinutes(15));
        // #3 processing (konbini) — on b3, long async hold
        $this->order($user, $b3, Order::STATUS_PROCESSING, 'konbini', Reservation::STATUS_ACTIVE, reservedUntil: now()->addDays(3));
        // #4 failed (card) — on b1 again (failed is not a "live" status, so allowed)
        $this->order($user, $b1, Order::STATUS_FAILED, 'card', Reservation::STATUS_RELEASED);
        // #5 paid (card) — on b4 → enrollment (second owned course)
        $o5 = $this->order($user, $b4, Order::STATUS_PAID, 'card', Reservation::STATUS_CONSUMED, paidAt: now()->subDays(6));

        $this->enroll($user, $laravel, $b1, $o1, now()->subDays(19));
        $this->enroll($user, $design, $b4, $o5, now()->subDays(6));
    }

    private function order(
        User $user,
        SaleBatch $batch,
        string $status,
        string $method,
        string $reservationStatus,
        ?\DateTimeInterface $paidAt = null,
        ?\DateTimeInterface $reservedUntil = null,
    ): Order {
        $reservation = Reservation::create([
            'sale_batch_id' => $batch->id,
            'user_id' => $user->id,
            'status' => $reservationStatus,
            'reserved_until' => $reservedUntil ?? now()->addMinutes(15),
        ]);

        return Order::create([
            'sale_batch_id' => $batch->id,
            'user_id' => $user->id,
            'reservation_id' => $reservation->id,
            'status' => $status,
            'amount' => $batch->price,
            'currency' => 'JPY',
            'payment_method_type' => $method,
            'reserved_until' => $reservedUntil,
            'paid_at' => $paidAt,
            'stripe_payment_intent_id' => 'pi_seed_' . $batch->id . '_' . $status,
            'stripe_charge_id' => $status === Order::STATUS_PAID ? 'ch_seed_' . $batch->id : null,
        ]);
    }

    private function enroll(User $user, Course $course, SaleBatch $batch, Order $order, \DateTimeInterface $at): void
    {
        Enrollment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'sale_batch_id' => $batch->id,
            'order_id' => $order->id,
            'status' => Enrollment::STATUS_ACTIVE,
            'granted_at' => $at,
        ]);
    }
}
