<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-slate-100 text-slate-800">
        <div x-data="{ sidebarOpen: false }" class="min-h-screen bg-slate-100">
            <div class="lg:hidden">
                <livewire:layout.navigation />
            </div>

            <div class="app-shell">
                <div x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false" class="fixed inset-0 z-30 hidden bg-slate-900/45 lg:hidden" aria-hidden="true"></div>

                <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'" class="app-sidebar">
                    <div class="flex items-center gap-3 border-b border-slate-800 px-6 py-5">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-cyan-500/20 text-lg font-bold text-cyan-300">A</div>
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Absensi</p>
                            <h1 class="text-lg font-bold text-white">Admin TK/SD</h1>
                        </div>
                    </div>

                    <nav class="sidebar-nav">
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
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <button type="button" @click="sidebarOpen = !sidebarOpen" class="app-sidebar-toggle lg:hidden" aria-label="Buka menu navigasi">
                                    <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
                                        <path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm1 4a1 1 0 100 2h12a1 1 0 100-2H4z" clip-rule="evenodd" />
                                    </svg>
                                </button>

                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-600">Akses cepat</p>
                                    <div class="mt-1 text-lg font-semibold text-slate-900">Panel Administrasi</div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3">
                                <div class="hidden rounded-full bg-slate-100 px-3 py-1.5 text-sm text-slate-600 sm:block">
                                    {{ now()->translatedFormat('d M Y') }}
                                </div>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700">
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
    </body>
</html>
