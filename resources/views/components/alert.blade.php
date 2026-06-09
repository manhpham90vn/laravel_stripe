@props([
    'variant' => 'info',   // info | success | warning | danger
    'title' => null,
])

@php
    $styles = [
        'info'    => ['wrap' => 'bg-sky-50 text-sky-800 ring-sky-200',       'icon' => 'text-sky-500'],
        'success' => ['wrap' => 'bg-emerald-50 text-emerald-800 ring-emerald-200', 'icon' => 'text-emerald-500'],
        'warning' => ['wrap' => 'bg-amber-50 text-amber-800 ring-amber-200',  'icon' => 'text-amber-500'],
        'danger'  => ['wrap' => 'bg-rose-50 text-rose-800 ring-rose-200',     'icon' => 'text-rose-500'],
    ];
    $s = $styles[$variant] ?? $styles['info'];

    $icons = [
        'info'    => 'M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z',
        'success' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'warning' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z',
        'danger'  => 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z',
    ];
@endphp

<div {{ $attributes->class('mb-4 flex gap-3 rounded-xl px-4 py-3 text-sm ring-1 ring-inset ' . $s['wrap']) }} role="alert">
    <svg class="mt-0.5 size-5 shrink-0 {{ $s['icon'] }}" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$variant] ?? $icons['info'] }}" />
    </svg>
    <div>
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div class="{{ $title ? 'mt-0.5' : '' }}">{{ $slot }}</div>
    </div>
</div>
