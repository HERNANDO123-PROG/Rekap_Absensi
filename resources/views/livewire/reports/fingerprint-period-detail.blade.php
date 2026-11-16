<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Detail Harian Rekap Fingerprint</h2>
            <p class="mt-1 text-[13px] text-slate-500">{{ $row['name'] }} · {{ $filters['start_date'] }} s/d {{ $filters['end_date'] }}</p>
        </div>
    </x-slot>

    @php
        $dayStatusLabels = [
            'working' => 'Hari kerja',
            'non_working' => 'Bukan hari kerja',
            'unknown' => 'Tanggal belum dapat dipastikan',
        ];
        $sourceLabels = [
            'calendar' => 'Kalender kerja',
            'weekly_schedule' => 'Jadwal rutin',
            'fingerprint_inference' => 'Inferensi fingerprint',
            'current_profile_rule' => 'Aturan profil saat ini',
            'current_profile_fallback' => 'Acuan profil saat ini',
            'current_category_fallback' => 'Jadwal kategori normal',
            'category_normal' => 'Jadwal kategori normal',
        ];
        $candidateLabels = [
            'not_evaluated' => 'Belum diperiksa',
            'no_fingerprint' => 'Tidak ada fingerprint',
            'non_working_day' => 'Hari non-kerja',
            'selected' => 'Dipilih',
            'single_scan_assigned' => 'Satu fingerprint ditetapkan ke satu waktu',
            'ambiguous_single_scan_role' => 'Peran satu fingerprint belum dapat dipastikan',
            'ambiguous_missing_out_candidate' => 'Tidak ada kandidat pulang lain',
            'overnight_shift_undetermined' => 'Jadwal lintas tengah malam belum dapat dihitung',
            'schedule_unavailable' => 'Acuan jam kerja tidak tersedia',
        ];
        $approvalLabels = [
            'none' => 'Tidak ada izin',
            'pending' => 'Menunggu konfirmasi',
            'confirmed' => 'Disetujui',
            'import' => 'Data impor',
            'conflict' => 'Konflik',
        ];
        $verificationLabels = [
            'none' => 'Tidak ada izin tercatat',
            'verified' => 'Terverifikasi',
            'unverifiable' => 'Belum terverifikasi',
        ];
        $leaveTypeLabels = [
            'terlambat' => 'Terlambat',
            'tidak_hadir' => 'Tidak hadir',
            'pulang_awal' => 'Pulang awal',
            'dinas' => 'Dinas',
            'keluar' => 'Izin keluar',
        ];
        $warningLabels = [
            'Status hari belum dapat ditentukan.' => 'Tanggal belum dapat dipastikan karena kalender, jadwal rutin, atau bukti fingerprint belum cukup.',
            'Terdapat beberapa izin yang cocok; metrik terkait belum dapat ditentukan.' => 'Terdapat beberapa permohonan izin yang bertentangan; metrik terkait belum dapat ditentukan.',
            'Cakupan atau persetujuan izin tidak dapat diverifikasi dari data yang tersedia.' => 'Izin belum terverifikasi karena cakupan atau persetujuannya tidak dapat dipastikan dari data yang tersedia.',
            'Kalender resmi menetapkan hari nonkerja.' => 'Kalender kerja menetapkan tanggal ini sebagai hari non-kerja.',
            'ambiguous_single_scan_role' => 'Satu fingerprint memiliki jarak yang sama ke jam masuk dan pulang.',
            'ambiguous_missing_out_candidate' => 'Setelah kandidat masuk dipilih, tidak ada kandidat fingerprint lain untuk waktu pulang.',
            'overnight_shift_undetermined' => 'Jadwal melewati tengah malam sehingga metrik waktu belum dapat dihitung.',
            'schedule_unavailable' => 'Jam kerja yang dapat dipakai belum tersedia.',
            'Jam jadwal tidak tersedia atau tidak dapat dipakai.' => 'Jadwal tidak tersedia atau tidak dapat digunakan untuk menilai waktu.',
        ];
        $metricDisplay = fn ($value) => $value === null
            ? 'Data belum cukup'
            : (is_numeric($value) && (float) $value === 0.0 ? '-' : $value);
    @endphp

    <div class="space-y-4">
        <section class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="min-w-0">
                <h3 class="text-base font-semibold text-slate-900">{{ $row['name'] }}</h3>
                <p class="mt-1 text-[13px] text-slate-600">NIP: {{ $row['nip'] ?: '-' }} · {{ $employee->unit?->code ?? $employee->unit?->name }}</p>
            </div>
            <a href="{{ route('rekap.period', $filters) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-[13px] font-medium text-slate-700 hover:bg-slate-50">Kembali ke rekap</a>
        </section>

        <section class="hidden overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm md:block">
            <table class="w-full table-fixed border-collapse text-left text-[13px]">
                <colgroup>
                    <col style="width: 10%">
                    <col style="width: 9%">
                    <col style="width: 12%">
                    <col style="width: 7%">
                    <col style="width: 7%">
                    <col style="width: 6%">
                    <col style="width: 7%">
                    <col style="width: 7%">
                    <col style="width: 8%">
                    <col style="width: 10%">
                    <col style="width: 17%">
                </colgroup>
                <thead class="bg-slate-50 text-[13px] font-semibold text-slate-700">
                    <tr>
                        <th class="px-2 py-3">Tanggal</th>
                        <th class="px-2 py-3">Status Hari</th>
                        <th class="px-2 py-3">Jadwal</th>
                        <th class="px-2 py-3 text-center">Masuk</th>
                        <th class="px-2 py-3 text-center">Pulang</th>
                        <th class="px-2 py-3 text-center">Hadir</th>
                        <th class="px-2 py-3 text-center">Terlambat</th>
                        <th class="px-2 py-3 text-center">Pulang Awal</th>
                        <th class="px-2 py-3 text-center">Tidak Hadir</th>
                        <th class="px-2 py-3">Izin</th>
                        <th class="px-2 py-3">Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @foreach ($row['daily'] as $day)
                        @php
                            $warning = $day['reason_or_warning'];
                            $metrics = $day['metrics'];
                            $dayStatusClass = match ($day['day_status']) {
                                'working' => 'bg-emerald-100 text-emerald-800',
                                'non_working' => 'bg-slate-100 text-slate-700',
                                default => 'bg-amber-100 text-amber-900',
                            };
                            $leaveTypes = array_map(
                                fn ($type) => $leaveTypeLabels[$type] ?? ucfirst(str_replace('_', ' ', $type)),
                                $day['leave_types'],
                            );
                            $leaveStatuses = [];
                            if ($day['leave_approval_status'] !== 'none') {
                                $leaveStatuses[] = $approvalLabels[$day['leave_approval_status']] ?? ucfirst(str_replace('_', ' ', $day['leave_approval_status']));
                            }
                            if ($day['leave_status'] !== 'none') {
                                $leaveStatuses[] = $verificationLabels[$day['leave_status']] ?? ucfirst(str_replace('_', ' ', $day['leave_status']));
                            }
                            $leaveText = $leaveTypes === [] ? '-' : implode(', ', $leaveTypes);
                            if ($leaveText !== '-' && $leaveStatuses !== []) {
                                $leaveText .= ' ('.implode(' · ', $leaveStatuses).')';
                            }
                            $remarks = [];
                            if ($metrics['izin_keluar'] !== null && $metrics['izin_keluar'] > 0) {
                                $remarks[] = 'Izin keluar: '.$metrics['izin_keluar'];
                            }
                            if ($metrics['dinas'] !== null && $metrics['dinas'] > 0) {
                                $remarks[] = 'Dinas: '.$metrics['dinas'];
                            }
                        @endphp
                        <tr wire:key="fingerprint-day-{{ $day['date'] }}" class="align-middle hover:bg-slate-50">
                            <td class="break-words px-2 py-3 font-medium text-slate-800">
                                <span class="whitespace-nowrap">{{ $day['date'] }}</span>
                                @if ($warning)
                                    <span class="ml-1 inline-flex align-middle text-amber-500" role="img" tabindex="0" title="{{ $warningLabels[$warning] ?? ucfirst(str_replace('_', ' ', $warning)) }}" aria-label="Peringatan: {{ $warningLabels[$warning] ?? ucfirst(str_replace('_', ' ', $warning)) }}">
                                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M9.401 2.562a1.7 1.7 0 0 1 1.198 0c.38.14.686.42.864.782l7.05 14.326A1.7 1.7 0 0 1 16.986 20H3.014a1.7 1.7 0 0 1-1.527-2.33l7.05-14.326c.178-.362.484-.642.864-.782ZM10 7a.75.75 0 0 1 .75.75v4a.75.75 0 0 1-1.5 0v-4A.75.75 0 0 1 10 7Zm0 8.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
                                        </svg>
                                    </span>
                                @endif
                            </td>
                            <td class="break-words px-2 py-3">
                                <span class="inline-flex rounded-full px-2 py-1 font-medium {{ $dayStatusClass }}">
                                    {{ $dayStatusLabels[$day['day_status']] ?? 'Tanggal belum dapat dipastikan' }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-2 py-3 text-slate-700">{{ $day['schedule_start'] ?? '—' }}–{{ $day['schedule_end'] ?? '—' }}</td>
                            <td class="whitespace-nowrap px-2 py-3 text-center">{{ $day['selected_in'] ?? '—' }}</td>
                            <td class="whitespace-nowrap px-2 py-3 text-center">{{ $day['selected_out'] ?? '—' }}</td>
                            <td class="px-2 py-3 text-center">{{ $metricDisplay($metrics['hadir']) }}</td>
                            <td class="rounded-md px-2 py-3 text-center {{ $metrics['terlambat'] !== null && $metrics['terlambat'] > 0 ? 'bg-amber-50 font-semibold text-amber-900' : '' }}">{{ $metricDisplay($metrics['terlambat']) }}</td>
                            <td class="rounded-md px-2 py-3 text-center {{ $metrics['pulang_awal'] !== null && $metrics['pulang_awal'] > 0 ? 'bg-rose-50 font-semibold text-rose-800' : '' }}">{{ $metricDisplay($metrics['pulang_awal']) }}</td>
                            <td class="rounded-md px-2 py-3 text-center {{ $metrics['tidak_hadir'] !== null && $metrics['tidak_hadir'] > 0 ? 'bg-rose-50 font-semibold text-rose-800' : '' }}">{{ $metricDisplay($metrics['tidak_hadir']) }}</td>
                            <td class="break-words px-2 py-3">{{ $leaveText }}</td>
                            <td class="break-words px-2 py-3">
                                @if ($remarks !== [])
                                    {{ implode(' · ', $remarks) }}
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                        <tr class="bg-slate-50/70">
                            <td colspan="11" class="px-3 py-2">
                                @include('livewire.reports.partials.fingerprint-day-technical-details', [
                                    'day' => $day,
                                    'sourceLabels' => $sourceLabels,
                                    'candidateLabels' => $candidateLabels,
                                    'approvalLabels' => $approvalLabels,
                                    'verificationLabels' => $verificationLabels,
                                    'warningLabels' => $warningLabels,
                                    'metricDisplay' => $metricDisplay,
                                ])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="space-y-3 md:hidden">
            @foreach ($row['daily'] as $day)
                @php
                    $warning = $day['reason_or_warning'];
                    $metrics = $day['metrics'];
                    $dayStatusClass = match ($day['day_status']) {
                        'working' => 'bg-emerald-100 text-emerald-800',
                        'non_working' => 'bg-slate-100 text-slate-700',
                        default => 'bg-amber-100 text-amber-900',
                    };
                    $leaveTypes = array_map(
                        fn ($type) => $leaveTypeLabels[$type] ?? ucfirst(str_replace('_', ' ', $type)),
                        $day['leave_types'],
                    );
                    $leaveStatuses = [];
                    if ($day['leave_approval_status'] !== 'none') {
                        $leaveStatuses[] = $approvalLabels[$day['leave_approval_status']] ?? ucfirst(str_replace('_', ' ', $day['leave_approval_status']));
                    }
                    if ($day['leave_status'] !== 'none') {
                        $leaveStatuses[] = $verificationLabels[$day['leave_status']] ?? ucfirst(str_replace('_', ' ', $day['leave_status']));
                    }
                    $leaveText = $leaveTypes === [] ? '-' : implode(', ', $leaveTypes);
                    if ($leaveText !== '-' && $leaveStatuses !== []) {
                        $leaveText .= ' ('.implode(' · ', $leaveStatuses).')';
                    }
                    $remarks = [];
                    if ($metrics['izin_keluar'] !== null && $metrics['izin_keluar'] > 0) {
                        $remarks[] = 'Izin keluar: '.$metrics['izin_keluar'];
                    }
                    if ($metrics['dinas'] !== null && $metrics['dinas'] > 0) {
                        $remarks[] = 'Dinas: '.$metrics['dinas'];
                    }
                @endphp
                <article wire:key="fingerprint-day-card-{{ $day['date'] }}" class="space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm text-[13px]">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="font-semibold text-slate-900">{{ $day['date'] }}</span>
                            @if ($warning)
                                <span class="inline-flex shrink-0 text-amber-500" role="img" tabindex="0" title="{{ $warningLabels[$warning] ?? ucfirst(str_replace('_', ' ', $warning)) }}" aria-label="Peringatan: {{ $warningLabels[$warning] ?? ucfirst(str_replace('_', ' ', $warning)) }}">
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M9.401 2.562a1.7 1.7 0 0 1 1.198 0c.38.14.686.42.864.782l7.05 14.326A1.7 1.7 0 0 1 16.986 20H3.014a1.7 1.7 0 0 1-1.527-2.33l7.05-14.326c.178-.362.484-.642.864-.782ZM10 7a.75.75 0 0 1 .75.75v4a.75.75 0 0 1-1.5 0v-4A.75.75 0 0 1 10 7Zm0 8.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
                                    </svg>
                                </span>
                            @endif
                        </div>
                        <span class="inline-flex shrink-0 rounded-full px-2 py-1 font-medium {{ $dayStatusClass }}">
                            {{ $dayStatusLabels[$day['day_status']] ?? 'Tanggal belum dapat dipastikan' }}
                        </span>
                    </div>

                    <p class="text-slate-600">Jadwal: {{ $day['schedule_start'] ?? '—' }}–{{ $day['schedule_end'] ?? '—' }}</p>

                    <div class="grid grid-cols-2 gap-2">
                        <div class="rounded-lg bg-slate-50 p-3">
                            <span class="block text-slate-500">Masuk</span>
                            <span class="mt-1 block font-semibold text-slate-900">{{ $day['selected_in'] ?? '—' }}</span>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3">
                            <span class="block text-slate-500">Pulang</span>
                            <span class="mt-1 block font-semibold text-slate-900">{{ $day['selected_out'] ?? '—' }}</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div class="rounded-lg p-2 {{ $metrics['terlambat'] !== null && $metrics['terlambat'] > 0 ? 'bg-amber-50 text-amber-900' : 'bg-slate-50 text-slate-700' }}">
                            Terlambat: <strong>{{ $metricDisplay($metrics['terlambat']) }}</strong>
                        </div>
                        <div class="rounded-lg p-2 {{ $metrics['pulang_awal'] !== null && $metrics['pulang_awal'] > 0 ? 'bg-rose-50 text-rose-800' : 'bg-slate-50 text-slate-700' }}">
                            Pulang awal: <strong>{{ $metricDisplay($metrics['pulang_awal']) }}</strong>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-slate-700">
                        <span>Hadir: <strong>{{ $metricDisplay($metrics['hadir']) }}</strong></span>
                        <span class="{{ $metrics['tidak_hadir'] !== null && $metrics['tidak_hadir'] > 0 ? 'font-semibold text-rose-800' : '' }}">Tidak hadir: <strong>{{ $metricDisplay($metrics['tidak_hadir']) }}</strong></span>
                    </div>
                    <p class="break-words text-slate-700"><strong>Izin:</strong> {{ $leaveText }}</p>
                    <p class="break-words text-slate-700"><strong>Keterangan:</strong> {{ $remarks === [] ? '-' : implode(' · ', $remarks) }}</p>

                    @include('livewire.reports.partials.fingerprint-day-technical-details', [
                        'day' => $day,
                        'sourceLabels' => $sourceLabels,
                        'candidateLabels' => $candidateLabels,
                        'approvalLabels' => $approvalLabels,
                        'verificationLabels' => $verificationLabels,
                        'warningLabels' => $warningLabels,
                        'metricDisplay' => $metricDisplay,
                    ])
                </article>
            @endforeach
        </section>
    </div>
</x-app-layout>
