@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => null,
    'required' => false,
    'hint' => null,
])

<div class="space-y-1.5">
    @if ($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-slate-700">
            {{ $label }} @if ($required)<span class="text-rose-500">*</span>@endif
        </label>
    @endif

    <input
        type="{{ $type }}"
        name="{{ $name }}"
        id="{{ $name }}"
        value="{{ old($name, $value) }}"
        @if ($required) required @endif
        {{ $attributes->class([
            'focus-ring block w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm placeholder:text-slate-400',
            'border-slate-300' => ! $errors->has($name),
            'border-rose-400 ring-1 ring-rose-400' => $errors->has($name),
        ]) }}
    >

    @if ($hint && ! $errors->has($name))
        <p class="text-xs text-slate-400">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="text-xs text-rose-600">{{ $message }}</p>
    @enderror
</div>
