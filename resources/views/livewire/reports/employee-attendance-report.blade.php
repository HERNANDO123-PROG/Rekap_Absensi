<div class="space-y-5">
    <section class="flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-start sm:justify-between sm:p-5">
        <div>
            <h3 class="text-xl font-bold text-slate-900">{{ $employee->displayName() }}</h3>
            <p class="mt-1 text-sm text-slate-600">{{ $employee->unit?->code ?? $employee->unit?->name }} <span class="px-1 text-slate-300">•</span> {{ $employee->position ?: 'Jabatan tidak tersedia' }}</p>
            <dl class="mt-4 grid grid-cols-2 gap-x-8 gap-y-2 text-sm sm:grid-cols-4">
                <div><dt class="text-xs text-slate-500">NIP</dt><dd class="font-medium text-slate-800">{{ $employee->nip ?: '-' }}</dd></div>
                <div><dt class="text-xs text-slate-500">ID Pegawai</dt><dd class="font-medium text-slate-800">{{ $employee->id }}</dd></div>
                <div><dt class="text-xs text-slate-500">Status Pegawai</dt><dd class="font-medium text-slate-800">{{ $employee->status?->value === 'active' ? 'Aktif' : 'Nonaktif' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Periode</dt><dd class="font-medium text-slate-800">{{ $startDate }} s/d {{ $endDate }}</dd></div>
            </dl>
        </div>
        <div class="flex shrink-0 gap-2">
            <a href="{{ route('rekap.period') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Kembali</a>
            <a href="{{ route('reports.employee.export', ['employee' => $employee->id, 'unit_id' => $employee->unit_id, 'start_date' => $startDate, 'end_date' => $endDate]) }}" class="rounded-lg bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Export Excel</a>
        </div>
    </section>

    <form wire:submit="applyFilters" class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-end">
        <div class="flex-1">
            <label for="detail-start" class="mb-1 block text-sm font-medium text-slate-700">Tanggal Mulai</label>
            <input id="detail-start" type="date" wire:model="startDate" class="w-full rounded-lg border-slate-300 text-sm">
            @error('startDate') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
        </div>
        <div class="flex-1">
            <label for="detail-end" class="mb-1 block text-sm font-medium text-slate-700">Tanggal Selesai</label>
            <input id="detail-end" type="date" wire:model="endDate" class="w-full rounded-lg border-slate-300 text-sm">
            @error('endDate') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="rounded-lg bg-cyan-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-800">Tampilkan</button>
    </form>

    <div wire:loading.flex class="items-center gap-2 rounded-lg border border-cyan-100 bg-cyan-50 px-4 py-3 text-sm text-cyan-900">
        <span class="h-4 w-4 animate-spin rounded-full border-2 border-cyan-700 border-r-transparent"></span>
        Memuat detail...
    </div>

    @if ($validRange)
        <section class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
            @foreach ([
                'hadir' => 'Hadir', 'terlambat' => 'Terlambat', 'pulang_awal' => 'Pulang Awal',
                'izin' => 'Izin', 'sakit' => 'Sakit', 'dinas' => 'Dinas', 'izin_keluar' => 'Izin Keluar',
                'alpha' => 'Alpha', 'perlu_verifikasi' => 'Perlu Verifikasi',
            ] as $metric => $label)
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs text-slate-500">{{ $label }}</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-slate-900">{{ $summary[$metric] ?? 0 }}</p>
                </div>
            @endforeach
        </section>

        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-4 py-3"><h3 class="font-semibold text-slate-900">Data Harian</h3></div>
            @if (empty($detail['daily']))
                <p class="p-6 text-center text-sm text-slate-500">Belum ada data absensi untuk filter yang dipilih.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-[900px] divide-y divide-slate-200 text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-600">
                            <tr><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Jadwal</th><th class="px-4 py-3">Masuk</th><th class="px-4 py-3">Pulang</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Terlambat</th><th class="px-4 py-3 text-right">Pulang Awal</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($detail['daily'] as $day)
                                <tr wire:key="day-{{ $day['date'] }}" class="hover:bg-slate-50">
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ \Carbon\CarbonImmutable::parse($day['date'])->translatedFormat('d M Y') }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $day['scheduled_in'] ? substr($day['scheduled_in'], 0, 5) : '--:--' }} - {{ $day['scheduled_out'] ? substr($day['scheduled_out'], 0, 5) : '--:--' }}</td>
                                    <td class="px-4 py-3 text-slate-700">{{ $day['actual_in'] ? substr($day['actual_in'], 0, 5) : '--:--' }}</td>
                                    <td class="px-4 py-3 text-slate-700">{{ $day['actual_out'] ? substr($day['actual_out'], 0, 5) : '--:--' }}</td>
                                    <td class="px-4 py-3">
                                        @include('livewire.reports.partials.status-badge', ['status' => $day['is_holiday'] ? 'libur' : $day['status']])
                                        @if ($day['activity_on_holiday']) <span class="ml-1 text-xs text-slate-500">Ada aktivitas</span> @endif
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ $day['late_minutes'] }} mnt</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ $day['early_leave_minutes'] }} mnt</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @php
            $exitIntervals = collect($detail['daily'])->flatMap(fn ($day) => collect($day['intervals'])
                ->filter(fn ($interval) => $interval['type'] === 'izin_keluar')
                ->map(fn ($interval) => $interval + ['date' => $day['date']]))->values();
            $anomalies = collect($detail['daily'])->flatMap(fn ($day) => collect($day['anomalies'])
                ->map(fn ($anomaly) => $anomaly + ['date' => $day['date']]))->values();
        @endphp

        @if ($exitIntervals->isNotEmpty())
            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-4 py-3"><h3 class="font-semibold text-slate-900">Riwayat Izin Keluar</h3></div>
                <div class="overflow-x-auto">
                    <table class="min-w-[720px] divide-y divide-slate-200 text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-600"><tr><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Mulai</th><th class="px-4 py-3">Selesai</th><th class="px-4 py-3">Durasi</th><th class="px-4 py-3">Sumber</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($exitIntervals as $interval)
                                <tr><td class="px-4 py-3">{{ $interval['date'] }}</td><td class="px-4 py-3">{{ $interval['start_time'] ?? '--:--' }}</td><td class="px-4 py-3">{{ $interval['end_time'] ?? 'Masih terbuka' }}</td><td class="px-4 py-3">{{ $interval['duration_minutes'] }} menit</td><td class="px-4 py-3">{{ $interval['source'] ?? '-' }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if ($anomalies->isNotEmpty())
            <section class="overflow-hidden rounded-xl border border-amber-200 bg-white shadow-sm">
                <div class="border-b border-amber-100 px-4 py-3"><h3 class="font-semibold text-slate-900">Perlu Verifikasi</h3></div>
                <div class="overflow-x-auto">
                    <table class="min-w-[760px] divide-y divide-slate-200 text-left text-sm">
                        <thead class="bg-amber-50 text-xs font-semibold uppercase text-slate-600"><tr><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Jenis Anomali</th><th class="px-4 py-3">Pesan</th><th class="px-4 py-3">Severity</th><th class="px-4 py-3">Status</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($anomalies as $anomaly)
                                <tr><td class="px-4 py-3">{{ $anomaly['date'] }}</td><td class="px-4 py-3">{{ str($anomaly['type'])->replace('_', ' ')->title() }}</td><td class="px-4 py-3">{{ $anomaly['message'] }}</td><td class="px-4 py-3">{{ $anomaly['severity'] }}</td><td class="px-4 py-3">{{ $anomaly['is_resolved'] ? 'Selesai' : 'Belum selesai' }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @else
        <p class="rounded-xl border border-rose-200 bg-white p-5 text-sm text-rose-700">Rentang tanggal tidak valid.</p>
    @endif
</div>