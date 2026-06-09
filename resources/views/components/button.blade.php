@props([
    'variant' => 'primary',   // primary | secondary | ghost | danger
    'size' => 'md',           // sm | md | lg
    'href' => null,           // when set, renders an <a> instead of <button>
    'type' => 'button',
])

@php
    $base = 'focus-ring inline-flex items-center justify-center gap-2 rounded-lg font-medium '
          . 'transition-colors disabled:cursor-not-allowed disabled:opacity-60';

    $variants = [
        'primary'   => 'bg-brand-600 text-white hover:bg-brand-700 active:bg-brand-800',
        'secondary' => 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50',
        'ghost'     => 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
        'danger'    => 'bg-rose-600 text-white hover:bg-rose-700 active:bg-rose-800',
    ];

    $sizes = [
        'sm' => 'h-9 px-3.5 text-sm',
        'md' => 'h-11 px-5 text-sm',
        'lg' => 'h-12 px-6 text-base',
    ];

    $classes = trim($base . ' ' . ($variants[$variant] ?? $variants['primary']) . ' ' . ($sizes[$size] ?? $sizes['md']));
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
