{{-- GET /courses/{slug} — chi tiết course + danh sách đợt (spec §9) --}}
<x-layouts.app :title="$course['title']">
    {{-- Breadcrumb --}}
    <nav class="mb-6 flex items-center gap-2 text-sm text-slate-500">
        <a href="{{ url('/courses') }}" class="hover:text-slate-700">Khóa học</a>
        <span class="text-slate-300">/</span>
        <span class="text-slate-700">{{ $course['title'] }}</span>
    </nav>

    <div class="grid gap-8 lg:grid-cols-3">
        {{-- Main --}}
        <div class="lg:col-span-2">
            <div class="aspect-[16/9] overflow-hidden rounded-2xl bg-gradient-to-br {{ $course['cover_from'] }} {{ $course['cover_to'] }}"></div>

            <div class="mt-6 flex flex-wrap items-center gap-2">
                <x-badge color="indigo">{{ $course['level'] }}</x-badge>
                <x-badge color="slate">{{ $course['lessons'] }} bài học</x-badge>
                <x-badge color="slate">{{ $course['duration'] }}</x-badge>
            </div>

            <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $course['title'] }}</h1>
            <p class="mt-3 leading-relaxed text-slate-600">{{ $course['description'] }}</p>

            <h2 class="mt-10 text-lg font-semibold text-slate-900">Bạn sẽ học được gì</h2>
            <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ($course['outcomes'] as $item)
                    <li class="flex items-start gap-2.5 text-sm text-slate-600">
                        <svg class="mt-0.5 size-5 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        {{ $item }}
                    </li>
                @endforeach
            </ul>
        </div>

        {{-- Sidebar: các đợt mở bán --}}
        <aside class="lg:col-span-1">
            <div class="lg:sticky lg:top-24">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Các đợt mở bán</h2>
                <div class="space-y-4">
                    @foreach ($course['batches'] as $batch)
                        <x-batch-card :batch="$batch" :highlight="$batch['status'] === 'on_sale'" />
                    @endforeach
                </div>
            </div>
        </aside>
    </div>
</x-layouts.app>
