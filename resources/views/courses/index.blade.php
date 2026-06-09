{{-- GET /courses — danh sách course đang mở bán (spec §9, US-1) --}}
<x-layouts.app title="Khóa học">
    {{-- Hero --}}
    <section class="mb-10 overflow-hidden rounded-3xl bg-gradient-to-br from-brand-700 via-brand-600 to-brand-500 px-6 py-12 text-white sm:px-12 sm:py-16">
        <div class="max-w-2xl">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-medium ring-1 ring-inset ring-white/20">
                <span class="size-1.5 rounded-full bg-emerald-300"></span>
                Đợt mở bán giới hạn số lượng
            </span>
            <h1 class="mt-4 text-3xl font-bold tracking-tight sm:text-4xl">Học kỹ năng mới theo từng đợt</h1>
            <p class="mt-3 text-brand-100">Mỗi đợt chỉ nhận số lượng học viên giới hạn. Đặt chỗ sớm, thanh toán an toàn qua Stripe, học trọn đời.</p>
        </div>
    </section>

    {{-- Filters (tĩnh — minh hoạ UI) --}}
    <div class="mb-6 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-slate-900">Đang mở bán</h2>
        <div class="hidden items-center gap-2 sm:flex">
            <x-badge color="indigo">{{ count($courses) }} khóa học</x-badge>
        </div>
    </div>

    @if (count($courses))
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($courses as $course)
                <x-course-card :course="$course" />
            @endforeach
        </div>
    @else
        <x-empty-state title="Chưa có khóa học nào đang mở bán">
            Quay lại sau để xem các đợt mở bán mới nhé.
        </x-empty-state>
    @endif
</x-layouts.app>
