@php
    $appName = config('app.name', 'Admin Starter');
@endphp
<meta charset="utf-8" />
<title>{{ ($title ?? 'Admin') . ' | ' . $appName }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
<meta name="description" content="{{ $appName }} admin panel" />
<meta name="csrf-token" content="{{ csrf_token() }}" />
