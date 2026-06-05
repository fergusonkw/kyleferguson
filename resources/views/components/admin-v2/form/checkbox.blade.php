@props([
    'name',
    'label' => null,
    'checked' => false,
    'value' => '1',
    'disabled' => false,
    'help' => null,
    'error' => null,
])

<div class="mb-5">
    <div class="flex items-center gap-2">
        <input
            type="checkbox"
            id="{{ $name }}"
            name="{{ $name }}"
            value="{{ $value }}"
            {{ $attributes->merge(['class' => 'form-checkbox form-checkbox-light size-4.25' . ($error || $errors->has($name) ? ' is-invalid' : '')]) }}
            @if(old($name, $checked)) checked @endif
            @if($disabled) disabled @endif
        >

        @if($label)
            <label class="text-sm" for="{{ $name }}">{{ $label }}</label>
        @endif
    </div>

    @if($help)
        <p class="text-xs text-default-400 mt-1">{{ $help }}</p>
    @endif

    @if($error || $errors->has($name))
        <p class="text-xs text-danger mt-1">{{ $error ?? $errors->first($name) }}</p>
    @endif
</div>
