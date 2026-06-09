@props([
    'title' => null,
])

<!DOCTYPE html>
<html lang="vi" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title . ' — Manabi' : 'Manabi — Nền tảng khóa học' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full flex-col">
    <x-site-header />

    {{-- Flash messages (PRG pattern — spec §9) --}}
    @if (session('status') || session('error'))
        <div class="mx-auto w-full max-w-6xl px-4 pt-6 sm:px-6">
            @if (session('status'))
                <x-alert variant="success">{{ session('status') }}</x-alert>
            @endif
            @if (session('error'))
                <x-alert variant="danger">{{ session('error') }}</x-alert>
            @endif
        </div>
    @endif

    <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-6 sm:py-10">
        {{ $slot }}
    </main>

    <x-site-footer />
</body>
</html>
