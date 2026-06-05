<!DOCTYPE html>
<html lang="en" data-skin="material" data-theme="light">
<head>
    @include('admin-v2.partials.title-meta', ['title' => $title ?? 'Admin'])
    @yield('styles')
    @include('admin-v2.partials.head-css')
</head>
<body @yield('body_attribute')>
    @yield('content')
    @stack('scripts')
</body>
</html>
