<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-600">Import</p>
                <h2 class="mt-1 text-2xl font-bold text-slate-900">Riwayat Import Fingerprint</h2>
            </div>
            <a href="{{ route('fingerprint-import.index') }}" class="rounded-xl bg-cyan-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-cyan-800">Upload baru</a>
        </div>
    </x-slot>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex items-center justify-between gap-3">
            <h3 class="text-lg font-semibold text-slate-900">Batch import</h3>
            <span class="rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold text-violet-700">{{ $batches->count() }} batch</span>
        </div>

        @forelse ($batches as $batch)
            @php
                $rawScanCount = $batch->logs()->count();
                $employeeCount = $batch->logs()->distinct('employee_id')->count('employee_id');
            @endphp
            <div class="mb-3 rounded-xl border border-slate-200 p-4">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-slate-900">#{{ $batch->id }} · {{ $batch->filename }}</p>
                        <p class="text-xs text-slate-500">{{ $batch->unit?->code ?? $batch->unit?->name ?? 'Unit' }} · {{ $batch->imported_at?->format('d M Y H:i') ?? '-' }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full {{ $batch->status->value === 'completed' ? 'bg-emerald-100 text-emerald-700' : ($batch->status->value === 'failed' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') }} px-2.5 py-1 text-xs font-semibold uppercase">
                            {{ $batch->status->value ?? $batch->status }}
                        </span>
                        <a href="{{ route('fingerprint-imports.show', $batch) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Lihat detail</a>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg bg-slate-50 p-3">
                        <p class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Periode</p>
                        <p class="mt-1 text-sm font-semibold text-slate-800">{{ $batch->period_start?->format('d M Y') ?? '-' }} s/d {{ $batch->period_end?->format('d M Y') ?? '-' }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3">
                        <p class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Pegawai</p>
                        <p class="mt-1 text-sm font-semibold text-slate-800">{{ $employeeCount }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3">
                        <p class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Raw scan</p>
                        <p class="mt-1 text-sm font-semibold text-slate-800">{{ $rawScanCount }}</p>
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-3 py-8 text-center text-sm text-slate-500">
                Belum ada batch import fingerprint yang tersimpan.
            </div>
        @endforelse
    </div>
</x-app-layout>
