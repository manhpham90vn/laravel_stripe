<x-layouts.admin :title="'Đợt — ' . $course->title">
    <a href="{{ route('admin.courses.index') }}" class="mb-5 inline-block text-sm text-slate-500 hover:text-slate-700">← Về danh sách khóa học</a>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Create batch --}}
        <div class="lg:col-span-1">
            <x-card>
                <h2 class="font-semibold text-slate-900">Tạo đợt mở bán</h2>
                <form method="POST" action="{{ route('admin.courses.batches.store', $course) }}" class="mt-4 space-y-4">
                    @csrf
                    <x-input name="name" label="Tên đợt" required />
                    <div class="grid grid-cols-2 gap-3">
                        <x-input name="capacity" label="Số slot" type="number" required />
                        <x-input name="price" label="Giá (¥)" type="number" required />
                    </div>
                    <x-input name="sale_starts_at" label="Mở bán từ" type="datetime-local" required />
                    <x-input name="sale_ends_at" label="Đóng bán lúc" type="datetime-local" hint="Để trống = đến khi hết slot." />
                    <div class="space-y-1.5">
                        <label for="status" class="block text-sm font-medium text-slate-700">Trạng thái</label>
                        <select name="status" id="status" class="focus-ring block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm">
                            @foreach (['scheduled' => 'Sắp mở', 'on_sale' => 'Đang bán', 'closed' => 'Đóng'] as $v => $l)
                                <option value="{{ $v }}" @selected(old('status') === $v)>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-button type="submit" class="w-full">Tạo đợt</x-button>
                </form>
            </x-card>
        </div>

        {{-- Batch list --}}
        <div class="lg:col-span-2 space-y-4">
            @forelse ($course->batches as $batch)
                <x-card>
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h3 class="font-semibold text-slate-900">{{ $batch->name }}</h3>
                                <x-batch-status-badge :status="$batch->status" />
                            </div>
                            <p class="mt-1 text-sm text-slate-500">
                                <x-price :amount="$batch->price" /> ·
                                {{ $batch->sale_starts_at->format('d/m/Y') }} → {{ $batch->sale_ends_at?->format('d/m/Y') ?? 'khi hết slot' }}
                            </p>
                        </div>
                        <a href="{{ route('admin.batches.stats', $batch) }}" class="shrink-0 text-sm font-medium text-brand-600 hover:underline">Thống kê →</a>
                    </div>

                    <div class="mt-4"><x-slot-meter :capacity="$batch->capacity" :taken="$batch->slots_taken" /></div>

                    {{-- Quick status change (US-10) --}}
                    <form method="POST" action="{{ route('admin.batches.update', $batch) }}" class="mt-4 flex items-end gap-2 border-t border-slate-100 pt-4">
                        @csrf @method('PATCH')
                        <input type="hidden" name="name" value="{{ $batch->name }}">
                        <input type="hidden" name="capacity" value="{{ $batch->capacity }}">
                        <input type="hidden" name="price" value="{{ $batch->price }}">
                        <input type="hidden" name="sale_starts_at" value="{{ $batch->sale_starts_at->format('Y-m-d\TH:i') }}">
                        @if ($batch->sale_ends_at)
                            <input type="hidden" name="sale_ends_at" value="{{ $batch->sale_ends_at->format('Y-m-d\TH:i') }}">
                        @endif
                        <div class="flex-1 space-y-1">
                            <label class="block text-xs font-medium text-slate-500">Đổi trạng thái</label>
                            <select name="status" class="focus-ring block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                                @foreach (['scheduled','on_sale','sold_out','closed'] as $s)
                                    <option value="{{ $s }}" @selected($batch->status === $s)>{{ $s }}</option>
                                @endforeach
                            </select>
                        </div>
                        <x-button type="submit" variant="secondary">Lưu</x-button>
                    </form>
                </x-card>
            @empty
                <x-empty-state title="Chưa có đợt mở bán">Tạo đợt đầu tiên ở bên trái.</x-empty-state>
            @endforelse
        </div>
    </div>
</x-layouts.admin>
