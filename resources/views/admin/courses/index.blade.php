<x-layouts.admin title="Khóa học & đợt">
    <div class="mb-5 flex items-center justify-between">
        <p class="text-sm text-slate-500">{{ $courses->count() }} khóa học</p>
        <x-button href="{{ route('admin.courses.create') }}">+ Khóa học mới</x-button>
    </div>

    <x-card class="!p-0 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-5 py-3 font-medium">Khóa học</th>
                    <th class="px-5 py-3 font-medium">Trạng thái</th>
                    <th class="px-5 py-3 font-medium">Số đợt</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($courses as $course)
                    <tr class="hover:bg-slate-50/60">
                        <td class="px-5 py-3">
                            <p class="font-medium text-slate-900">{{ $course->title }}</p>
                            <p class="text-xs text-slate-400">/{{ $course->slug }}</p>
                        </td>
                        <td class="px-5 py-3">
                            <x-badge :color="match($course->status) { 'published' => 'green', 'archived' => 'slate', default => 'amber' }">
                                {{ $course->status }}
                            </x-badge>
                        </td>
                        <td class="px-5 py-3 text-slate-600">{{ $course->batches_count }}</td>
                        <td class="px-5 py-3 text-right">
                            <a href="{{ route('admin.courses.batches.index', $course) }}" class="text-sm font-medium text-brand-600 hover:underline">Quản lý đợt</a>
                            <a href="{{ route('admin.courses.edit', $course) }}" class="ml-4 text-sm text-slate-500 hover:underline">Sửa</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-5 py-10 text-center text-slate-400">Chưa có khóa học nào.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>
</x-layouts.admin>
