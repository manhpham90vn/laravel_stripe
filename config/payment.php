<?php

return [

    'currency' => 'JPY',

    /*
    | Checkout payment methods. Default 'card' so it works with any test
    | account. Add others (e.g. 'card,konbini') only after activating them in
    | the Stripe Dashboard. Set STRIPE_PAYMENT_METHODS empty to let Stripe use
    | whatever is enabled in the Dashboard for the currency.
    */
    'payment_methods' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('STRIPE_PAYMENT_METHODS', 'card')),
    ))),

    /*
    | Slot-hold TTLs (spec §6, BR-8). Card holds are short; async methods
    | (Konbini/Pay-easy) are held until the Stripe voucher expires.
    */
    'ttl' => [
        'card_minutes' => env('CHECKOUT_TTL_CARD_MINUTES', 15),
        'async_days' => env('CHECKOUT_TTL_ASYNC_DAYS', 3),
    ],

    /*
    | Reconciliation (jobs_and_scheduler §5). Only orders older than this are
    | checked against Stripe, so in-flight checkouts aren't touched.
    */
    'reconcile' => [
        'stuck_after_minutes' => env('RECONCILE_STUCK_AFTER_MINUTES', 30),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
];
