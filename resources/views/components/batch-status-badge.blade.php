@props([
    'status' => 'on_sale',   // scheduled | on_sale | sold_out | closed (spec §5.3)
])

@php
    // status => [badge color, label, dot]
    $map = [
        'scheduled' => ['sky',   'Sắp mở bán'],
        'on_sale'   => ['green', 'Đang mở bán'],
        'sold_out'  => ['rose',  'Đã hết slot'],
        'closed'    => ['slate', 'Đã đóng'],
    ];
    [$color, $label] = $map[$status] ?? ['slate', $status];
@endphp

<x-badge :color="$color" dot>{{ $label }}</x-badge>
