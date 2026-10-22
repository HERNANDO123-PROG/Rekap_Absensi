<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-600">Import</p>
                <h2 class="mt-1 text-2xl font-bold text-slate-900">Detail Batch #{{ $batch->id }}</h2>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('fingerprint-imports.index') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Riwayat</a>
                <a href="{{ route('fingerprint-import.index', ['reimport_batch_id' => $batch->id]) }}" class="rounded-xl bg-cyan-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-cyan-800">Import ulang</a>
            </div>
        </div>
    </x-slot>

    @if (session('status'))
        <div role="status" class="mb-6 rounded-xl border border-cyan-200 bg-cyan-50 px-4 py-3 text-sm font-medium text-cyan-900">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Informasi import</h3>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="rounded-xl bg-slate-50 p-3">
                    <dt class="text-[11px] uppercase tracking-[0.16em] text-slate-500">File</dt>
                    <dd class="mt-1 font-semibold text-slate-800">{{ $batch->filename }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <dt class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Unit</dt>
                    <dd class="mt-1 font-semibold text-slate-800">{{ $batch->unit?->code ?? $batch->unit?->name ?? '-' }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <dt class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Periode</dt>
                    <dd class="mt-1 font-semibold text-slate-800">{{ $batch->period_start?->format('d M Y') ?? '-' }} s/d {{ $batch->period_end?->format('d M Y') ?? '-' }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <dt class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Waktu import</dt>
                    <dd class="mt-1 font-semibold text-slate-800">{{ $batch->imported_at?->format('d M Y H:i') ?? '-' }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <dt class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Status</dt>
                    <dd class="mt-1 font-semibold text-slate-800">{{ $batch->status->value ?? $batch->status }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <dt class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Import oleh</dt>
                    <dd class="mt-1 font-semibold text-slate-800">{{ $batch->importedBy?->name ?? '-' }}</dd>
                </div>
            </dl>

            <div class="mt-5 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-center">
                    <p class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Pegawai</p>
                    <p class="mt-2 text-xl font-bold text-slate-900">{{ $summary['employee_count'] }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-center">
                    <p class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Raw scan</p>
                    <p class="mt-2 text-xl font-bold text-slate-900">{{ $summary['raw_scan_count'] }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-center">
                    <p class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Tanggal</p>
                    <p class="mt-2 text-xl font-bold text-slate-900">{{ $summary['unique_dates'] }}</p>
                </div>
            </div>

            @if (($reprocessingSummary = session('reprocessing_summary')) !== null)
                <div class="mt-5 rounded-xl border {{ ($reprocessingSummary['failed'] ?? 0) > 0 || ($reprocessingSummary['review_cases'] ?? []) !== [] ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50' }} p-4">
                    <h4 class="font-semibold text-slate-900">Hasil pemrosesan absensi</h4>
                    <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-4">
                        <div><dt class="text-slate-600">Diproses</dt><dd class="font-semibold">{{ $reprocessingSummary['processed'] ?? 0 }}</dd></div>
                        <div><dt class="text-slate-600">Gagal</dt><dd class="font-semibold">{{ $reprocessingSummary['failed'] ?? 0 }}</dd></div>
                        <div><dt class="text-slate-600">Dilewati (nonaktif)</dt><dd class="font-semibold">{{ $reprocessingSummary['skipped_ineligible_employee'] ?? 0 }}</dd></div>
                        <div><dt class="text-slate-600">Record dipertahankan</dt><dd class="font-semibold">{{ ($reprocessingSummary['preserved_ineligible_attendance'] ?? 0) + ($reprocessingSummary['preserved_for_review'] ?? 0) }}</dd></div>
                    </dl>
                    @if (($reprocessingSummary['review_cases'] ?? []) !== [])
                        <h5 class="mt-4 text-sm font-semibold text-amber-900">Record yang dipertahankan untuk pemeriksaan</h5>
                        <ul class="mt-2 space-y-1 text-sm text-amber-900">
                            @foreach ($reprocessingSummary['review_cases'] as $case)
                                <li>Pegawai #{{ $case['employee_id'] }} · {{ $case['attendance_date'] }} · record #{{ $case['attendance_record_id'] }} · {{ $case['reason'] === 'inactive' ? 'status nonaktif' : 'perlu verifikasi' }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @if (($reprocessingSummary['failed_cases'] ?? []) !== [])
                        <h5 class="mt-4 text-sm font-semibold text-rose-900">Tanggal yang gagal diproses</h5>
                        <ul class="mt-2 space-y-1 text-sm text-rose-900">
                            @foreach ($reprocessingSummary['failed_cases'] as $case)
                                <li>Pegawai #{{ $case['employee_id'] }} · {{ $case['attendance_date'] }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            @if ($summary['historical_verification_note_count'] > 0)
                <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <h4 class="font-semibold text-amber-900">Catatan verifikasi historis ({{ $summary['historical_verification_note_count'] }})</h4>
                    <p class="mt-1 text-sm text-amber-800">Catatan ini dipertahankan sebagai riwayat verifikasi dan tidak menjadi syarat eligibility pemrosesan saat ini.</p>
                    <ul class="mt-3 max-h-64 space-y-2 overflow-y-auto text-sm text-amber-950">
                        @foreach ($historicalVerificationLogs as $log)
                            <li class="rounded-lg bg-white/70 p-2">
                                <span class="font-medium">{{ $log->employee?->displayName() ?? $log->employee_name_raw ?? 'Fingerprint '.$log->fingerprint_id }}</span>
                                <span class="text-amber-800"> · {{ $log->fingerprint_id }} · {{ $log->attendance_date->format('Y-m-d') }} {{ $log->attendance_time }}</span>
                                <p class="text-xs text-amber-800">{{ $log->verification_note }}</p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Aksi</h3>
            <div class="mt-5 space-y-3">
                <form method="POST" action="{{ route('fingerprint-imports.reprocess', $batch) }}">
                    @csrf
                    <button type="submit" class="w-full rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-emerald-800">Sinkronkan &amp; Proses Ulang</button>
                </form>
                <form method="POST" action="{{ route('attendance.process') }}">
                    @csrf
                    <input type="hidden" name="unit_id" value="{{ $batch->unit_id }}">
                    <input type="hidden" name="date_from" value="{{ $batch->period_start?->format('Y-m-d') ?? now()->format('Y-m-d') }}">
                    <input type="hidden" name="date_to" value="{{ $batch->period_end?->format('Y-m-d') ?? now()->format('Y-m-d') }}">
                    <button type="submit" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Proses ulang attendance</button>
                </form>
                @php($calendarMonth = $batch->period_start?->format('Y-m') ?? now()->format('Y-m'))
                <a href="{{ route('work-calendar.index', ['month' => $calendarMonth]) }}" class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-medium text-slate-700 hover:bg-slate-50">Buka kalender</a>
            </div>

            <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm font-semibold text-amber-800">Catatan</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-700">
                    <li>Raw fingerprint tetap dipertahankan sebagai sumber data utama.</li>
                    <li>Import ulang menggunakan file baru untuk data revisi, bukan edit data lama.</li>
                    <li>Attendance dapat diproses ulang dari raw logs tanpa mengupload ulang file Excel.</li>
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
