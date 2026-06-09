@props([
    'amount' => 0,          // integer yen (JPY is zero-decimal — db_design §4)
    'currency' => 'JPY',
])

{{-- Renders JPY directly as whole yen, e.g. ¥12,000 --}}
<span {{ $attributes }}>{{ $currency === 'JPY' ? '¥' : '' }}{{ number_format((int) $amount) }}</span>
