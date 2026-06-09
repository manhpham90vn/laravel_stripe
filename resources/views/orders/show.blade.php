{{-- GET /orders/{id} — trạng thái đơn, success_url của Stripe trỏ về đây (spec §7.1) --}}
<x-layouts.app title="Đơn hàng">
    <div class="mx-auto max-w-2xl">
        <a href="{{ url('/my/courses') }}" class="mb-6 inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
            Về khóa học của tôi
        </a>

        <x-card class="overflow-hidden !p-0">
            {{-- Header theo trạng thái --}}
            @php
                $head = match ($order['status']) {
                    'paid'       => ['bg-emerald-50', 'text-emerald-600', 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'Thanh toán thành công'],
                    'processing' => ['bg-sky-50', 'text-sky-600', 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z', 'Đang chờ thanh toán'],
                    'pending'    => ['bg-amber-50', 'text-amber-600', 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z', 'Đang xác nhận'],
                    'failed'     => ['bg-rose-50', 'text-rose-600', 'M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'Thanh toán thất bại'],
                    default      => ['bg-slate-50', 'text-slate-500', 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z', 'Đơn hàng'],
                };
            @endphp
            <div class="flex flex-col items-center {{ $head[0] }} px-6 py-10 text-center">
                <div class="grid size-14 place-items-center rounded-full bg-white shadow-sm {{ $head[1] }}">
                    <svg class="size-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $head[2] }}"/></svg>
                </div>
                <h1 class="mt-4 text-xl font-bold text-slate-900">{{ $head[3] }}</h1>
                <div class="mt-2"><x-order-status-badge :status="$order['status']" /></div>
            </div>

            <div class="p-6">
                {{-- Thông báo theo trạng thái (webhook là nguồn sự thật — spec §7.1) --}}
                @if ($order['status'] === 'pending')
                    <x-alert variant="info">Chúng tôi đang xác nhận thanh toán với Stripe. Trang sẽ cập nhật sau giây lát — bạn có thể tải lại.</x-alert>
                @elseif ($order['status'] === 'processing')
                    <x-alert variant="warning" title="Hoàn tất tại cửa hàng tiện lợi">
                        Mã thanh toán đã được tạo. Vui lòng trả tiền trước <strong>{{ $order['due_at'] }}</strong>. Slot của bạn đang được giữ.
                    </x-alert>
                @elseif ($order['status'] === 'failed')
                    <x-alert variant="danger">Thanh toán không thành công. Bạn có thể thử lại bên dưới.</x-alert>
                @endif

                <dl class="divide-y divide-slate-100 text-sm">
                    <div class="flex justify-between py-3"><dt class="text-slate-500">Mã đơn</dt><dd class="font-mono text-slate-800">#{{ $order['id'] }}</dd></div>
                    <div class="flex justify-between py-3"><dt class="text-slate-500">Khóa học</dt><dd class="font-medium text-slate-800">{{ $order['course_title'] }}</dd></div>
                    <div class="flex justify-between py-3"><dt class="text-slate-500">Đợt</dt><dd class="text-slate-800">{{ $order['batch_name'] }}</dd></div>
                    <div class="flex justify-between py-3"><dt class="text-slate-500">Phương thức</dt><dd class="text-slate-800">{{ $order['method'] }}</dd></div>
                    <div class="flex items-baseline justify-between py-3"><dt class="text-slate-500">Số tiền</dt><dd class="text-lg font-bold text-slate-900"><x-price :amount="$order['amount']" /></dd></div>
                </dl>

                <div class="mt-6 flex gap-3">
                    @if ($order['status'] === 'paid')
                        <x-button href="{{ url('/my/courses') }}" size="lg" class="flex-1">Vào học ngay</x-button>
                    @elseif ($order['status'] === 'failed')
                        <x-button href="{{ url('/batches/' . $order['batch_id']) }}" size="lg" class="flex-1">Thử lại</x-button>
                    @elseif ($order['status'] === 'pending')
                        {{-- Resume Stripe Checkout for this held order (spec §12) --}}
                        <form method="POST" action="{{ route('orders.pay', $order['id']) }}" class="flex-1">
                            @csrf
                            <x-button type="submit" size="lg" class="w-full">Tiếp tục thanh toán</x-button>
                        </form>
                        <form method="POST" action="{{ route('orders.cancel', $order['id']) }}">
                            @csrf
                            <x-button type="submit" variant="ghost" size="lg">Hủy đơn</x-button>
                        </form>
                    @else
                        <x-button variant="secondary" size="lg" class="flex-1" onclick="location.reload()">Tải lại trạng thái</x-button>
                        <form method="POST" action="{{ route('orders.cancel', $order['id']) }}">
                            @csrf
                            <x-button type="submit" variant="ghost" size="lg">Hủy đơn</x-button>
                        </form>
                    @endif
                </div>
            </div>
        </x-card>
    </div>
</x-layouts.app>
