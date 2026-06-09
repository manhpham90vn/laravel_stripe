<?php

namespace Tests\Unit;

use App\Models\SaleBatch;
use Tests\TestCase;

/** Pure batch logic that the overselling/window guards lean on (spec §5.3, §6). */
class SaleBatchTest extends TestCase
{
    private function batch(array $attrs = []): SaleBatch
    {
        return new SaleBatch([
            'capacity' => 5, 'slots_taken' => 0,
            'status' => SaleBatch::STATUS_ON_SALE,
            'sale_starts_at' => now()->subDay(),
            'sale_ends_at' => now()->addDay(),
            ...$attrs,
        ]);
    }

    public function test_remaining_slots_never_goes_negative(): void
    {
        $this->assertSame(3, $this->batch(['capacity' => 5, 'slots_taken' => 2])->remainingSlots());
        $this->assertSame(0, $this->batch(['capacity' => 5, 'slots_taken' => 7])->remainingSlots());
    }

    public function test_is_sold_out_tracks_remaining(): void
    {
        $this->assertFalse($this->batch(['capacity' => 2, 'slots_taken' => 1])->isSoldOut());
        $this->assertTrue($this->batch(['capacity' => 2, 'slots_taken' => 2])->isSoldOut());
    }

    public function test_within_window_respects_start_and_end(): void
    {
        $this->assertTrue($this->batch()->isWithinWindow());
        $this->assertFalse($this->batch(['sale_starts_at' => now()->addDay()])->isWithinWindow());
        $this->assertFalse($this->batch(['sale_ends_at' => now()->subDay()])->isWithinWindow());
        // A null end date means an open-ended sale.
        $this->assertTrue($this->batch(['sale_ends_at' => null])->isWithinWindow());
    }

    public function test_is_purchasable_requires_on_sale_with_slots_in_window(): void
    {
        $this->assertTrue($this->batch()->isPurchasable());
        $this->assertFalse($this->batch(['status' => SaleBatch::STATUS_SCHEDULED])->isPurchasable());
        $this->assertFalse($this->batch(['status' => SaleBatch::STATUS_CLOSED])->isPurchasable());
        $this->assertFalse($this->batch(['capacity' => 1, 'slots_taken' => 1])->isPurchasable());
        $this->assertFalse($this->batch(['sale_ends_at' => now()->subHour()])->isPurchasable());
    }
}
