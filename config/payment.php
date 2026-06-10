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
        // Mốc giữ chỗ cho thẻ (~15'). Cũng là TTL mặc định lúc vừa reserve khi
        // CHƯA biết phương thức (Checkout hosted chọn method ở trang Stripe).
        // Với konbini, đây là "hạn lấy voucher", không phải hạn trả tiền.
        'card_minutes' => env('CHECKOUT_TTL_CARD_MINUTES', 15),

        // Mốc giữ chỗ cho phương thức bất đồng bộ (Konbini/Pay-easy): giữ tới khi
        // voucher Stripe hết hạn — vài NGÀY. Áp khi đơn chuyển sang `processing`
        // (extendForAsync). NÊN trùng với `expires_after_days` của konbini bên
        // StripeGateway (đang kẹp [1,60]) để hold và voucher khớp nhau.
        'async_days' => env('CHECKOUT_TTL_ASYNC_DAYS', 3),

        /*
        | Stripe Checkout Session lifetime (expires_at). Our slot-hold is 15 min
        | but Stripe enforces a 30-min minimum on expires_at, so we bound the
        | session to the floor to shrink the late-payment window (§8.2a) from the
        | 24h default. The residual 15→30 min gap is covered by reclaim-or-refund.
        */
        'session_minutes' => env('CHECKOUT_TTL_SESSION_MINUTES', 30),
    ],

    /*
    | Reconciliation (jobs_and_scheduler §5). Only orders older than this are
    | checked against Stripe, so in-flight checkouts aren't touched.
    */
    'reconcile' => [
        'stuck_after_minutes' => env('RECONCILE_STUCK_AFTER_MINUTES', 30),
    ],

    /*
    | processed_stripe_events housekeeping (payment_solutions §2.8 review #8).
    | Stripe stops retrying after a few days, so the idempotency markers can be
    | pruned once they are older than this without weakening dedup.
    */
    'processed_events' => [
        'retention_days' => env('PROCESSED_EVENTS_RETENTION_DAYS', 60),
    ],

    /*
    | Checkout abuse guard (payment_solutions §1.2 review #7). Reserving a slot
    | on "Mua" lets a user hold inventory, so rate-limit checkout attempts per
    | authenticated user (falls back to IP for safety).
    */
    'rate_limit' => [
        'checkout_per_minute' => env('CHECKOUT_RATE_LIMIT_PER_MINUTE', 10),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
];
