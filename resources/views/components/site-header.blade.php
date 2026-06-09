{{-- Top navigation. Auth-aware; renders for guest, buyer and admin. --}}
@php($user = auth()->check() ? auth()->user() : null)

<header class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/85 backdrop-blur">
    <div class="mx-auto flex h-16 w-full max-w-6xl items-center gap-6 px-4 sm:px-6">
        <a href="{{ url('/courses') }}" class="flex items-center gap-2 font-semibold text-slate-900">
            <span class="grid size-8 place-items-center rounded-lg bg-brand-600 text-sm font-bold text-white">学</span>
            <span class="text-lg tracking-tight">Manabi</span>
        </a>

        <nav class="hidden items-center gap-1 sm:flex">
            <x-nav-link href="{{ url('/courses') }}">Khóa học</x-nav-link>
            @auth
                <x-nav-link href="{{ url('/my/courses') }}">Khóa học của tôi</x-nav-link>
                @if ($user?->isAdmin())
                    <x-nav-link href="{{ url('/admin/courses') }}">Quản trị</x-nav-link>
                @endif
            @endauth
        </nav>

        <div class="ml-auto flex items-center gap-3">
            @if ($user)
                @if ($user->isAdmin())
                    <x-badge color="indigo">Admin</x-badge>
                @endif
                <span class="hidden text-sm text-slate-500 sm:block">{{ $user->name }}</span>
                <span class="grid size-9 place-items-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">
                    {{ strtoupper(mb_substr($user->name, 0, 1)) }}
                </span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-sm font-medium text-slate-500 hover:text-slate-900">Đăng xuất</button>
                </form>
            @else
                <a href="{{ route('login') }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">Đăng nhập</a>
                <x-button href="{{ route('register') }}" size="sm">Đăng ký</x-button>
            @endif
        </div>
    </div>
</header>
