<?php

namespace Tests\Unit;

use App\Models\Reservation;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    public function test_is_expired_compares_against_reserved_until(): void
    {
        $this->assertTrue((new Reservation(['reserved_until' => now()->subMinute()]))->isExpired());
        $this->assertFalse((new Reservation(['reserved_until' => now()->addMinute()]))->isExpired());
    }

    public function test_is_expired_accepts_an_explicit_clock(): void
    {
        $r = new Reservation(['reserved_until' => now()]);

        $this->assertTrue($r->isExpired(now()->addHour()));
        $this->assertFalse($r->isExpired(now()->subHour()));
    }
}
