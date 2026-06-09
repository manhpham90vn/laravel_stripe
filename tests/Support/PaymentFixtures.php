<?php

namespace Tests\Support;

use App\Models\Course;
use App\Models\Order;
use App\Models\SaleBatch;
use App\Models\User;
use App\Services\ReservationService;
use App\Services\StripeEventProcessor;

/**
 * Shared builders for the payment feature tests so each file does not re-declare
 * the same batch/order/event scaffolding (mirrors the helpers that grew up in
 * CheckoutTest / PaymentLifecycleTest).
 */
trait PaymentFixtures
{
    protected function onSaleBatch(int $capacity = 5, int $taken = 0, array $overrides = []): SaleBatch
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
            ...$overrides,
        ]);
    }

    protected function reserve(SaleBatch $batch, ?User $user = null): Order
    {
        return app(ReservationService::class)->reserve($batch, $user ?? User::factory()->create());
    }

    /** A reservation + a card payment_intent.succeeded already applied → paid + enrollment. */
    protected function paidOrder(?SaleBatch $batch = null, ?User $user = null): Order
    {
        $order = $this->reserve($batch ?? $this->onSaleBatch(), $user);
        app(StripeEventProcessor::class)->process($this->succeededEvent($order));

        return $order->fresh();
    }

    protected function succeededEvent(Order $order, string $id = 'evt_succeeded_'): array
    {
        return [
            'id' => $id === 'evt_succeeded_' ? $id.$order->id : $id,
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_'.$order->id, 'payment_method_type' => 'card',
                'latest_charge' => 'ch_'.$order->id, 'metadata' => ['order_id' => (string) $order->id],
            ]],
        ];
    }
}
