<div class="space-y-5">
    <form wire:submit="applyFilters" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
            <div>
                <label for="period-unit" class="mb-1 block text-sm font-medium text-slate-700">Unit <span class="text-rose-600">*</span></label>
                <select id="period-unit" wire:model="unitId" required class="w-full rounded-lg border-slate-300 text-sm focus:border-cyan-600 focus:ring-cyan-600">
                    <option value="">Pilih unit</option>
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}">{{ $unit->code ?? $unit->name }}</option>
                    @endforeach
                </select>
                @error('unitId') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="period-start" class="mb-1 block text-sm font-medium text-slate-700">Tanggal mulai <span class="text-rose-600">*</span></label>
                <input id="period-start" type="date" wire:model="startDate" required class="w-full rounded-lg border-slate-300 text-sm">
                @error('startDate') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="period-end" class="mb-1 block text-sm font-medium text-slate-700">Tanggal selesai <span class="text-rose-600">*</span></label>
                <input id="period-end" type="date" wire:model="endDate" required class="w-full rounded-lg border-slate-300 text-sm">
                @error('endDate') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="period-category" class="mb-1 block text-sm font-medium text-slate-700">Kategori</label>
                <select id="period-category" wire:model="categoryId" class="w-full rounded-lg border-slate-300 text-sm">
                    <option value="">Semua kategori</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="period-search" class="mb-1 block text-sm font-medium text-slate-700">Nama atau NIP</label>
                <input id="period-search" type="search" wire:model="search" placeholder="Cari pegawai..." class="w-full rounded-lg border-slate-300 text-sm">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded-lg bg-cyan-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-800">Tampilkan</button>
            </div>
        </div>
    </form>

    <section class="rounded-xl border border-cyan-200 bg-cyan-50 p-4 text-sm text-cyan-950">
        <p>Rekap dihitung berdasarkan data fingerprint dan pengaturan jam kerja yang tersedia. Sebagian hasil mungkin belum dapat dipastikan jika status hari, jam kerja, atau data pendukung belum lengkap.</p>
        <p class="mt-2">Kalender Kerja digunakan untuk mengatur tanggal khusus, seperti libur atau perubahan jam kerja. Admin tidak harus mengisi setiap hari jika jadwal rutin sudah mencukupi.</p>
    </section>

    @if ($report)
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950" role="note">
            <p><strong>Catatan:</strong> angka “Data belum cukup” bernilai null pada mesin hitung dan tidak dianggap nol. “Perkiraan hari kerja” berasal dari inferensi fingerprint; “Acuan jam kerja tidak lengkap” berarti jadwal saat ini dipakai sebagai fallback untuk tanggal lampau.</p>
            <p class="mt-1">Tanggal belum dapat dipastikan: {{ $report['summary']['unknown_date_count'] }}. Perkiraan hari kerja: {{ $report['summary']['provisional_day_count'] }}. Kasus “Perlu diperiksa” pada pegawai di halaman ini: {{ $report['summary']['page_ambiguous_employee_day_count'] }}.</p>
        </section>

        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="font-semibold text-slate-900">Rekap Fingerprint</h3>
                    <p class="text-sm text-slate-500">{{ $report['pagination']['total'] }} pegawai sesuai filter</p>
                </div>
                <a href="{{ route('reports.fingerprint-period.export', $filterValues) }}" class="rounded-lg border border-emerald-700 px-4 py-2 text-center text-sm font-semibold text-emerald-800 hover:bg-emerald-50">Ekspor seluruh hasil</a>
            </div>

            <div wire:loading.flex class="items-center gap-2 border-b border-cyan-100 bg-cyan-50 px-4 py-2 text-sm text-cyan-900">
                <span class="h-4 w-4 animate-spin rounded-full border-2 border-cyan-700 border-r-transparent"></span>
                Memuat rekap...
            </div>

            @if (count($report['data']) === 0)
                <p class="p-8 text-center text-sm text-slate-500">Tidak ada pegawai yang cocok dengan filter.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-[1500px] divide-y divide-slate-200 text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-600">
                            <tr>
                                <th class="sticky left-0 z-10 bg-slate-50 px-4 py-3">Pegawai</th>
                                <th class="px-3 py-3">Kategori</th>
                                @foreach ([
                                    'hari_kerja' => 'Hari Kerja', 'total_finger' => 'Total Finger',
                                    'hadir' => 'Hadir', 'terlambat' => 'Terlambat',
                                    'pulang_awal' => 'Pulang Awal', 'tidak_hadir' => 'Tidak Hadir',
                                    'izin_keluar' => 'Izin Keluar', 'dinas' => 'Dinas',
                                ] as $metric => $label)
                                    <th class="px-3 py-3 text-right">{{ $label }}</th>
                                @endforeach
                                <th class="px-4 py-3">Rincian</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($report['data'] as $row)
                                <tr wire:key="period-employee-{{ $row['employee_id'] }}" class="hover:bg-slate-50">
                                    <td class="sticky left-0 z-10 whitespace-nowrap bg-white px-4 py-3">
                                        <p class="font-semibold text-slate-900">{{ $row['name'] }}</p>
                                        <p class="text-xs text-slate-500">NIP: {{ $row['nip'] ?: '-' }}</p>
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @if ($row['is_provisional']) <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900">Perkiraan hari kerja</span> @endif
                                            @if ($row['is_ambiguous']) <span class="rounded bg-rose-100 px-1.5 py-0.5 text-xs text-rose-900">Perlu diperiksa</span> @endif
                                            @if ($row['history_limited']) <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700">Acuan jam kerja tidak lengkap</span> @endif
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 text-slate-700">{{ $row['category_name'] ?? 'Tidak tersedia' }}</td>
                                    @foreach (['hari_kerja', 'total_finger', 'hadir', 'terlambat', 'pulang_awal', 'tidak_hadir', 'izin_keluar', 'dinas'] as $metric)
                                        <td class="px-3 py-3 text-right tabular-nums {{ $row[$metric] === null ? 'text-amber-800' : 'text-slate-700' }}">
                                            {{ $row[$metric] === null ? 'Data belum cukup' : $row[$metric] }}
                                        </td>
                                    @endforeach
                                    <td class="px-4 py-3">
                                        <a href="{{ route('rekap.period.employee', ['employee' => $row['employee_id'], ...$filterValues]) }}" class="font-medium text-cyan-800 hover:underline">Lihat harian</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-sm">
                <span class="text-slate-600">Halaman {{ $report['pagination']['current_page'] }} dari {{ $report['pagination']['last_page'] }}</span>
                <div class="flex gap-2">
                    <button type="button" wire:click="previousPage" @disabled($report['pagination']['current_page'] <= 1) class="rounded-lg border border-slate-300 px-3 py-1.5 font-medium text-slate-700 disabled:cursor-not-allowed disabled:opacity-50">Sebelumnya</button>
                    <button type="button" wire:click="nextPage({{ $report['pagination']['last_page'] }})" @disabled($report['pagination']['current_page'] >= $report['pagination']['last_page']) class="rounded-lg border border-slate-300 px-3 py-1.5 font-medium text-slate-700 disabled:cursor-not-allowed disabled:opacity-50">Berikutnya</button>
                </div>
            </div>
        </section>
    @else
        <section class="rounded-xl border border-cyan-200 bg-cyan-50 p-5 text-sm text-cyan-950" role="status">
            Pilih unit dan rentang tanggal, lalu tekan <strong>Tampilkan</strong> untuk memuat rekap fingerprint.
        </section>
    @endif
</div>
