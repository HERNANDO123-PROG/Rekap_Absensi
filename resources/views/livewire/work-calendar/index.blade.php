@php
    $selectedMonth = request('month', now()->format('Y-m'));
    $selectedDate = \Carbon\CarbonImmutable::createFromFormat('Y-m', $selectedMonth)->startOfMonth();
    $nextMonth = $selectedDate->copy()->addMonth();
    $previousMonth = $selectedDate->copy()->subMonth();
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-600">Kalender</p>
                <h2 class="mt-1 text-2xl font-bold text-slate-900">Kalender Kerja</h2>
            </div>
            <div class="flex items-center gap-2"><span class="rounded-full bg-pink-100 px-3 py-1 text-xs font-semibold text-pink-700">{{ \App\Models\WorkCalendar::query()->count() }} agenda</span><a href="{{ route('work-schedule.index') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Jadwal Mingguan</a></div>
        </div>
    </x-slot>

    <div class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Periode kalender</p>
                <h3 class="mt-1 text-lg font-semibold text-slate-900">{{ $selectedDate->translatedFormat('F Y') }}</h3>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('work-calendar.index', ['month' => $previousMonth->format('Y-m')]) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Sebelumnya</a>
                <a href="{{ route('work-calendar.index', ['month' => $nextMonth->format('Y-m')]) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Berikutnya</a>
            </div>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1.4fr_0.6fr]">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Agenda mendatang</h3>
            <div class="mt-5 space-y-3">
                @php($calendarEntries = \App\Models\WorkCalendar::query()->with('unit')->whereBetween('date', [$selectedDate->format('Y-m-01'), $selectedDate->copy()->endOfMonth()->format('Y-m-d')])->orderBy('date')->limit(10)->get())
                @forelse ($calendarEntries as $entry)
                    <div class="flex items-center justify-between rounded-xl border border-slate-200 px-3 py-3">
                        <div>
                            <p class="font-semibold text-slate-900">{{ $entry->title ?: 'Agenda kerja' }}</p>
                            <p class="text-xs text-slate-500">{{ $entry->unit?->code ?? $entry->unit?->name }} · {{ $entry->date?->format('d M Y') }}</p>
                        </div>
                        <span class="rounded-full {{ $entry->type->value === 'holiday' ? 'bg-pink-100 text-pink-700' : 'bg-sky-100 text-sky-700' }} px-2.5 py-1 text-xs font-semibold">
                            {{ $entry->type->value ?? 'workday' }}
                        </span>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-center text-sm text-slate-500">Belum ada agenda kerja.</div>
                @endforelse
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Tambah agenda</h3>
            <form data-api-form data-endpoint="{{ route('work-calendars.store') }}" data-reload-on-success="true" class="mt-5 space-y-4 text-sm">
                <div>
                    <label for="calendar-unit" class="mb-1 block text-sm font-medium text-slate-700">Unit</label>
                    <select id="calendar-unit" name="unit_id" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-slate-800 focus:border-cyan-500 focus:outline-none">
                        @foreach (\App\Models\Unit::query()->orderBy('name')->get() as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->code ?? $unit->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="calendar-date" class="mb-1 block text-sm font-medium text-slate-700">Tanggal</label>
                    <input id="calendar-date" name="date" type="date" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-slate-800 focus:border-cyan-500 focus:outline-none" />
                </div>
                <div>
                    <label for="calendar-type" class="mb-1 block text-sm font-medium text-slate-700">Jenis</label>
                    <select id="calendar-type" name="type" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-slate-800 focus:border-cyan-500 focus:outline-none">
                        <option value="holiday">Libur</option>
                        <option value="workday">Hari kerja</option>
                        <option value="special_workday">Hari kerja khusus</option>
                        <option value="special_event">Acara khusus</option>
                    </select>
                </div>
                <div>
                    <label for="calendar-title" class="mb-1 block text-sm font-medium text-slate-700">Nama agenda</label>
                    <input id="calendar-title" name="title" type="text" maxlength="255" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-slate-800 focus:border-cyan-500 focus:outline-none" />
                </div>
                <input name="is_working_day" type="hidden" value="0" />
                <label class="flex items-center gap-2 text-sm text-slate-700"><input name="is_working_day" type="checkbox" value="1" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-600" /> Hari kerja</label>
                <div class="grid grid-cols-2 gap-3">
                    <div><label for="calendar-start" class="mb-1 block text-sm font-medium text-slate-700">Mulai</label><input id="calendar-start" name="start_time" type="time" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5" /></div>
                    <div><label for="calendar-end" class="mb-1 block text-sm font-medium text-slate-700">Selesai</label><input id="calendar-end" name="end_time" type="time" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5" /></div>
                </div>
                <button type="submit" class="w-full rounded-xl bg-pink-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-pink-800">Simpan agenda</button>
                <p data-form-status role="status" aria-live="polite" class="text-sm text-slate-600"></p>
            </form>
        </div>
    </div>
</x-app-layout>
