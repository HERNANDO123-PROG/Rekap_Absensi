<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-600">Import data</p>
                <h2 class="mt-1 text-2xl font-bold text-slate-900">Upload Fingerprint</h2>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('fingerprint-imports.index') }}" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Riwayat import</a>
                <span class="rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold text-violet-700">{{ \App\Models\FingerprintImportBatch::query()->count() }} batch</span>
            </div>
        </div>
    </x-slot>

    @php($reimportBatchId = request('reimport_batch_id'))
    @if ($reimportBatchId)
        @php($batch = \App\Models\FingerprintImportBatch::query()->find($reimportBatchId))
        <div class="mb-5 rounded-xl border border-cyan-200 bg-cyan-50 p-4 text-sm text-cyan-800">
            <p class="font-semibold">Import ulang batch #{{ $batch?->id ?? $reimportBatchId }}</p>
            <p class="mt-1">Periode sebelumnya: {{ $batch?->period_start?->format('d M Y') ?? '-' }} s/d {{ $batch?->period_end?->format('d M Y') ?? '-' }}</p>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[1.3fr_0.7fr]">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Form unggah</h3>
            <form data-import-form data-preview-endpoint="{{ route('fingerprint-imports.preview') }}" data-sync-endpoint="{{ route('fingerprint-imports.sync') }}" data-import-endpoint="{{ route('fingerprint-imports.store') }}" data-preview-ready="false" class="mt-5 space-y-4">
                <div>
                    <label for="fingerprint-unit" class="mb-1 block text-sm font-medium text-slate-700">Unit</label>
                    <select id="fingerprint-unit" name="unit_id" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-slate-800 focus:border-cyan-500 focus:outline-none">
                        @foreach (\App\Models\Unit::query()->orderBy('name')->get() as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->code ?? $unit->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="fingerprint-file" class="mb-1 block text-sm font-medium text-slate-700">File Excel</label>
                    <input id="fingerprint-file" name="file" type="file" accept=".xls,.xlsx" required class="block w-full rounded-xl border border-dashed border-slate-300 bg-slate-50 px-3 py-3 text-sm text-slate-600 file:mr-4 file:rounded-lg file:border-0 file:bg-cyan-600 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white" />
                </div>

                <div class="flex flex-wrap gap-3">
                    <button type="submit" value="preview" class="rounded-xl bg-cyan-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-cyan-800">Preview data</button>
                    <button type="submit" value="sync" class="rounded-xl bg-violet-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-violet-800">Sinkronkan Pegawai</button>
                    <button type="submit" value="import" data-import-confirm class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-emerald-800">Import Data</button>
                    <button type="button" data-replace-file class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Ganti file</button>
                    <button type="reset" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset</button>
                </div>
                <p data-form-status role="status" aria-live="polite" class="text-sm text-slate-600"></p>
                <ul data-import-preview hidden class="space-y-1 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700"></ul>
            </form>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Hasil review</h3>
            <div class="mt-5 space-y-4 text-sm">
                @php($batchStats = \App\Models\FingerprintImportBatch::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'))
                <div class="flex items-center justify-between rounded-xl bg-emerald-50 px-3 py-2">
                    <span class="text-emerald-700">Import selesai</span>
                    <strong class="text-emerald-700">{{ $batchStats->get('completed', 0) }}</strong>
                </div>
                <div class="flex items-center justify-between rounded-xl bg-amber-50 px-3 py-2">
                    <span class="text-amber-700">Menunggu / diproses</span>
                    <strong class="text-amber-700">{{ $batchStats->get('pending', 0) + $batchStats->get('processing', 0) }}</strong>
                </div>
                <div class="flex items-center justify-between rounded-xl bg-rose-50 px-3 py-2">
                    <span class="text-rose-700">Gagal</span>
                    <strong class="text-rose-700">{{ $batchStats->get('failed', 0) }}</strong>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
