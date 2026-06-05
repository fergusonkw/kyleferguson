<!DOCTYPE html>
<html lang="en" data-skin="material" data-theme="light" data-layout-mode="horizontal">
<head>
    @include('admin-v2.partials.title-meta', ['title' => $title ?? 'Admin'])
    @yield('styles')
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
