@props([
    'name',
    'label' => null,
    'value' => '',
    'placeholder' => '',
    'rows' => 3,
    'required' => false,
    'disabled' => false,
    'readonly' => false,
    'help' => null,
    'error' => null,
])

<div class="mb-5">
    @if($label)
        <label for="{{ $name }}" class="form-label">
            {{ $label }}
            @if($required)
                <span class="text-danger">*</span>
            @endif
        </label>
    @endif

    <textarea
        id="{{ $name }}"
        name="{{ $name }}"
        rows="{{ $rows }}"
        {{ $attributes->merge(['class' => 'form-textarea' . ($error || $errors->has($name) ? ' is-invalid' : '')]) }}
        @if($placeholder) placeholder="{{ $placeholder }}" @endif
        @if($required) required @endif
        @if($disabled) disabled @endif
        @if($readonly) readonly @endif
    >{{ old($name, $value) }}</textarea>

    @if($help)
        <p class="text-xs text-default-400 mt-1">{{ $help }}</p>
    @endif

    @if($error || $errors->has($name))
        <p class="text-xs text-danger mt-1">{{ $error ?? $errors->first($name) }}</p>
    @endif
</div>
