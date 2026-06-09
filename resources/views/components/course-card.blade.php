@props([
    'course',   // ['slug','title','excerpt','level','cover_from','cover_to','batch' => [...] | null]
])

@php
    $batch = $course['batch'] ?? null;
@endphp

<a href="{{ url('/courses/' . $course['slug']) }}"
   class="focus-ring group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
    {{-- Cover --}}
    <div class="relative aspect-[16/9] bg-gradient-to-br {{ $course['cover_from'] }} {{ $course['cover_to'] }}">
        <span class="absolute left-3 top-3">
            <x-badge color="slate" class="bg-white/85 ring-white/40 backdrop-blur">{{ $course['level'] }}</x-badge>
        </span>
        @if ($batch)
            <span class="absolute right-3 top-3">
                <x-batch-status-badge :status="$batch['status']" class="bg-white/90 backdrop-blur" />
            </span>
        @endif
    </div>

    {{-- Body --}}
    <div class="flex flex-1 flex-col p-5">
        <h3 class="font-semibold text-slate-900 group-hover:text-brand-700">{{ $course['title'] }}</h3>
        <p class="mt-1.5 line-clamp-2 text-sm text-slate-500">{{ $course['excerpt'] }}</p>

        <div class="mt-4 flex-1"></div>

        @if ($batch)
            <div class="mt-2">
                <x-slot-meter :capacity="$batch['capacity']" :taken="$batch['taken']" class="mb-4" />
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[11px] uppercase tracking-wide text-slate-400">{{ $batch['name'] }}</p>
                        <p class="text-lg font-bold text-slate-900"><x-price :amount="$batch['price']" /></p>
                    </div>
                    <span class="text-sm font-medium text-brand-600 group-hover:underline">Xem đợt →</span>
                </div>
            </div>
        @else
            <p class="text-sm text-slate-400">Chưa có đợt mở bán</p>
        @endif
    </div>
</a>
