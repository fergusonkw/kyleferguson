<!DOCTYPE html>
<html lang="en" data-skin="material" data-theme="light">
<head>
    {{-- Apply saved theme before first paint to prevent flash of wrong theme --}}
    <script>
    (function () {
        try {
            var raw = localStorage.getItem('__THEME_CONFIG__') || sessionStorage.getItem('__THEME_CONFIG__');
            var cfg = raw ? JSON.parse(raw) : {};
            if (cfg.theme) { document.documentElement.setAttribute('data-theme', cfg.theme); }
            if (cfg['sidenav-size']) { document.documentElement.setAttribute('data-sidenav-size', cfg['sidenav-size']); }
        } catch (e) {}
    })();
    </script>
    @include('admin-v2.partials.title-meta', ['title' => $title ?? 'Admin'])
    @yield('styles')
    @stack('styles')
    @include('admin-v2.partials.head-css')
</head>
<body>
    <div class="wrapper">
        @include('admin-v2.partials.sidenav')
        @include('admin-v2.partials.topbar')

        <div class="page-content">
            <main>
                <div class="container-fluid">
                    @yield('content')
                </div>
            </main>

            @include('admin-v2.partials.footer')
        </div>
    </div>

    @stack('scripts')
</body>
</html>
