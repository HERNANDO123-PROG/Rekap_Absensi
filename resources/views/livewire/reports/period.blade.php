<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-2xl font-bold text-slate-900">Rekap Periode</h2>
                <p class="mt-1 text-sm text-slate-500">Rekap sementara berdasarkan fingerprint yang memenuhi syarat.</p>
            </div>
            <a href="{{ route('rekap.annual') }}" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Rekap Tahunan</a>
        </div>
    </x-slot>

    <livewire:reports.period-report />
</x-app-layout>