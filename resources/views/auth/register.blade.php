<x-layouts.auth title="Đăng ký">
    <h1 class="text-xl font-bold text-slate-900">Tạo tài khoản</h1>
    <p class="mt-1 text-sm text-slate-500">Đăng ký để mua và truy cập khóa học.</p>

    <form method="POST" action="{{ route('register.store') }}" class="mt-6 space-y-4">
        @csrf
        <x-input name="name" label="Họ tên" required autofocus />
        <x-input name="email" label="Email" type="email" required />
        <x-input name="password" label="Mật khẩu" type="password" required />
        <x-input name="password_confirmation" label="Nhập lại mật khẩu" type="password" required />

        <x-button type="submit" size="lg" class="w-full">Đăng ký</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-500">
        Đã có tài khoản?
        <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:underline">Đăng nhập</a>
    </p>
</x-layouts.auth>
