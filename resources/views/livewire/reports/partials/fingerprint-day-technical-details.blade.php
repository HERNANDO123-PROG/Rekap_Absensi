<details class="min-w-0">
    <summary class="cursor-pointer select-none font-medium text-slate-600 hover:text-slate-900">Tampilkan detail teknis</summary>
    <dl class="mt-3 grid grid-cols-1 gap-3 text-[13px] sm:grid-cols-2 lg:grid-cols-4">
        <div class="min-w-0">
            <dt class="font-medium text-slate-500">Sumber status</dt>
            <dd class="break-words text-slate-800">{{ $sourceLabels[$day['source'] ?? ''] ?? 'Belum tersedia' }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="font-medium text-slate-500">Sumber jadwal</dt>
            <dd class="break-words text-slate-800">{{ $sourceLabels[$day['schedule_source'] ?? ''] ?? 'Belum tersedia' }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="font-medium text-slate-500">Status kandidat</dt>
            <dd class="break-words text-slate-800">{{ $candidateLabels[$day['candidate_status']] ?? ucfirst(str_replace('_', ' ', $day['candidate_status'])) }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="font-medium text-slate-500">Hari kerja</dt>
            <dd class="break-words text-slate-800">{{ $metricDisplay($day['metrics']['hari_kerja']) }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="font-medium text-slate-500">Total fingerprint</dt>
            <dd class="break-words text-slate-800">{{ $metricDisplay($day['metrics']['total_finger']) }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="font-medium text-slate-500">Persetujuan</dt>
            <dd class="break-words text-slate-800">{{ $approvalLabels[$day['leave_approval_status']] ?? ucfirst(str_replace('_', ' ', $day['leave_approval_status'])) }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="font-medium text-slate-500">Verifikasi</dt>
            <dd class="break-words text-slate-800">{{ $verificationLabels[$day['leave_status']] ?? ucfirst(str_replace('_', ' ', $day['leave_status'])) }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="font-medium text-slate-500">Peringatan</dt>
            <dd class="break-words text-slate-800">{{ $day['reason_or_warning'] ? ($warningLabels[$day['reason_or_warning']] ?? ucfirst(str_replace('_', ' ', $day['reason_or_warning']))) : '-' }}</dd>
        </div>
    </dl>
</details>
