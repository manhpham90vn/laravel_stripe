<?php

namespace App\Payments;

/** Result of starting a hosted checkout — the URL to redirect the browser to. */
readonly class CheckoutSession
{
    public function __construct(
        public string $redirectUrl,
        public string $providerRef,   // PaymentIntent / Session id
    ) {}
}
