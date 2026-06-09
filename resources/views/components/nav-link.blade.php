@props([
    'href' => '#',
    'active' => null,
])

@php
    // Auto-detect active state from the current path when not explicitly set.
    $isActive = $active ?? request()->fullUrlIs($href . '*') ?? false;
@endphp

<a href="{{ $href }}"
   @if ($isActive) aria-current="page" @endif
   {{ $attributes->class([
        'rounded-lg px-3 py-2 text-sm font-medium transition-colors',
        'text-brand-700 bg-brand-50' => $isActive,
        'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! $isActive,
   ]) }}>
    {{ $slot }}
</a>
