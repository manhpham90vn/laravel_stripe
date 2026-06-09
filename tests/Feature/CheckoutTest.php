<?php

namespace Tests\Feature;

use App\Exceptions\CheckoutException;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\SaleBatch;
use App\Models\User;
use App\Payments\CheckoutSession;
use App\Payments\PaymentGateway;
use App\Services\ReservationService;
use App\Services\StripeEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function onSaleBatch(int $capacity = 5, int $taken = 0): SaleBatch
    {
        $course = Course::create([
            'title' => 'Test Course', 'slug' => 'test-course-'.uniqid(),
            'summary' => 's', 'description' => 'd', 'status' => 'published',
        ]);

        return $course->batches()->create([
            'name' => 'Đợt test', 'capacity' => $capacity, 'slots_taken' => $taken,
            'price' => 10000, 'currency' => 'JPY',
            'sale_starts_at' => now()->subDay(), 'sale_ends_at' => now()->addDay(),
            'status' => SaleBatch::STATUS_ON_SALE,
        ]);
    }

    private function succeededEvent(Order $order, string $id = 'evt_test_1'): array
    {
        return [
            'id' => $id,
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_test', 'payment_method_type' => 'card',
                'latest_charge' => 'ch_test', 'metadata' => ['order_id' => (string) $order->id],
            ]],
        ];
    }

    /** Full happy path: checkout reserves + redirects to Stripe; the webhook
     *  (source of truth) grants the enrollment (AC-4, BR-4, D5). */
    public function test_card_checkout_grants_enrollment(): void
    {
        $user = User::factory()->create();
        $batch = $this->onSaleBatch();

        // Stripe Checkout is mocked — we assert our side: reserve + redirect.
        $this->mock(PaymentGateway::class)
            ->shouldReceive('createCheckout')
            ->once()
            ->andReturn(new CheckoutSession('https://checkout.stripe.test/abc', 'pi_test'));

        $this->actingAs($user)
            ->post(route('checkout.store', $batch->id))
            ->assertRedirect('https://checkout.stripe.test/abc');

        $order = Order::firstOrFail();
        $this->assertEquals(Order::STATUS_PENDING, $order->status);
        $this->assertEquals(1, $batch->fresh()->slots_taken);

        // Stripe later fires payment_intent.succeeded → enrollment granted.
        app(StripeEventProcessor::class)->process($this->succeededEvent($order));

        $this->assertEquals(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertDatabaseHas('enrollments', [
            'order_id' => $order->id, 'status' => Enrollment::STATUS_ACTIVE,
        ]);
    }

    /** Never sell past capacity (AC-1, NFR-1). */
    public function test_overselling_is_prevented(): void
    {
        $batch = $this->onSaleBatch(capacity: 1);
        $service = app(ReservationService::class);

        $service->reserve($batch, User::factory()->create());

        $this->assertEquals(1, $batch->fresh()->slots_taken);
        $this->assertEquals(SaleBatch::STATUS_SOLD_OUT, $batch->fresh()->status);

        $this->expectException(CheckoutException::class);
        $service->reserve($batch->fresh(), User::factory()->create());
    }

    /**
     * Capacity is a hard ceiling: with M distinct buyers chasing N slots, exactly
     * N reservations succeed and slots_taken settles at N — never above, never
     * negative (AC-1, NFR-1, BR-1).
     *
     * Note: this drives the guard deterministically in-process. True parallel
     * contention relies on `lockForUpdate` on the sale_batches row, which is a
     * no-op on SQLite (:memory:) — exercise that against MySQL/Postgres in CI.
     */
    public function test_capacity_is_the_hard_ceiling_across_many_buyers(): void
    {
        $batch = $this->onSaleBatch(capacity: 3);
        $service = app(ReservationService::class);

        $granted = 0;
        $rejected = 0;
        foreach (range(1, 7) as $i) {
            try {
                $service->reserve($batch->fresh(), User::factory()->create());
                $granted++;
            } catch (CheckoutException $e) {
                // The buyer who hits the last slot gets SOLD_OUT; once the batch
                // flips to sold_out the rest are turned away as BATCH_NOT_ON_SALE.
                $this->assertContains($e->errorCode, ['SOLD_OUT', 'BATCH_NOT_ON_SALE']);
                $rejected++;
            }
        }

        $this->assertEquals(3, $granted);
        $this->assertEquals(4, $rejected);
        $this->assertEquals(3, $batch->fresh()->slots_taken);
        $this->assertEquals(0, $batch->fresh()->remainingSlots());
        $this->assertEquals(SaleBatch::STATUS_SOLD_OUT, $batch->fresh()->status);
    }

    /** One slot per user per batch (AC-2, BR-2). */
    public function test_user_cannot_buy_twice(): void
    {
        $user = User::factory()->create();
        $batch = $this->onSaleBatch();
        $service = app(ReservationService::class);

        $service->reserve($batch, $user);

        $this->expectException(CheckoutException::class);
        $service->reserve($batch->fresh(), $user);
    }

    /** Replaying the same webhook is a no-op (AC-3, BR-5/NFR-2). */
    public function test_webhook_is_idempotent(): void
    {
        $user = User::factory()->create();
        $batch = $this->onSaleBatch();
        $order = app(ReservationService::class)->reserve($batch, $user);

        $processor = app(StripeEventProcessor::class);
        $event = $this->succeededEvent($order);

        $processor->process($event);
        $processor->process($event);
        $processor->process($event);

        $this->assertEquals(1, Enrollment::where('order_id', $order->id)->count());
        $this->assertEquals(1, \DB::table('processed_stripe_events')->count());
        $this->assertEquals(Order::STATUS_PAID, $order->fresh()->status);
    }

    /** Checkout is refused outside the sale window (AC-8). */
    public function test_checkout_rejected_when_not_on_sale(): void
    {
        $batch = $this->onSaleBatch();
        $batch->update(['status' => SaleBatch::STATUS_SCHEDULED]);

        $this->actingAs(User::factory()->create())
            ->post(route('checkout.store', $batch->id))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('orders', 0);
    }

    /** Refund revokes access (AC-7, BR-7). Admin triggers Stripe; the
     *  charge.refunded webhook applies the state change. */
    public function test_refund_revokes_enrollment(): void
    {
        $user = User::factory()->create();
        $batch = $this->onSaleBatch();
        $order = app(ReservationService::class)->reserve($batch, $user);
        app(StripeEventProcessor::class)->process($this->succeededEvent($order));

        // Admin action calls Stripe's refund API (mocked here).
        $this->mock(PaymentGateway::class)->shouldReceive('refund')->once();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.orders.refund', $order))->assertRedirect();

        // Stripe then fires charge.refunded.
        app(StripeEventProcessor::class)->process([
            'id' => 'evt_refund_1',
            'type' => 'charge.refunded',
            'data' => ['object' => ['id' => 'ch_test', 'metadata' => ['order_id' => (string) $order->id]]],
        ]);

        $this->assertEquals(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertDatabaseHas('enrollments', [
            'order_id' => $order->id, 'status' => Enrollment::STATUS_REVOKED,
        ]);
    }
}
