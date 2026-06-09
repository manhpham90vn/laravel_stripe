{{-- GET /my/courses — danh sách enrollment active (spec §9, US-4) --}}
<x-layouts.app title="Khóa học của tôi">
    <header class="mb-8">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Khóa học của tôi</h1>
        <p class="mt-1 text-slate-500">Các khóa bạn đã sở hữu — truy cập trọn đời.</p>
    </header>

    @if (count($enrollments))
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($enrollments as $e)
                <x-card class="flex flex-col !p-0 overflow-hidden">
                    <div class="aspect-[16/9] bg-gradient-to-br {{ $e['cover_from'] }} {{ $e['cover_to'] }}"></div>
                    <div class="flex flex-1 flex-col p-5">
                        <div class="flex items-center gap-2">
                            <x-badge color="green" dot>Đang sở hữu</x-badge>
                            <span class="text-xs text-slate-400">Mua {{ $e['granted_at']->format('d/m') }}</span>
                        </div>
                        <h3 class="mt-2 font-semibold text-slate-900">{{ $e['title'] }}</h3>
                        <div class="mt-1 flex-1"></div>
                        <x-button href="#" variant="secondary" class="mt-4 w-full">Tiếp tục học</x-button>
                    </div>
                </x-card>
            @endforeach
        </div>
    @else
        <x-empty-state title="Bạn chưa sở hữu khóa học nào"
            icon="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25">
            Khám phá các đợt mở bán và bắt đầu hành trình học tập của bạn.
            <div class="mt-4"><x-button href="{{ url('/courses') }}">Xem khóa học</x-button></div>
        </x-empty-state>
    @endif
</x-layouts.app>
