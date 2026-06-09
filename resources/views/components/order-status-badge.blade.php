@props([
    'status' => 'pending',   // order status — spec §5.1
])

@php
    $map = [
        'pending'    => ['amber', 'Chờ thanh toán'],
        'processing' => ['sky',   'Đang chờ tiền về'],
        'paid'       => ['green', 'Đã thanh toán'],
        'failed'     => ['rose',  'Thất bại'],
        'canceled'   => ['slate', 'Đã hủy'],
        'refunded'   => ['slate', 'Đã hoàn tiền'],
        'disputed'   => ['rose',  'Đang tranh chấp'],
    ];
    [$color, $label] = $map[$status] ?? ['slate', $status];
@endphp

<x-badge :color="$color" dot>{{ $label }}</x-badge>
