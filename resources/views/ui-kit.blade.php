{{-- /ui-kit — bảng trưng bày toàn bộ component (tham khảo khi dev) --}}
<x-layouts.app title="UI Kit">
    <header class="mb-8">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">UI Kit</h1>
        <p class="mt-1 text-slate-500">Thư viện component dùng chung. Tham khảo cách dùng trong <code class="rounded bg-slate-100 px-1.5 py-0.5 text-sm">resources/views/components</code>.</p>
    </header>

    <div class="space-y-10">
        {{-- Buttons --}}
        <section>
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Buttons</h2>
            <x-card>
                <div class="flex flex-wrap items-center gap-3">
                    <x-button>Primary</x-button>
                    <x-button variant="secondary">Secondary</x-button>
                    <x-button variant="ghost">Ghost</x-button>
                    <x-button variant="danger">Danger</x-button>
                    <x-button disabled>Disabled</x-button>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <x-button size="sm">Small</x-button>
                    <x-button size="md">Medium</x-button>
                    <x-button size="lg">Large</x-button>
                </div>
            </x-card>
        </section>

        {{-- Badges --}}
        <section>
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Badges & Statuses</h2>
            <x-card>
                <div class="flex flex-wrap gap-2">
                    <x-badge color="slate" dot>Slate</x-badge>
                    <x-badge color="green" dot>Green</x-badge>
                    <x-badge color="amber" dot>Amber</x-badge>
                    <x-badge color="rose" dot>Rose</x-badge>
                    <x-badge color="indigo" dot>Indigo</x-badge>
                    <x-badge color="sky" dot>Sky</x-badge>
                </div>
                <p class="mt-5 mb-2 text-xs font-medium text-slate-400">Trạng thái đợt</p>
                <div class="flex flex-wrap gap-2">
                    @foreach (['scheduled','on_sale','sold_out','closed'] as $s)
                        <x-batch-status-badge :status="$s" />
                    @endforeach
                </div>
                <p class="mt-5 mb-2 text-xs font-medium text-slate-400">Trạng thái đơn</p>
                <div class="flex flex-wrap gap-2">
                    @foreach (['pending','processing','paid','failed','canceled','refunded','disputed'] as $s)
                        <x-order-status-badge :status="$s" />
                    @endforeach
                </div>
            </x-card>
        </section>

        {{-- Alerts --}}
        <section>
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Alerts</h2>
            <x-alert variant="info" title="Thông tin">Webhook là nguồn sự thật cho trạng thái thanh toán.</x-alert>
            <x-alert variant="success" title="Thành công">Đã cấp quyền truy cập khóa học.</x-alert>
            <x-alert variant="warning" title="Lưu ý">Slot được giữ tới hạn thanh toán Konbini.</x-alert>
            <x-alert variant="danger" title="Lỗi">Đã hết slot cho đợt này.</x-alert>
        </section>

        {{-- Slot meter --}}
        <section>
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Slot meter</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-card><x-slot-meter :capacity="50" :taken="12" /></x-card>
                <x-card><x-slot-meter :capacity="50" :taken="44" /></x-card>
                <x-card><x-slot-meter :capacity="50" :taken="50" /></x-card>
            </div>
        </section>

        {{-- Price --}}
        <section>
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Price (JPY)</h2>
            <x-card>
                <div class="flex gap-6 text-lg font-bold text-slate-900">
                    <x-price :amount="9800" />
                    <x-price :amount="29800" />
                    <x-price :amount="128000" />
                </div>
            </x-card>
        </section>
    </div>
</x-layouts.app>
