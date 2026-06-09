@props([
    'title' => 'Chưa có gì ở đây',
    'icon' => 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
])

<div {{ $attributes->class('flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center') }}>
    <div class="grid size-12 place-items-center rounded-full bg-slate-100 text-slate-400">
        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" />
        </svg>
    </div>
    <p class="mt-4 font-medium text-slate-700">{{ $title }}</p>
    @if (! $slot->isEmpty())
        <p class="mt-1 max-w-sm text-sm text-slate-500">{{ $slot }}</p>
    @endif
</div>
