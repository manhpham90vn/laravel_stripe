@props(['href' => '#', 'active' => false])

<a href="{{ $href }}"
   {{ $attributes->class([
        'block rounded-lg px-3 py-2 text-sm font-medium transition-colors',
        'bg-brand-50 text-brand-700' => $active,
        'text-slate-600 hover:bg-slate-100' => ! $active,
   ]) }}>
    {{ $slot }}
</a>
