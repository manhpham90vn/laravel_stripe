@props([
    'batch',          // ['id','name','status','price','capacity','taken','starts_at','ends_at']
    'highlight' => false,
])

@php
    $remaining  = max(0, (int) $batch['capacity'] - (int) $batch['taken']);
    $isOnSale   = $batch['status'] === 'on_sale' && $remaining > 0;
@endphp

<x-card :class="$highlight ? 'ring-2 ring-brand-500/40' : ''">
    <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <div class="flex items-center gap-2.5">
                <h3 class="truncate font-semibold text-slate-900">{{ $batch['name'] }}</h3>
                <x-batch-status-badge :status="$batch['status']" />
            </div>
            <dl class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-500">
                <div class="flex items-center gap-1.5">
                    <svg class="size-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                    {{ $batch['starts_at'] }} → {{ $batch['ends_at'] ?? 'khi hết slot' }}
                </div>
            </dl>
        </div>

        <div class="shrink-0 text-right">
            <p class="text-2xl font-bold text-slate-900"><x-price :amount="$batch['price']" /></p>
            <p class="text-xs text-slate-400">đã gồm thuế</p>
        </div>
    </div>

    <div class="mt-5">
        <x-slot-meter :capacity="$batch['capacity']" :taken="$batch['taken']" />
    </div>

    <div class="mt-5 flex items-center gap-3">
        @if ($isOnSale)
            {{-- Checkout is a POST form → controller → redirect to Stripe (spec §7.1) --}}
            <form method="POST" action="{{ url('/batches/' . $batch['id'] . '/checkout') }}" class="contents">
                @csrf
                <x-button type="submit" size="lg" class="flex-1 sm:flex-none">
                    Mua ngay
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                </x-button>
            </form>
            <span class="text-sm text-slate-400">Thanh toán an toàn qua Stripe</span>
        @elseif ($batch['status'] === 'scheduled')
            <x-button variant="secondary" size="lg" disabled class="flex-1 sm:flex-none">Sắp mở bán</x-button>
        @elseif ($batch['status'] === 'closed')
            {{-- A closed batch stays "Đã đóng" even when full, so the CTA matches the status badge. --}}
            <x-button variant="secondary" size="lg" disabled class="flex-1 sm:flex-none">Đã đóng</x-button>
        @elseif ($batch['status'] === 'sold_out' || $remaining === 0)
            <x-button variant="secondary" size="lg" disabled class="flex-1 sm:flex-none">Đã hết slot</x-button>
        @else
            <x-button variant="secondary" size="lg" disabled class="flex-1 sm:flex-none">Đã đóng</x-button>
        @endif
    </div>
</x-card>
