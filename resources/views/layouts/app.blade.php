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
        <div class="min-h-screen">
            <livewire:layout.navigation />

            <div class="flex min-h-screen">
                <aside class="hidden w-72 flex-col border-r border-slate-200 bg-slate-900 text-slate-100 lg:flex">
                    <div class="flex items-center gap-3 border-b border-slate-800 px-6 py-5">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-cyan-500/20 text-lg font-bold text-cyan-300">A</div>
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Absensi</p>
                            <h1 class="text-lg font-bold text-white">Admin TK/SD</h1>
                        </div>
                    </div>

                    <nav class="flex-1 space-y-5 px-4 py-6">
                        @php($dashboardLink = ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => '⌂'])
                        @php($isDashboardActive = request()->routeIs('dashboard'))
                        <a href="{{ route('dashboard') }}" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ $isDashboardActive ? 'bg-cyan-500/20 text-white ring-1 ring-cyan-400/30' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                            <span class="text-base">{{ $dashboardLink['icon'] }}</span>
                            <span>{{ $dashboardLink['label'] }}</span>
                        </a>

                        <div>
                            <p class="mb-2 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Absensi</p>
                            @foreach ([
                                ['label' => 'Upload Fingerprint', 'route' => 'fingerprint-import.index', 'icon' => '🖥️'],
                                ['label' => 'Upload Data Izin', 'route' => 'leave-import.index', 'icon' => '📄'],
                                ['label' => 'Kalender Kerja', 'route' => 'work-calendar.index', 'icon' => '📅'],
                            ] as $link)
                                @php($isActive = request()->routeIs($link['route']) || ($link['route'] === 'rekap.period' && request()->routeIs('rekap.employee')))
                                <a href="{{ route($link['route']) }}" class="mt-1 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ $isActive ? 'bg-cyan-500/20 text-white ring-1 ring-cyan-400/30' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                                    <span class="text-base">{{ $link['icon'] }}</span>
                                    <span>{{ $link['label'] }}</span>
                                </a>
                            @endforeach
                        </div>

                        <div>
                            <p class="mb-2 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Data</p>
                            @php($employeeLink = ['label' => 'Pegawai', 'route' => 'employees.manage', 'icon' => '👥'])
                            @php($isEmployeeActive = request()->routeIs('employees.manage'))
                            <a href="{{ route('employees.manage') }}" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ $isEmployeeActive ? 'bg-cyan-500/20 text-white ring-1 ring-cyan-400/30' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                                <span class="text-base">{{ $employeeLink['icon'] }}</span>
                                <span>{{ $employeeLink['label'] }}</span>
                            </a>
                            @php($categoryLink = ['label' => 'Kategori Jam Kerja', 'route' => 'work-schedule-categories.manage', 'icon' => '◷'])
                            @php($isCategoryActive = request()->routeIs('work-schedule-categories.manage'))
                            <a href="{{ route($categoryLink['route']) }}" class="mt-1 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ $isCategoryActive ? 'bg-cyan-500/20 text-white ring-1 ring-cyan-400/30' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                                <span class="text-base">{{ $categoryLink['icon'] }}</span>
                                <span>{{ $categoryLink['label'] }}</span>
                            </a>
                        </div>

                        <div>
                            <p class="mb-2 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Rekap</p>
                            @foreach ([
                                ['label' => 'Rekap Periode', 'route' => 'rekap.period', 'icon' => '📊'],
                                ['label' => 'Rekap Tahunan', 'route' => 'rekap.annual', 'icon' => '📈'],
                            ] as $link)
                                @php($isActive = request()->routeIs($link['route']))
                                <a href="{{ route($link['route']) }}" class="mt-1 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ $isActive ? 'bg-cyan-500/20 text-white ring-1 ring-cyan-400/30' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                                    <span class="text-base">{{ $link['icon'] }}</span>
                                    <span>{{ $link['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </nav>

                    <div class="border-t border-slate-800 px-4 py-4">
                        <div class="rounded-xl bg-slate-800 px-3 py-2">
                            <p class="text-[10px] uppercase tracking-[0.2em] text-slate-400">Admin</p>
                            <p class="mt-1 font-semibold text-white">{{ auth()->user()->name ?? 'Administrator' }}</p>
                        </div>
                    </div>
                </aside>

                <div class="min-w-0 flex-1">
                    <header class="border-b border-slate-200 bg-white/80 backdrop-blur-sm">
                        <div class="flex items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-600">Akses cepat</p>
                                <div class="mt-1 text-lg font-semibold text-slate-900">Panel Administrasi</div>
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

                    <main class="min-w-0 p-4 sm:p-6 lg:p-8">
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
