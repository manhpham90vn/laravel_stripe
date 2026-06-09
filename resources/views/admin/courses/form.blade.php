@php($editing = $course->exists)

<x-layouts.admin :title="$editing ? 'Sửa khóa học' : 'Khóa học mới'">
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('admin.courses.index') }}" class="mb-5 inline-block text-sm text-slate-500 hover:text-slate-700">← Về danh sách</a>

        <x-card>
            <form method="POST" action="{{ $editing ? route('admin.courses.update', $course) : route('admin.courses.store') }}" class="space-y-5">
                @csrf
                @if ($editing) @method('PUT') @endif

                <x-input name="title" label="Tiêu đề" :value="$course->title" required />
                <x-input name="slug" label="Slug" :value="$course->slug" hint="Để trống sẽ tự tạo từ tiêu đề." />
                <x-input name="summary" label="Tóm tắt (excerpt)" :value="$course->summary" required />

                <div class="space-y-1.5">
                    <label for="description" class="block text-sm font-medium text-slate-700">Mô tả</label>
                    <textarea name="description" id="description" rows="4"
                        class="focus-ring block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm">{{ old('description', $course->description) }}</textarea>
                    @error('description')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-input name="level" label="Trình độ" :value="$course->level" />
                    <x-input name="lessons_count" label="Số bài" type="number" :value="$course->lessons_count" />
                    <x-input name="duration_label" label="Thời lượng" :value="$course->duration_label" />
                </div>

                <div class="space-y-1.5">
                    <label for="outcomes" class="block text-sm font-medium text-slate-700">Kết quả học (mỗi dòng 1 ý)</label>
                    <textarea name="outcomes" id="outcomes" rows="4"
                        class="focus-ring block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm">{{ old('outcomes', is_array($course->outcomes) ? implode("\n", $course->outcomes) : '') }}</textarea>
                </div>

                <div class="space-y-1.5">
                    <label for="status" class="block text-sm font-medium text-slate-700">Trạng thái</label>
                    <select name="status" id="status" class="focus-ring block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm">
                        @foreach (['draft' => 'Nháp', 'published' => 'Đã xuất bản', 'archived' => 'Lưu trữ'] as $val => $label)
                            <option value="{{ $val }}" @selected(old('status', $course->status) === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-100 pt-5">
                    <x-button href="{{ route('admin.courses.index') }}" variant="secondary">Hủy</x-button>
                    <x-button type="submit">{{ $editing ? 'Lưu thay đổi' : 'Tạo khóa học' }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.admin>
