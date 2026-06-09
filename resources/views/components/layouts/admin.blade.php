@props(['title' => null])

<!DOCTYPE html>
<html lang="vi" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title . ' — Admin' : 'Admin — Manabi' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-slate-100">
    <div class="flex min-h-full">
        {{-- Sidebar --}}
        <aside class="hidden w-60 shrink-0 flex-col border-r border-slate-200 bg-white lg:flex">
            <div class="flex h-16 items-center gap-2 border-b border-slate-200 px-5">
                <span class="grid size-8 place-items-center rounded-lg bg-brand-600 text-sm font-bold text-white">学</span>
                <span class="font-semibold tracking-tight">Manabi <span class="text-slate-400">Admin</span></span>
            </div>
            <nav class="flex-1 space-y-1 p-3">
                <x-admin.nav href="{{ route('admin.courses.index') }}" :active="request()->is('admin/courses*')">Khóa học & đợt</x-admin.nav>
                <x-admin.nav href="{{ url('/courses') }}">↗ Xem trang bán</x-admin.nav>
            </nav>
            <div class="border-t border-slate-200 p-3">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="w-full rounded-lg px-3 py-2 text-left text-sm text-slate-600 hover:bg-slate-100">Đăng xuất</button>
                </form>
            </div>
        </aside>

        {{-- Content --}}
        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-16 items-center justify-between border-b border-slate-200 bg-white px-6">
                <h1 class="text-lg font-semibold text-slate-900">{{ $title }}</h1>
                <div class="flex items-center gap-2 text-sm text-slate-500">
                    <x-badge color="indigo">Admin</x-badge>
                    {{ auth()->user()?->name }}
                </div>
            </header>

            <main class="flex-1 p-6">
                @if (session('status'))
                    <x-alert variant="success">{{ session('status') }}</x-alert>
                @endif
                @if (session('error'))
                    <x-alert variant="danger">{{ session('error') }}</x-alert>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
