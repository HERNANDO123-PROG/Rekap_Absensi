<div class="space-y-6">
    <p class="text-gray-900">Fondasi absensi siap.</p>

    <form method="GET" action="{{ route('dashboard') }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4 md:flex-row md:items-end">
            <div class="flex-1">
                <label for="unit_id" class="mb-1 block text-sm font-medium text-slate-700">Unit</label>
                <select id="unit_id" name="unit_id" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-slate-800 focus:border-cyan-500 focus:outline-none">
                    <option value="all" {{ ($selectedUnitId ?? 'all') === 'all' ? 'selected' : '' }}>Semua Unit</option>
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}" {{ (string) ($selectedUnitId ?? 'all') === (string) $unit->id ? 'selected' : '' }}>{{ $unit->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex-1">
                <label for="date" class="mb-1 block text-sm font-medium text-slate-700">Tanggal</label>
                <input id="date" name="date" type="date" value="{{ $selectedDate }}" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-slate-800 focus:border-cyan-500 focus:outline-none" />
            </div>

            <button type="submit" class="rounded-xl bg-cyan-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-cyan-500">Terapkan</button>
        </div>
    </form>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Total Pegawai Aktif</p>
            <div class="mt-3 flex items-end justify-between">
                <span class="text-3xl font-bold text-slate-900">{{ $stats['totalEmployees'] }}</span>
                <span class="rounded-full bg-cyan-100 px-2 py-1 text-xs font-semibold text-cyan-700">{{ $selectedUnitName }}</span>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Hadir</p>
            <div class="mt-3 flex items-end justify-between">
                <span class="text-3xl font-bold text-slate-900">{{ $stats['hadir'] }}</span>
                <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold text-emerald-700">Hari ini</span>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Terlambat</p>
            <div class="mt-3 flex items-end justify-between">
                <span class="text-3xl font-bold text-slate-900">{{ $stats['terlambat'] }}</span>
                <span class="rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-700">Cek</span>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Izin</p>
            <div class="mt-3 flex items-end justify-between">
                <span class="text-3xl font-bold text-slate-900">{{ $stats['izin'] }}</span>
                <span class="rounded-full bg-sky-100 px-2 py-1 text-xs font-semibold text-sky-700">Status</span>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Sakit</p>
            <div class="mt-3 flex items-end justify-between">
                <span class="text-3xl font-bold text-slate-900">{{ $stats['sakit'] }}</span>
                <span class="rounded-full bg-violet-100 px-2 py-1 text-xs font-semibold text-violet-700">Status</span>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Dinas</p>
            <div class="mt-3 flex items-end justify-between">
                <span class="text-3xl font-bold text-slate-900">{{ $stats['dinas'] }}</span>
                <span class="rounded-full bg-indigo-100 px-2 py-1 text-xs font-semibold text-indigo-700">Status</span>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Alpha</p>
            <div class="mt-3 flex items-end justify-between">
                <span class="text-3xl font-bold text-slate-900">{{ $stats['alpha'] }}</span>
                <span class="rounded-full bg-rose-100 px-2 py-1 text-xs font-semibold text-rose-700">Status</span>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Perlu Verifikasi</p>
            <div class="mt-3 flex items-end justify-between">
                <span class="text-3xl font-bold text-slate-900">{{ $stats['perlu_verifikasi'] }}</span>
                <span class="rounded-full bg-rose-100 px-2 py-1 text-xs font-semibold text-rose-700">Audit</span>
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex items-center justify-between gap-3">
            <h3 class="text-lg font-semibold text-slate-900">Akses Cepat</h3>
            <span class="text-sm text-slate-500">Menu utama</span>
        </div>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            @foreach ([
                ['label' => 'Upload Fingerprint', 'href' => route('fingerprint-import.index'), 'tone' => 'bg-cyan-50 text-cyan-700'],
                ['label' => 'Upload Data Izin', 'href' => route('leave-import.index'), 'tone' => 'bg-emerald-50 text-emerald-700'],
                ['label' => 'Pegawai', 'href' => route('employees.manage'), 'tone' => 'bg-violet-50 text-violet-700'],
                ['label' => 'Kalender Kerja', 'href' => route('work-calendar.index'), 'tone' => 'bg-pink-50 text-pink-700'],
                ['label' => 'Rekap Periode', 'href' => route('rekap.period'), 'tone' => 'bg-slate-100 text-slate-700'],
            ] as $item)
                <a href="{{ $item['href'] }}" class="rounded-xl border border-slate-200 px-3 py-3 text-sm font-medium transition hover:bg-slate-50 {{ $item['tone'] }}">
                    {{ $item['label'] }}
                </a>
            @endforeach
        </div>
    </div>
</div>
