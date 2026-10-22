<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-2xl font-bold text-slate-900">Rekap Tahunan</h2>
                <p class="mt-1 text-sm text-slate-500">Ringkasan absensi pegawai selama satu tahun</p>
            </div>
            <a href="{{ route('rekap.period') }}" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Rekap Periode</a>
        </div>
    </x-slot>

    <livewire:reports.annual-report />
</x-app-layout>