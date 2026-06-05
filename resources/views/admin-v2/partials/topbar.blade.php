<header class="app-header">
    <div class="container-fluid flex items-center justify-between">

        {{-- Left: logo (visible when sidenav hidden) + toggle --}}
        <div class="flex items-center gap-2.5">
            <div class="logo-topbar">
                <a class="logo-box" href="{{ route('admin.home') }}">
                    <span class="logo-lg text-lg font-semibold">{{ config('app.name') }}</span>
                </a>
            </div>

            <button type="button"
                class="sidenav-toggle-button topbar-link"
                id="button-toggle-menu"
                data-toggle="sidenav-size"
                aria-label="Toggle sidenav">
                <i data-lucide="menu" class="size-5"></i>
            </button>
        </div>

        {{-- Right: topbar actions --}}
        <div class="flex items-center gap-1">

            @isset($currentBusiness)
                @if($availableBusinesses->isNotEmpty())
                    <div class="topbar-item hs-dropdown relative inline-flex" id="business-switcher-dropdown">
                        <button type="button"
                            aria-expanded="false"
                            aria-haspopup="menu"
                            class="hs-dropdown-toggle topbar-link flex cursor-pointer items-center gap-1.5 px-3!">
                            <i data-lucide="briefcase-business" class="size-4"></i>
                            <span class="hidden lg:inline text-sm font-medium">{{ $currentBusiness->name }}</span>
                            <i data-lucide="chevron-down" class="size-4"></i>
                        </button>

                        <div aria-orientation="vertical"
                            class="hs-dropdown-menu min-w-56"
                            role="menu">
                            <div class="py-2 px-3.5">
                                <p class="text-xs text-default-400 uppercase">Switch business</p>
                            </div>
                            <div class="dropdown-divider"></div>
                            @foreach($availableBusinesses as $business)
                                <form action="{{ route('admin.billing.switch', $business) }}" method="POST">
                                    @csrf
                                    <button type="submit"
                                        class="dropdown-item w-full text-left flex items-center justify-between">
                                        <span>{{ $business->name }}</span>
                                        @if($business->id === $currentBusiness?->id)
                                            <i data-lucide="check" class="size-4 text-success"></i>
                                        @endif
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endisset

            {{-- Light/Dark mode --}}
            <div class="topbar-item hidden sm:flex">
                <button type="button" class="topbar-link" data-toggle="theme" aria-label="Toggle theme">
                    <i data-lucide="sun" class="size-5 dark:hidden"></i>
                    <i data-lucide="moon" class="size-5 hidden dark:block"></i>
                </button>
            </div>

            {{-- User dropdown --}}
            <div class="topbar-item hs-dropdown relative inline-flex before:bg-default-700/35 before:h-4.5 before:w-px before:content-['']" id="user-dropdown">
                <button type="button"
                    aria-expanded="false"
                    aria-haspopup="menu"
                    class="hs-dropdown-toggle topbar-link ms-2.5 flex cursor-pointer items-center px-3!">
                    <span class="size-8 rounded-full bg-light flex items-center justify-center lg:me-2">
                        <i data-lucide="circle-user" class="size-5 text-muted"></i>
                    </span>
                    <div class="hidden lg:flex items-center gap-1.5">
                        <span class="text-sm font-medium">{{ Auth::user()->name }}</span>
                        <i data-lucide="chevron-down" class="size-4"></i>
                    </div>
                </button>

                <div aria-orientation="vertical"
                    class="hs-dropdown-menu min-w-48"
                    role="menu">
                    <div class="py-2 px-3.5">
                        <p class="text-xs font-semibold">{{ Auth::user()->name }}</p>
                        <p class="text-xs text-default-400">{{ Auth::user()->email }}</p>
                    </div>

                    <div class="dropdown-divider"></div>

                    <a href="{{ route('admin.settings.index') }}" class="dropdown-item">
                        <i data-lucide="settings-2" class="size-4 me-1.5 align-middle"></i>
                        <span class="align-middle">Account Settings</span>
                    </a>

                    <div class="dropdown-divider"></div>

                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="dropdown-item text-danger font-semibold w-full text-left">
                            <i data-lucide="log-out" class="size-4 me-1.5 align-middle"></i>
                            <span class="align-middle">Log Out</span>
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>
</header>
