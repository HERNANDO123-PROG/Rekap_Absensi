<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @livewireStyles
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-slate-100 text-slate-800">
        <div
            x-data="{
                sidebarOpen: false,
                isMobile: window.innerWidth < 1024,
                bodyOverflow: '',
                toggleSidebar() {
                    if (this.sidebarOpen) {
                        this.closeSidebar();
                        return;
                    }

                    this.bodyOverflow = document.body.style.overflow;
                    this.sidebarOpen = true;
                    document.body.style.overflow = 'hidden';
                    this.$nextTick(() => {
                        const firstItem = this.$refs.sidebar.querySelector('a[href], button:not([disabled])');
                        if (firstItem) firstItem.focus();
                    });
                },
                closeSidebar(restoreFocus = true) {
                    this.sidebarOpen = false;
                    document.body.style.overflow = this.bodyOverflow;

                    if (restoreFocus && window.innerWidth < 1024) {
                        this.$nextTick(() => this.$refs.sidebarToggle.focus());
                    }
                }
            }"
            @keydown.escape.window="if (sidebarOpen) closeSidebar()"
            @resize.window="isMobile = window.innerWidth < 1024; if (!isMobile && sidebarOpen) closeSidebar(false)"
            class="min-h-screen bg-slate-100"
        >
            <div class="app-shell">
                <div
                    x-show="sidebarOpen"
                    x-transition.opacity
                    @click="closeSidebar()"
                    class="app-sidebar-overlay"
                    aria-hidden="true"
                ></div>

                <aside
                    id="app-sidebar"
                    x-ref="sidebar"
                    :class="sidebarOpen ? 'is-open' : ''"
                    :aria-hidden="isMobile && !sidebarOpen"
                    :inert="isMobile && !sidebarOpen"
                    class="app-sidebar"
                >
                    <div class="flex items-center gap-3 border-b border-slate-800 px-6 py-5">
                        <span class="app-brand-mark app-brand-mark--sidebar" aria-label="Logo Khazanah Ilmu">
                            <img src="{{ asset('images/logo.png') }}" alt="Logo Khazanah Ilmu">
                        </span>
                        <h1 class="text-lg font-bold text-white">Absensi TK/SD</h1>
                    </div>

                    <nav class="sidebar-nav" aria-label="Navigasi utama" @click="if (window.innerWidth < 1024 && $event.target.closest('a')) closeSidebar()">
                        @php($dashboardLink = ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => '⌂'])
                        @php($isDashboardActive = request()->routeIs('dashboard'))
                        <a href="{{ route('dashboard') }}" class="sidebar-link {{ $isDashboardActive ? 'active' : '' }}">
                            <span class="text-base">{{ $dashboardLink['icon'] }}</span>
                            <span>{{ $dashboardLink['label'] }}</span>
                        </a>

                        <div>
                            <p class="sidebar-section-label">Absensi</p>
                            @foreach ([
                                ['label' => 'Upload Fingerprint', 'route' => 'fingerprint-import.index', 'icon' => '🖥️'],
                                ['label' => 'Upload Data Izin', 'route' => 'leave-import.index', 'icon' => '📄'],
                                ['label' => 'Kalender Kerja', 'route' => 'work-calendar.index', 'icon' => '📅'],
                            ] as $link)
                                @php($isActive = request()->routeIs($link['route']) || ($link['route'] === 'rekap.period' && request()->routeIs('rekap.employee')))
                                <a href="{{ route($link['route']) }}" class="sidebar-link {{ $isActive ? 'active' : '' }}">
                                    <span class="text-base">{{ $link['icon'] }}</span>
                                    <span>{{ $link['label'] }}</span>
                                </a>
                            @endforeach
                        </div>

                        <div>
                            <p class="sidebar-section-label">Data</p>
                            @php($employeeLink = ['label' => 'Pegawai', 'route' => 'employees.manage', 'icon' => '👥'])
                            @php($isEmployeeActive = request()->routeIs('employees.manage'))
                            <a href="{{ route('employees.manage') }}" class="sidebar-link {{ $isEmployeeActive ? 'active' : '' }}">
                                <span class="text-base">{{ $employeeLink['icon'] }}</span>
                                <span>{{ $employeeLink['label'] }}</span>
                            </a>
                            @php($categoryLink = ['label' => 'Kategori Jam Kerja', 'route' => 'work-schedule-categories.manage', 'icon' => '◷'])
                            @php($isCategoryActive = request()->routeIs('work-schedule-categories.manage'))
                            <a href="{{ route($categoryLink['route']) }}" class="sidebar-link {{ $isCategoryActive ? 'active' : '' }}">
                                <span class="text-base">{{ $categoryLink['icon'] }}</span>
                                <span>{{ $categoryLink['label'] }}</span>
                            </a>
                        </div>

                        <div>
                            <p class="sidebar-section-label">Rekap</p>
                            @foreach ([
                                ['label' => 'Rekap Periode', 'route' => 'rekap.period', 'icon' => '📊'],
                                ['label' => 'Rekap Tahunan', 'route' => 'rekap.annual', 'icon' => '📈'],
                            ] as $link)
                                @php($isActive = request()->routeIs($link['route']))
                                <a href="{{ route($link['route']) }}" class="sidebar-link {{ $isActive ? 'active' : '' }}">
                                    <span class="text-base">{{ $link['icon'] }}</span>
                                    <span>{{ $link['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </nav>

                    <div class="sidebar-footer">
                        <div class="sidebar-user-card">
                            <p class="text-[10px] uppercase tracking-[0.2em] text-slate-400">Admin</p>
                            <p class="mt-1 font-semibold text-white">{{ auth()->user()->name ?? 'Administrator' }}</p>
                        </div>
                    </div>
                </aside>

                <div class="app-main">
                    <header class="app-header">
                        <div class="app-header__inner">
                            <div class="app-header__left">
                                <button
                                    type="button"
                                    x-ref="sidebarToggle"
                                    @click="toggleSidebar()"
                                    :aria-expanded="sidebarOpen"
                                    :aria-label="sidebarOpen ? 'Tutup menu' : 'Buka menu'"
                                    aria-controls="app-sidebar"
                                    class="app-sidebar-toggle lg:hidden"
                                >
                                    <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
                                        <path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm1 4a1 1 0 100 2h12a1 1 0 100-2H4z" clip-rule="evenodd" />
                                    </svg>
                                </button>

                                <a href="{{ route('dashboard') }}" class="app-header__identity" aria-label="Dashboard Khazanah Ilmu">
                                    <span class="app-header__school-logo">
                                        <img
                                            src="{{ asset('images/logo.png') }}"
                                            alt="Lambang Sekolah Dasar Khazanah Ilmu"
                                        >
                                    </span>
                                    <span class="app-header__identity-divider" aria-hidden="true"></span>
                                    <span class="app-header__identity-copy">
                                        <span class="app-header__eyebrow">Institusi Pendidikan Islam</span>
                                        <span class="app-header__name">Khazanah Ilmu</span>
                                    </span>
                                </a>
                            </div>
                            <div class="app-header__right">
                                <div class="app-header__date">
                                    {{ now()->translatedFormat('d M Y') }}
                                </div>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="app-header__logout">
                                        Keluar
                                    </button>
                                </form>
                            </div>
                        </div>
                    </header>

                    <main class="app-content">
                        @if (isset($header))
                            <div class="mb-6 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm sm:px-6">
                                {{ $header }}
                            </div>
                        @endif

                        {{ $slot }}
                    </main>
                </div>
            </div>
        </div>
        @livewireScripts
    </body>
</html>
