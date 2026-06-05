@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => '',
    'placeholder' => '',
    'required' => false,
    'disabled' => false,
    'readonly' => false,
    'help' => null,
    'error' => null,
    'step' => null,
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

    <input
        type="{{ $type }}"
        id="{{ $name }}"
        name="{{ $name }}"
        value="{{ old($name, $value) }}"
        {{ $attributes->merge(['class' => 'form-input' . ($error || $errors->has($name) ? ' is-invalid' : '')]) }}
        @if($placeholder) placeholder="{{ $placeholder }}" @endif
        @if($required) required @endif
        @if($disabled) disabled @endif
        @if($readonly) readonly @endif
        @if($step) step="{{ $step }}" @endif
    >

    @if($help)
        <p class="text-xs text-default-400 mt-1">{{ $help }}</p>
    @endif

    @if($error || $errors->has($name))
        <p class="text-xs text-danger mt-1">{{ $error ?? $errors->first($name) }}</p>
    @endif
</div>
