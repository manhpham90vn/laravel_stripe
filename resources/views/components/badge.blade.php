@props([
    'color' => 'slate',   // slate | green | amber | rose | indigo | sky
    'dot' => false,
])

@php
    // Full class strings kept as literals so Tailwind's scanner picks them up.
    $palette = [
        'slate'  => 'bg-slate-100 text-slate-700 ring-slate-200',
        'green'  => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'amber'  => 'bg-amber-50 text-amber-700 ring-amber-200',
        'rose'   => 'bg-rose-50 text-rose-700 ring-rose-200',
        'indigo' => 'bg-brand-50 text-brand-700 ring-brand-200',
        'sky'    => 'bg-sky-50 text-sky-700 ring-sky-200',
    ];

    $dotColor = [
        'slate'  => 'bg-slate-400',
        'green'  => 'bg-emerald-500',
        'amber'  => 'bg-amber-500',
        'rose'   => 'bg-rose-500',
        'indigo' => 'bg-brand-500',
        'sky'    => 'bg-sky-500',
    ];

    $classes = $palette[$color] ?? $palette['slate'];
@endphp

<span {{ $attributes->class('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset ' . $classes) }}>
    @if ($dot)
        <span class="size-1.5 rounded-full {{ $dotColor[$color] ?? $dotColor['slate'] }}"></span>
    @endif
    {{ $slot }}
</span>
