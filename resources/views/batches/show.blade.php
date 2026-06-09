{{-- GET /batches/{id} — trang 1 đợt mở bán (spec §9) --}}
<x-layouts.app :title="$batch['course_title'] . ' — ' . $batch['name']">
    <nav class="mb-6 flex items-center gap-2 text-sm text-slate-500">
        <a href="{{ url('/courses') }}" class="hover:text-slate-700">Khóa học</a>
        <span class="text-slate-300">/</span>
        <a href="{{ url('/courses/' . $batch['course_slug']) }}" class="hover:text-slate-700">{{ $batch['course_title'] }}</a>
        <span class="text-slate-300">/</span>
        <span class="text-slate-700">{{ $batch['name'] }}</span>
    </nav>

    <div class="grid gap-8 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            <div>
                <div class="flex items-center gap-2.5">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ $batch['name'] }}</h1>
                    <x-batch-status-badge :status="$batch['status']" />
                </div>
                <p class="mt-1 text-slate-500">Khóa: {{ $batch['course_title'] }}</p>
            </div>

            <x-batch-card :batch="$batch" highlight />

            {{-- Phương thức thanh toán (JP: card + async) — spec §1, §7.2 --}}
            <x-card>
                <h2 class="font-semibold text-slate-900">Phương thức thanh toán</h2>
                <p class="mt-1 text-sm text-slate-500">Hỗ trợ thẻ và các phương thức thanh toán phổ biến tại Nhật.</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    @foreach (['Thẻ tín dụng/ghi nợ' => 'Xác nhận ngay', 'Konbini' => 'Trả tại cửa hàng tiện lợi', 'Pay-easy' => 'Chuyển khoản ngân hàng'] as $method => $note)
                        <div class="rounded-xl border border-slate-200 p-3">
                            <p class="text-sm font-medium text-slate-800">{{ $method }}</p>
                            <p class="text-xs text-slate-400">{{ $note }}</p>
                        </div>
                    @endforeach
                </div>
                <x-alert variant="info" class="mt-4 mb-0">
                    Với Konbini/Pay-easy, slot của bạn được <strong>giữ tới hạn thanh toán</strong>. Quyền học được cấp ngay khi tiền về.
                </x-alert>
            </x-card>
        </div>

        {{-- Tóm tắt đặt chỗ --}}
        <aside class="lg:col-span-1">
            <div class="lg:sticky lg:top-24">
                <x-card>
                    <h2 class="font-semibold text-slate-900">Tóm tắt</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Đợt</dt><dd class="font-medium text-slate-800">{{ $batch['name'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Trạng thái</dt><dd><x-batch-status-badge :status="$batch['status']" /></dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Còn lại</dt><dd class="font-medium text-slate-800">{{ number_format(max(0, $batch['capacity'] - $batch['taken'])) }} slot</dd></div>
                        <div class="my-2 border-t border-slate-100"></div>
                        <div class="flex items-baseline justify-between"><dt class="text-slate-500">Tổng</dt><dd class="text-xl font-bold text-slate-900"><x-price :amount="$batch['price']" /></dd></div>
                    </dl>

                    @if ($batch['status'] === 'on_sale' && $batch['capacity'] - $batch['taken'] > 0)
                        <form method="POST" action="{{ url('/batches/' . $batch['id'] . '/checkout') }}" class="mt-5">
                            @csrf
                            <x-button type="submit" size="lg" class="w-full">Mua ngay</x-button>
                        </form>
                        <p class="mt-2 text-center text-xs text-slate-400">Mỗi người tối đa 1 suất / đợt</p>
                    @else
                        <x-button variant="secondary" size="lg" disabled class="mt-5 w-full">Không thể mua</x-button>
                    @endif
                </x-card>
            </div>
        </aside>
    </div>
</x-layouts.app>
