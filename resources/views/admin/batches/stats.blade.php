<x-layouts.admin :title="'Thống kê — ' . $batch->name">
    <a href="{{ route('admin.courses.batches.index', $batch->course_id) }}" class="mb-5 inline-block text-sm text-slate-500 hover:text-slate-700">← Về danh sách đợt</a>

    {{-- KPI cards --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @php($cards = [
            ['Đã bán (paid)', $stats['paid'], 'green'],
            ['Đang giữ chỗ', $stats['active_reservations'], 'amber'],
            ['Còn lại', $stats['remaining'], 'sky'],
            ['Doanh thu', '¥' . number_format($stats['revenue']), 'indigo'],
        ])
        @foreach ($cards as [$label, $value, $color])
            <x-card>
                <p class="text-xs uppercase tracking-wide text-slate-400">{{ $label }}</p>
                <p class="mt-1 text-2xl font-bold text-slate-900">{{ $value }}</p>
            </x-card>
        @endforeach
    </div>

    <div class="mt-4">
        <x-card>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-slate-900">Sức chứa</h2>
                <x-batch-status-badge :status="$batch->status" />
            </div>
            <div class="mt-4"><x-slot-meter :capacity="$stats['capacity']" :taken="$stats['taken']" /></div>
        </x-card>
    </div>

    {{-- Orders --}}
    <h2 class="mb-3 mt-8 text-sm font-semibold uppercase tracking-wide text-slate-500">Đơn hàng</h2>
    <x-card class="!p-0 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-5 py-3 font-medium">#</th>
                    <th class="px-5 py-3 font-medium">Khách</th>
                    <th class="px-5 py-3 font-medium">Trạng thái</th>
                    <th class="px-5 py-3 font-medium">Số tiền</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($orders as $order)
                    <tr>
                        <td class="px-5 py-3 font-mono text-slate-500">#{{ $order->id }}</td>
                        <td class="px-5 py-3 text-slate-700">{{ $order->user?->name ?? '—' }}</td>
                        <td class="px-5 py-3"><x-order-status-badge :status="$order->status" /></td>
                        <td class="px-5 py-3 text-slate-700"><x-price :amount="$order->amount" /></td>
                        <td class="px-5 py-3 text-right">
                            @if ($order->status === \App\Models\Order::STATUS_PAID)
                                <form method="POST" action="{{ route('admin.orders.refund', $order) }}"
                                      onsubmit="return confirm('Hoàn tiền đơn #{{ $order->id }}? Quyền học sẽ bị thu hồi.')">
                                    @csrf
                                    <button class="text-sm font-medium text-rose-600 hover:underline">Hoàn tiền</button>
                                </form>
                            @else
                                <span class="text-xs text-slate-300">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center text-slate-400">Chưa có đơn hàng.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>
</x-layouts.admin>
