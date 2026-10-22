<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Detail Absensi Pegawai</h2>
            <p class="mt-1 text-sm text-slate-500">Riwayat absensi dan verifikasi per pegawai</p>
        </div>
    </x-slot>

    <livewire:reports.employee-attendance-report :employee-id="$employeeId" />
</x-app-layout>