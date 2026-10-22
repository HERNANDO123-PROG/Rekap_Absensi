<div class="space-y-5">
    <form wire:submit="applyFilters" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div>
                <label for="annual-unit" class="mb-1 block text-sm font-medium text-slate-700">Unit</label>
                <select id="annual-unit" wire:model.live="unitId" class="w-full rounded-lg border-slate-300 text-sm focus:border-cyan-600 focus:ring-cyan-600">
                    <option value="all">Semua Unit</option>
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}">{{ $unit->code ?? $unit->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="annual-year" class="mb-1 block text-sm font-medium text-slate-700">Tahun</label>
                <input id="annual-year" type="number" min="2000" max="2100" wire:model="year" class="w-full rounded-lg border-slate-300 text-sm focus:border-cyan-600 focus:ring-cyan-600">
                @error('year') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded-lg bg-cyan-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-800">Tampilkan</button>
            </div>
            <div class="flex items-end">
                @if ($unitId !== 'all')
                    <a href="{{ route('reports.annual.export', ['unit_id' => $unitId, 'year' => $year]) }}" class="w-full rounded-lg border border-emerald-700 px-4 py-2.5 text-center text-sm font-semibold text-emerald-800 hover:bg-emerald-50">Export Excel</a>
                @else
                    <button type="button" disabled title="Pilih satu unit untuk export" class="w-full cursor-not-allowed rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-400">Export Excel</button>
                @endif
            </div>
        </div>
    </form>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="font-semibold text-slate-900">Rekap Absensi {{ $year }}</h3>
                <p class="text-sm text-slate-500">{{ $employees->total() }} pegawai</p>
            </div>
            <div class="w-full sm:max-w-xs">
                <label for="annual-search" class="sr-only">Cari pegawai</label>
                <input id="annual-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nama, NIP, ID fingerprint..." class="w-full rounded-lg border-slate-300 text-sm focus:border-cyan-600 focus:ring-cyan-600">
            </div>
        </div>

        <div wire:loading.flex class="items-center gap-2 border-b border-cyan-100 bg-cyan-50 px-4 py-2 text-sm text-cyan-900">
            <span class="h-4 w-4 animate-spin rounded-full border-2 border-cyan-700 border-r-transparent"></span>
            Memuat rekap...
        </div>

        @if ($employees->isEmpty())
            <p class="p-8 text-center text-sm text-slate-500">Belum ada data absensi untuk filter yang dipilih.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-[1920px] divide-y divide-slate-200 text-left text-sm">
                    <thead class="sticky top-0 bg-slate-50 text-xs font-semibold uppercase text-slate-600">
                        <tr>
                            <th class="sticky left-0 z-10 bg-slate-50 px-4 py-3">Pegawai</th>
                            <th class="px-3 py-3">Unit</th>
                            @foreach (['hari_kerja' => 'Hari Kerja', 'hadir' => 'Hadir', 'terlambat' => 'Terlambat', 'pulang_awal' => 'Pulang Awal', 'total_finger' => 'Total Finger', 'izin' => 'Izin', 'sakit' => 'Sakit', 'dinas' => 'Dinas', 'izin_keluar' => 'Izin Keluar', 'alpha' => 'Alpha', 'perlu_verifikasi' => 'Verifikasi'] as $key => $label)
                                <th class="px-3 py-3 text-right">{{ $label }}</th>
                            @endforeach
                            @foreach ($monthKeys as $label)
                                <th class="px-3 py-3 text-right">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($employees as $row)
                            <tr wire:key="annual-employee-{{ $row['employee_id'] }}" class="hover:bg-slate-50">
                                <td class="sticky left-0 z-10 whitespace-nowrap bg-white px-4 py-3 hover:bg-slate-50">
                                    <a href="{{ route('rekap.employee', ['employee' => $row['employee_id'], 'unit_id' => $row['unit_id'], 'start_date' => $year.'-01-01', 'end_date' => $year.'-12-31']) }}" class="font-semibold text-cyan-800 hover:underline">{{ $row['name'] }}</a>
                                    <p class="text-xs text-slate-500">NIP: {{ $row['nip'] ?: '-' }}</p>
                                </td>
                                <td class="px-3 py-3 text-slate-600">{{ $row['unit'] }}</td>
                                @foreach (['hari_kerja', 'hadir', 'terlambat', 'pulang_awal', 'total_finger', 'izin', 'sakit', 'dinas', 'izin_keluar', 'alpha', 'perlu_verifikasi'] as $metric)
                                    <td class="px-3 py-3 text-right tabular-nums text-slate-700">{{ $row['annual'][$metric] ?? 0 }}</td>
                                @endforeach
                                @foreach ($monthKeys as $month => $label)
                                    <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums text-slate-700">
                                        @foreach (['hari_kerja' => 'Hari Kerja', 'hadir' => 'Hadir', 'terlambat' => 'Terlambat', 'pulang_awal' => 'Pulang Awal', 'total_finger' => 'Total Finger'] as $metric => $metricLabel)
                                            <span class="block" title="{{ $metricLabel }}">{{ $row['monthly'][$month][$metric] ?? 0 }} <span class="text-xs text-slate-400">{{ $metricLabel }}</span></span>
                                        @endforeach
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">Setiap kolom bulan menampilkan hari kerja, hadir, terlambat, pulang awal, dan total fingerprint endpoint.</p>
            <div class="border-t border-slate-200 px-4 py-3">{{ $employees->links() }}</div>
        @endif
    </section>
</div>