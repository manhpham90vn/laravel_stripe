@props([
    'as' => 'div',
    'padded' => true,
])

<{{ $as }} {{ $attributes->class([
    'rounded-2xl border border-slate-200 bg-white shadow-sm',
    'p-6' => $padded,
]) }}>
    {{ $slot }}
</{{ $as }}>
