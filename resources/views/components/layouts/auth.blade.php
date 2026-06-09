@props(['title' => null])

<!DOCTYPE html>
<html lang="vi" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title . ' — Manabi' : 'Manabi' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full flex-col items-center justify-center bg-slate-50 px-4 py-12">
    <a href="{{ url('/courses') }}" class="mb-8 flex items-center gap-2 font-semibold text-slate-900">
        <span class="grid size-9 place-items-center rounded-lg bg-brand-600 text-white">学</span>
        <span class="text-xl tracking-tight">Manabi</span>
    </a>

    <div class="w-full max-w-md">
        <x-card class="!p-8">
            {{ $slot }}
        </x-card>
    </div>
</body>
</html>
