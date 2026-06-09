<?php

namespace Tests\Unit;

use App\Exceptions\CheckoutException;
use PHPUnit\Framework\TestCase;

/** The business-rule rejection codes/statuses must match spec §9. */
class CheckoutExceptionTest extends TestCase
{
    public function test_sold_out_is_409(): void
    {
        $e = CheckoutException::soldOut();
        $this->assertSame('SOLD_OUT', $e->errorCode);
        $this->assertSame(409, $e->httpStatus);
        $this->assertNotEmpty($e->getMessage());
    }

    public function test_already_purchased_is_409(): void
    {
        $e = CheckoutException::alreadyPurchased();
        $this->assertSame('ALREADY_PURCHASED', $e->errorCode);
        $this->assertSame(409, $e->httpStatus);
    }

    public function test_not_on_sale_is_422(): void
    {
        $e = CheckoutException::notOnSale();
        $this->assertSame('BATCH_NOT_ON_SALE', $e->errorCode);
        $this->assertSame(422, $e->httpStatus);
    }
}
