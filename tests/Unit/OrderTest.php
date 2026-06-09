<?php

namespace Tests\Unit;

use App\Models\Order;
use Tests\TestCase;

class OrderTest extends TestCase
{
    public function test_live_statuses_are_the_slot_holding_ones(): void
    {
        // BR-2: pending/processing/paid hold a live claim; terminal ones do not.
        $this->assertSame(
            [Order::STATUS_PENDING, Order::STATUS_PROCESSING, Order::STATUS_PAID],
            Order::LIVE_STATUSES,
        );
        foreach ([Order::STATUS_FAILED, Order::STATUS_CANCELED, Order::STATUS_REFUNDED] as $terminal) {
            $this->assertNotContains($terminal, Order::LIVE_STATUSES);
        }
    }

    public function test_method_accessor_labels_known_payment_methods(): void
    {
        $this->assertSame('Thẻ tín dụng', (new Order(['payment_method_type' => 'card']))->method);
        $this->assertSame('Konbini', (new Order(['payment_method_type' => 'konbini']))->method);
        // Unknown / missing falls back to a dash.
        $this->assertSame('—', (new Order(['payment_method_type' => 'wat']))->method);
        $this->assertSame('—', (new Order)->method);
    }

    public function test_due_at_formats_the_hold_deadline(): void
    {
        $order = new Order(['reserved_until' => '2026-06-09 13:45:00']);

        $this->assertSame('09/06 13:45', $order->dueAt);
        $this->assertNull((new Order)->dueAt);
    }
}
