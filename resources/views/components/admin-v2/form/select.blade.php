@props([
    'name',
    'id' => null,
    'label' => null,
    'options' => [],
    'selected' => null,
    'value' => null,
    'placeholder' => 'Select an option',
    'required' => false,
    'disabled' => false,
    'help' => null,
    'error' => null,
    'multiple' => false,
])

@php
    $elementId = $id ?? $name;
    $selectedValue = $selected ?? $value;
@endphp

<div class="mb-5">
    @if($label)
        <label for="{{ $elementId }}" class="form-label">
            {{ $label }}
            @if($required)
                <span class="text-danger">*</span>
            @endif
        </label>
    @endif

    <select
        id="{{ $elementId }}"
        name="{{ $name }}{{ $multiple ? '[]' : '' }}"
        {{ $attributes->merge(['class' => 'form-select' . ($error || $errors->has($name) ? ' is-invalid' : '')]) }}
        @if($required) required @endif
        @if($disabled) disabled @endif
        @if($multiple) multiple @endif
    >
        @if($placeholder && !$multiple)
            <option value="">{{ $placeholder }}</option>
        @endif

        @foreach($options as $optValue => $optLabel)
            <option value="{{ $optValue }}"
                    @if(old($name, $selectedValue) == $optValue) selected @endif>
                {{ $optLabel }}
            </option>
        @endforeach
    </select>

    @if($help)
        <p class="text-xs text-default-400 mt-1">{{ $help }}</p>
    @endif

    @if($error || $errors->has($name))
        <p class="text-xs text-danger mt-1">{{ $error ?? $errors->first($name) }}</p>
    @endif
</div>
