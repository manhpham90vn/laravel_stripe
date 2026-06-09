<x-layouts.auth title="Đăng nhập">
    <h1 class="text-xl font-bold text-slate-900">Đăng nhập</h1>
    <p class="mt-1 text-sm text-slate-500">Tiếp tục hành trình học của bạn.</p>

    @if (session('status'))
        <x-alert variant="success" class="mt-4">{{ session('status') }}</x-alert>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4">
        @csrf
        <x-input name="email" label="Email" type="email" required autofocus />
        <x-input name="password" label="Mật khẩu" type="password" required />

        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="remember" class="rounded border-slate-300 text-brand-600 focus-ring">
            Ghi nhớ đăng nhập
        </label>

        <x-button type="submit" size="lg" class="w-full">Đăng nhập</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-500">
        Chưa có tài khoản?
        <a href="{{ route('register') }}" class="font-medium text-brand-600 hover:underline">Đăng ký</a>
    </p>
</x-layouts.auth>
