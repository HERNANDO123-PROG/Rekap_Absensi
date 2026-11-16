<x-app-layout>
    <x-slot name="header">
        <div class="dashboard-heading">
            <div>
                <p class="dashboard-heading__eyebrow">Ringkasan</p>
                <h2 class="dashboard-heading__title">Dashboard</h2>
            </div>
            <span class="dashboard-heading__status"><span aria-hidden="true"></span>Sistem aktif</span>
        </div>
    </x-slot>

    <livewire:dashboard />
</x-app-layout>
