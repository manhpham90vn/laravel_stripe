@props([
    'capacity' => 0,
    'taken' => 0,
    'showLabel' => true,
])

@php
    $capacity  = max(0, (int) $capacity);
    $taken     = max(0, min((int) $taken, $capacity));
    $remaining = $capacity - $taken;
    $pct       = $capacity > 0 ? round($taken / $capacity * 100) : 0;

    // Bar turns warm as it fills — visual urgency for hot batches.
    $barColor = match (true) {
        $remaining === 0 => 'bg-rose-500',
        $pct >= 80       => 'bg-amber-500',
        default          => 'bg-brand-500',
    };
@endphp

<div {{ $attributes }}>
    @if ($showLabel)
        <div class="mb-1.5 flex items-baseline justify-between text-sm">
            <span class="font-medium text-slate-700">
                @if ($remaining === 0)
                    Đã hết slot
                @else
                    Còn <span class="text-slate-900">{{ number_format($remaining) }}</span> slot
                @endif
            </span>
            <span class="text-xs text-slate-400">{{ number_format($taken) }}/{{ number_format($capacity) }}</span>
        </div>
    @endif

    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100"
         role="progressbar" aria-valuenow="{{ $taken }}" aria-valuemin="0" aria-valuemax="{{ $capacity }}">
        <div class="h-full rounded-full {{ $barColor }} transition-all" style="width: {{ $pct }}%"></div>
    </div>
</div>
