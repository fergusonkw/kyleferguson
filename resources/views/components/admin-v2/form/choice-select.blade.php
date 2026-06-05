@props([
    'name',
    'id' => null,
    'label' => null,
    'options' => [],
    'selected' => null,
    'placeholder' => 'Select an option',
    'required' => false,
    'disabled' => false,
    'help' => null,
    'error' => null,
    'multiple' => false,
    'searchable' => true,
    'removeItemButton' => false,
    'shouldSort' => true,
    'maxItemCount' => null,
    'selectClass' => '',
    // AJAX options
    'ajaxUrl' => null,
    'ajaxValueKey' => 'id',
    'ajaxLabelKey' => 'name',
    'ajaxParams' => null,
    'ajaxDataPath' => 'data',
    'ajaxSkipAutoload' => false,
])

@php
    $elementId = $id ?? $name;
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
        data-choices
        class="form-select{{ $error || $errors->has($name) ? ' is-invalid' : '' }}{{ $selectClass ? ' ' . $selectClass : '' }}"
        @if($required) required @endif
        @if($disabled) disabled @endif
        @if($multiple) multiple @endif
        @if($placeholder) data-choices-text-placeholder-value="{{ $placeholder }}" @endif
        @if(!$searchable) data-choices-search-false @endif
        @if($removeItemButton) data-choices-removeItem @endif
        @if(!$shouldSort) data-choices-sorting-false @endif
        @if($maxItemCount) data-choices-limit="{{ $maxItemCount }}" @endif
        @if($ajaxUrl)
            data-choices-ajax-url="{{ $ajaxUrl }}"
            data-choices-ajax-value-key="{{ $ajaxValueKey }}"
            data-choices-ajax-label-key="{{ $ajaxLabelKey }}"
            @if($ajaxParams) data-choices-ajax-params="{{ json_encode($ajaxParams) }}" @endif
            @if($ajaxDataPath) data-choices-ajax-data-path="{{ $ajaxDataPath }}" @endif
            @if($ajaxSkipAutoload) data-choices-ajax-skip-autoload @endif
        @endif
    >
        @if($placeholder && !$multiple)
            <option value="">{{ $placeholder }}</option>
        @endif

        @if(!$ajaxUrl)
            @foreach($options as $optValue => $optLabel)
                <option value="{{ $optValue }}"
                        @if(is_array($selected) ? in_array($optValue, $selected) : old($name, $selected) == $optValue) selected @endif>
                    {{ $optLabel }}
                </option>
            @endforeach
        @else
            @if($selected && count($options) > 0)
                @foreach($options as $optValue => $optLabel)
                    <option value="{{ $optValue }}" selected>{{ $optLabel }}</option>
                @endforeach
            @endif
        @endif
    </select>

    @if($help)
        <p class="text-xs text-default-400 mt-1">{{ $help }}</p>
    @endif

    @if($error || $errors->has($name))
        <p class="text-xs text-danger mt-1">{{ $error ?? $errors->first($name) }}</p>
    @endif
</div>
