<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-600">Ringkasan</p>
                <h2 class="mt-1 text-2xl font-bold text-slate-900">Dashboard</h2>
            </div>
            <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">Sistem aktif</span>
        </div>
    </x-slot>

    <livewire:dashboard />
</x-app-layout>
