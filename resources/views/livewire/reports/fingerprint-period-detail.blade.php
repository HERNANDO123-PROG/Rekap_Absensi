<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Detail Harian Rekap Fingerprint</h2>
            <p class="mt-1 text-sm text-slate-500">{{ $row['name'] }} · {{ $filters['start_date'] }} s/d {{ $filters['end_date'] }}</p>
        </div>
    </x-slot>

    <div class="space-y-5">
        <section class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div>
                <h3 class="font-semibold text-slate-900">{{ $row['name'] }}</h3>
                <p class="text-sm text-slate-600">NIP: {{ $row['nip'] ?: '-' }} · {{ $employee->unit?->code ?? $employee->unit?->name }}</p>
            </div>
            <a href="{{ route('rekap.period', $filters) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Kembali ke rekap</a>
        </section>

        <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
            Nilai “Data belum cukup” tetap null dari mesin hitung, bukan nol. “Perkiraan hari kerja” berasal dari inferensi fingerprint; “Acuan jam kerja tidak lengkap” menandakan jadwal saat ini dipakai sebagai fallback untuk tanggal lampau.
        </section>

        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-[1900px] divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-600">
                        <tr>
                            <th class="px-3 py-3">Tanggal</th>
                            <th class="px-3 py-3">Status hari</th>
                            <th class="px-3 py-3">Sumber status</th>
                            <th class="px-3 py-3">Jadwal</th>
                            <th class="px-3 py-3">Sumber jadwal</th>
                            <th class="px-3 py-3">Masuk terpilih</th>
                            <th class="px-3 py-3">Pulang terpilih</th>
                            <th class="px-3 py-3">Status kandidat</th>
                            <th class="px-3 py-3">Hari Kerja</th>
                            <th class="px-3 py-3">Total Finger</th>
                            <th class="px-3 py-3">Hadir</th>
                            <th class="px-3 py-3">Terlambat</th>
                            <th class="px-3 py-3">Pulang Awal</th>
                            <th class="px-3 py-3">Tidak Hadir</th>
                            <th class="px-3 py-3">Izin Keluar</th>
                            <th class="px-3 py-3">Dinas</th>
                            <th class="px-3 py-3">Izin</th>
                            <th class="px-3 py-3">Persetujuan</th>
                            <th class="px-3 py-3">Verifikasi</th>
                            <th class="px-3 py-3">Peringatan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($row['daily'] as $day)
                            @php
                                $dayStatusLabels = [
                                    'working' => 'Hari kerja',
                                    'non_working' => 'Bukan hari kerja',
                                    'unknown' => 'Tanggal belum dapat dipastikan',
                                ];
                                $sourceLabels = [
                                    'calendar' => 'Kalender Kerja',
                                    'weekly_schedule' => 'Jadwal rutin',
                                    'fingerprint_inference' => 'Inferensi fingerprint',
                                    'current_profile_rule' => 'Aturan profil saat ini',
                                    'current_profile_fallback' => 'Acuan profil saat ini',
                                    'current_category_fallback' => 'Acuan kategori saat ini',
                                ];
                                $candidateLabels = [
                                    'not_evaluated' => 'Belum diperiksa',
                                    'no_fingerprint' => 'Tidak ada fingerprint',
                                    'selected' => 'Kandidat masuk dan pulang dipilih',
                                    'single_scan_assigned' => 'Satu fingerprint ditetapkan ke satu waktu',
                                    'ambiguous_single_scan_role' => 'Peran satu fingerprint belum dapat dipastikan',
                                    'ambiguous_missing_out_candidate' => 'Tidak ada kandidat pulang lain',
                                    'overnight_shift_undetermined' => 'Jadwal lintas tengah malam belum dapat dihitung',
                                    'schedule_unavailable' => 'Acuan jam kerja tidak tersedia',
                                ];
                                $approvalLabels = [
                                    'none' => 'Tidak ada izin',
                                    'pending' => 'Menunggu konfirmasi',
                                    'confirmed' => 'Dikonfirmasi',
                                    'import' => 'Data import',
                                    'conflict' => 'Konflik',
                                ];
                                $verificationLabels = [
                                    'none' => 'Tidak ada izin tercatat',
                                    'verified' => 'Terverifikasi',
                                    'unverifiable' => 'Belum terverifikasi',
                                ];
                                $warningLabels = [
                                    'Status hari belum dapat ditentukan.' => 'Tanggal belum dapat dipastikan karena kalender, jadwal rutin, atau bukti fingerprint belum cukup.',
                                    'Terdapat beberapa izin yang cocok; metrik terkait belum dapat ditentukan.' => 'Terdapat beberapa permohonan izin yang bertentangan; metrik terkait belum dapat ditentukan.',
                                    'Cakupan atau persetujuan izin tidak dapat diverifikasi dari data yang tersedia.' => 'Izin belum terverifikasi karena cakupan atau persetujuannya tidak dapat dipastikan dari data yang tersedia.',
                                    'Kalender resmi menetapkan hari nonkerja.' => 'Kalender Kerja menetapkan tanggal ini sebagai hari nonkerja.',
                                    'ambiguous_single_scan_role' => 'Satu fingerprint memiliki jarak yang sama ke jam masuk dan pulang.',
                                    'ambiguous_missing_out_candidate' => 'Setelah kandidat masuk dipilih, tidak ada kandidat fingerprint lain untuk waktu pulang.',
                                    'overnight_shift_undetermined' => 'Jadwal melewati tengah malam sehingga metrik waktu belum dapat dihitung.',
                                    'schedule_unavailable' => 'Jam kerja yang dapat dipakai belum tersedia.',
                                    'Jam jadwal tidak tersedia atau tidak dapat dipakai.' => 'Acuan jam kerja tidak lengkap atau tidak dapat digunakan untuk menilai waktu.',
                                ];
                                $warning = $day['reason_or_warning'];
                            @endphp
                            <tr wire:key="fingerprint-day-{{ $day['date'] }}" class="align-top hover:bg-slate-50">
                                <td class="whitespace-nowrap px-3 py-3">{{ $day['date'] }}</td>
                                <td class="px-3 py-3">
                                    {{ $dayStatusLabels[$day['day_status']] ?? $day['day_status'] }}
                                    @if ($day['is_provisional']) <span class="mt-1 block text-xs font-medium text-amber-800">Perkiraan hari kerja</span> @endif
                                    @if ($day['is_ambiguous']) <span class="mt-1 block text-xs font-medium text-rose-800">Perlu diperiksa</span> @endif
                                </td>
                                <td class="px-3 py-3">
                                    {{ $sourceLabels[$day['source'] ?? ''] ?? 'Belum tersedia' }}
                                    @if ($day['source']) <code class="block text-xs text-slate-500">{{ $day['source'] }}</code> @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-3">{{ $day['schedule_start'] ?? '—' }} – {{ $day['schedule_end'] ?? '—' }}</td>
                                <td class="px-3 py-3">
                                    {{ $sourceLabels[$day['schedule_source'] ?? ''] ?? 'Belum tersedia' }}
                                    @if ($day['schedule_source']) <code class="block text-xs text-slate-500">{{ $day['schedule_source'] }}</code> @endif
                                    @if ($day['history_limited']) <span class="mt-1 block text-xs font-medium text-amber-800">Acuan jam kerja tidak lengkap</span> @endif
                                </td>
                                <td class="px-3 py-3">{{ $day['selected_in'] ?? '—' }}</td>
                                <td class="px-3 py-3">{{ $day['selected_out'] ?? '—' }}</td>
                                <td class="px-3 py-3">
                                    {{ $candidateLabels[$day['candidate_status']] ?? 'Perlu diperiksa' }}
                                    <code class="block text-xs text-slate-500">{{ $day['candidate_status'] }}</code>
                                </td>
                                @foreach (['hari_kerja', 'total_finger', 'hadir', 'terlambat', 'pulang_awal', 'tidak_hadir', 'izin_keluar', 'dinas'] as $metric)
                                    <td class="px-3 py-3">{{ $day['metrics'][$metric] === null ? 'Data belum cukup' : $day['metrics'][$metric] }}</td>
                                @endforeach
                                <td class="px-3 py-3">{{ $day['leave_types'] === [] ? 'Tidak ada izin tercatat' : implode(', ', $day['leave_types']) }}</td>
                                <td class="px-3 py-3">
                                    {{ $approvalLabels[$day['leave_approval_status']] ?? $day['leave_approval_status'] }}
                                    <code class="block text-xs text-slate-500">{{ $day['leave_approval_status'] }}</code>
                                </td>
                                <td class="px-3 py-3">
                                    {{ $verificationLabels[$day['leave_status']] ?? $day['leave_status'] }}
                                    <code class="block text-xs text-slate-500">{{ $day['leave_status'] }}</code>
                                </td>
                                <td class="min-w-56 px-3 py-3">
                                    @if ($day['is_ambiguous']) <span class="font-semibold text-rose-800">Perlu diperiksa</span>@endif
                                    @if ($day['leave_status'] === 'unverifiable') <span class="block font-medium text-amber-900">Izin belum terverifikasi</span>@endif
                                    <span class="block">{{ $warningLabels[$warning] ?? $warning ?? 'Tidak ada peringatan.' }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
