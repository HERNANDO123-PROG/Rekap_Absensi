@php
    $selectedMonth = request('month', now()->format('Y-m'));
    $selectedDate = \Carbon\CarbonImmutable::createFromFormat('Y-m', $selectedMonth)->startOfMonth();
    $nextMonth = $selectedDate->copy()->addMonth();
    $previousMonth = $selectedDate->copy()->subMonth();
    $unitId = request('unit_id');
    $units = \App\Models\Unit::query()->whereIn('code', ['TK', 'SD'])->orderBy('code')->get();
    $calendarEntries = \App\Models\WorkCalendar::query()
        ->with('unit')
        ->whereBetween('date', [$selectedDate->format('Y-m-01'), $selectedDate->copy()->endOfMonth()->format('Y-m-d')])
        ->when($unitId, fn ($query) => $query->where('unit_id', $unitId))
        ->orderBy('date')
        ->get()
        ->groupBy(fn ($entry) => $entry->date->format('Y-m-d'));
    $agendaEntries = \App\Models\WorkCalendar::query()
        ->with('unit')
        ->whereYear('date', $selectedDate->year)
        ->when($unitId, fn ($query) => $query->where('unit_id', $unitId))
        ->orderBy('date')
        ->get();
    $groupCalendarEntries = fn ($entries) => $entries->groupBy(fn ($entry) => json_encode([
        $entry->date->format('Y-m-d'),
        $entry->national_calendar_event_id,
        $entry->type->value,
        $entry->title,
        $entry->description,
        $entry->is_working_day,
        $entry->start_time,
        $entry->end_time,
    ], JSON_THROW_ON_ERROR));
    $agendaGroups = $groupCalendarEntries($agendaEntries);
    $nationalEvents = \App\Models\NationalCalendarEvent::query()
        ->where('country_code', 'ID')
        ->whereYear('date', $selectedDate->year)
        ->withCount('workCalendarEntries')
        ->orderBy('date')
        ->get();
    $calendarDays = [];
    $firstCalendarDay = $selectedDate->startOfWeek(\Carbon\Carbon::MONDAY);
    $lastCalendarDay = $selectedDate->copy()->endOfMonth()->endOfWeek(\Carbon\Carbon::SUNDAY);
    for ($day = $firstCalendarDay; $day->lessThanOrEqualTo($lastCalendarDay); $day = $day->addDay()) {
        $calendarDays[] = $day;
    }
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-600">Kalender</p>
                <h2 class="mt-1 text-2xl font-bold text-slate-900">Kalender Kerja</h2>
            </div>
            <div class="flex items-center gap-2">
                <span class="rounded-full bg-cyan-50 px-3 py-1.5 text-xs font-semibold text-cyan-800">{{ $agendaGroups->count() }} agenda</span>
                <a href="{{ route('work-schedule.index') }}" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-cyan-300 hover:bg-cyan-50">Jadwal Mingguan</a>
            </div>
        </div>
    </x-slot>

    <div class="app-panel mb-5 p-4 sm:p-5">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Periode kalender</p>
                <h3 class="mt-1 text-xl font-bold tracking-tight text-slate-900">{{ $selectedDate->translatedFormat('F Y') }}</h3>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                <div class="flex items-center gap-2">
                    <a aria-label="Bulan sebelumnya" href="{{ route('work-calendar.index', ['month' => $previousMonth->format('Y-m'), 'unit_id' => $unitId]) }}" class="calendar-control-button">‹ <span class="hidden sm:inline">Sebelumnya</span></a>
                    <a aria-label="Bulan berikutnya" href="{{ route('work-calendar.index', ['month' => $nextMonth->format('Y-m'), 'unit_id' => $unitId]) }}" class="calendar-control-button"><span class="hidden sm:inline">Berikutnya</span> ›</a>
                </div>
                <form method="GET" action="{{ route('work-calendar.index') }}" class="flex items-center gap-2">
                    <input type="hidden" name="month" value="{{ $selectedMonth }}">
                    <select name="unit_id" aria-label="Filter unit" class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-100 sm:flex-none">
                        <option value="">Semua unit</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}" @selected((string) $unitId === (string) $unit->id)>{{ $unit->code }}</option>
                        @endforeach
                    </select>
                    <button class="calendar-control-button">Terapkan</button>
                </form>
            </div>
        </div>
    </div>

    <div class="calendar-shell mb-6">
        <div class="calendar-grid calendar-weekdays border-b border-slate-200 bg-slate-50/90 text-center text-[0.68rem] font-bold uppercase tracking-wide text-slate-500">
            @foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $weekday)
                <div class="py-3">{{ $weekday }}</div>
            @endforeach
        </div>
        <div class="calendar-grid">
            @foreach ($calendarDays as $day)
                @php
                    $dayEntries = $calendarEntries->get($day->format('Y-m-d'), collect());
                    $dayEntryGroups = $groupCalendarEntries($dayEntries);
                    $unitCode = $unitId === null ? null : $units->firstWhere('id', (int) $unitId)?->code;
                    $routineWorking = $unitCode === 'TK'
                        ? $day->dayOfWeekIso < 6
                        : ($unitCode === 'SD'
                            ? ($day->dayOfWeekIso < 6 || ($day->dayOfWeekIso === 6 && intdiv($day->day - 1, 7) + 1 < 5))
                            : null);
                    $isWorking = $unitCode === null
                        ? null
                        : ($dayEntries->first()?->is_working_day ?? $routineWorking);
                @endphp
                <div class="calendar-day {{ $day->month !== $selectedDate->month ? 'is-outside-month' : '' }} {{ $day->isToday() ? 'is-today' : '' }}">
                    <div class="calendar-day__header">
                        <span class="calendar-day__number {{ $day->isToday() ? 'bg-cyan-100 text-cyan-700' : 'bg-slate-100 text-slate-700' }}">{{ $day->day }}</span>
                        @if ($day->month === $selectedDate->month)
                            <span class="calendar-day__badge {{ $isWorking === null ? 'bg-slate-100 text-slate-500' : ($isWorking ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600') }}">{{ $isWorking === null ? 'Pilih unit' : ($isWorking ? 'Kerja' : 'Libur') }}</span>
                        @endif
                    </div>
                    <div class="calendar-day__items">
                        @foreach ($dayEntryGroups as $dayEntryGroup)
                            @php
                                $entry = $dayEntryGroup->first();
                                $coversAllUnits = $units->isNotEmpty() && $units->every(fn ($unit) => $dayEntryGroup->contains('unit_id', $unit->id));
                                $unitLabel = $coversAllUnits ? 'Semua unit' : $dayEntryGroup->pluck('unit.code')->filter()->unique()->sort()->implode(' · ');
                            @endphp
                            <span class="calendar-day__item {{ $entry->type->value === 'holiday' ? 'calendar-day__item--holiday' : (($entry->type->value === 'special_event' || $entry->type->value === 'special_workday') ? 'calendar-day__item--special' : 'calendar-day__item--work') }}">
                                {{ $entry->title ?: $entry->type->value }}{{ $unitLabel !== '' ? ' · '.$unitLabel : '' }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="calendar-section">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="text-lg font-semibold text-slate-900">Daftar Agenda &amp; Hari Libur {{ $selectedDate->year }}</h3>
                <p class="mt-1 text-sm text-slate-500">Agenda yang sama untuk beberapa unit digabung dalam satu baris; data tiap unit tetap terpisah.</p>
            </div>
            <div class="flex items-center gap-3">
                <span class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600">Total {{ $agendaGroups->count() }} agenda</span>
                <button type="button" data-open-calendar-dialog class="calendar-primary-button">+ Tambah agenda</button>
            </div>
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="calendar-agenda-table min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-50 text-[0.68rem] font-bold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Tanggal pelaksanaan</th>
                        <th class="px-4 py-3">Durasi</th>
                        <th class="px-4 py-3">Nama agenda / hari libur</th>
                        <th class="px-4 py-3">Jenis agenda</th>
                        <th class="px-4 py-3">Unit</th>
                        <th class="px-4 py-3">Dampak rekap absensi</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($agendaGroups as $agendaGroup)
                        @php
                            $entry = $agendaGroup->first();
                            $groupUnits = $agendaGroup->pluck('unit.code')->filter()->unique()->sort()->values();
                            $coversAllUnits = $units->isNotEmpty() && $units->every(fn ($unit) => $agendaGroup->contains('unit_id', $unit->id));
                            $unitLabel = $coversAllUnits ? 'Semua unit' : $groupUnits->implode(' · ');
                        @endphp
                        <tr class="calendar-agenda-row">
                            <td data-label="Tanggal" class="whitespace-nowrap px-4 py-3 font-semibold text-slate-800">{{ $entry->date->format('d/m/Y') }}<p class="mt-1 text-xs font-normal text-slate-500">{{ $entry->date->translatedFormat('l') }}</p></td>
                            <td data-label="Durasi" class="px-4 py-3"><span class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-700">1 hari</span></td>
                            <td data-label="Nama agenda" class="min-w-52 px-4 py-3"><p class="font-semibold text-slate-900">{{ $entry->title ?: 'Agenda kerja' }}</p><p class="mt-1 text-xs text-slate-500">{{ $entry->description ?: ($entry->national_calendar_event_id ? 'Sinkronisasi otomatis hari libur nasional' : 'Agenda operasional sekolah') }}</p></td>
                            <td data-label="Jenis" class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $entry->type->value === 'holiday' ? 'bg-rose-50 text-rose-700' : ($entry->type->value === 'special_event' ? 'bg-violet-50 text-violet-700' : 'bg-cyan-50 text-cyan-700') }}">{{ $entry->type->value === 'holiday' ? 'Libur' : ($entry->type->value === 'special_workday' ? 'Hari kerja khusus' : ($entry->type->value === 'special_event' ? 'Agenda / dinas' : 'Hari kerja')) }}</td>
                            <td data-label="Unit" class="px-4 py-3"><span class="inline-flex whitespace-nowrap rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ $unitLabel ?: ($entry->unit?->name ?? 'Unit') }}</span></td>
                            <td data-label="Dampak absensi" class="whitespace-nowrap px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $entry->is_working_day ? 'bg-violet-50 text-violet-700' : 'bg-emerald-50 text-emerald-700' }}">{{ $entry->is_working_day ? 'Tetap masuk kerja' : 'Bebas kewajiban absen' }}</span></td>
                            <td data-label="Aksi" class="px-4 py-3">
                                <div class="calendar-row-actions">
                                    @foreach ($agendaGroup as $unitEntry)
                                        <div class="calendar-row-actions__unit">
                                            <button type="button" data-edit-calendar data-endpoint="{{ route('work-calendars.update', $unitEntry) }}" data-unit="{{ $unitEntry->unit_id }}" data-date="{{ $unitEntry->date->format('Y-m-d') }}" data-type="{{ $unitEntry->type->value }}" data-title="{{ $unitEntry->title }}" data-description="{{ $unitEntry->description }}" data-working="{{ $unitEntry->is_working_day ? '1' : '0' }}" data-start="{{ substr((string) $unitEntry->start_time, 0, 5) }}" data-end="{{ substr((string) $unitEntry->end_time, 0, 5) }}" class="calendar-row-actions__button calendar-row-actions__button--edit" aria-label="Edit agenda {{ $entry->title }} untuk {{ $unitEntry->unit?->code ?? $unitEntry->unit?->name }}" title="Edit agenda">
                                                <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" class="calendar-row-actions__icon"><path d="m13.5 3.5 3 3M4 16l3.8-.8L16 7a2.12 2.12 0 0 0-3-3L4.8 13.2 4 16Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </button>
                                            <form data-api-form data-endpoint="{{ route('work-calendars.destroy', $unitEntry) }}" data-method="DELETE" data-confirm="Hapus agenda untuk unit {{ $unitEntry->unit?->code ?? $unitEntry->unit?->name }}?" data-reload-on-success="true">
                                                <button type="submit" class="calendar-row-actions__button calendar-row-actions__button--delete" aria-label="Hapus agenda {{ $entry->title }} untuk {{ $unitEntry->unit?->code ?? $unitEntry->unit?->name }}" title="Hapus agenda">
                                                    <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" class="calendar-row-actions__icon"><path d="M4.5 6h11m-9.5 0 .6 10h7.8L15 6m-7-2h4l.7 2H7.3L8 4Zm1.5 4v5m3-5v5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                </button>
                                                <p data-form-status role="status" aria-live="polite" class="sr-only"></p>
                                            </form>
                                        </div>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr class="calendar-agenda-empty"><td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">Belum ada agenda pada periode ini. Sinkronkan API atau tambahkan agenda baru.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <dialog id="calendar-entry-dialog" class="calendar-dialog">
        <div class="calendar-dialog__panel">
            <div class="calendar-dialog__header">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-cyan-700">Kalender operasional</p>
                    <h3 class="mt-1 text-xl font-bold text-slate-900">Tambah Agenda / Hari Libur</h3>
                </div>
                <button type="button" data-close-calendar-dialog class="calendar-dialog__close" aria-label="Tutup">×</button>
            </div>
            <form id="calendar-entry-form" data-api-form data-store-endpoint="{{ route('work-calendars.bulk-upsert') }}" data-endpoint="{{ route('work-calendars.bulk-upsert') }}" data-reload-on-success="true" data-close-dialog-on-success="true" class="space-y-4 p-5">
                <div>
                    <label for="calendar-title" class="mb-1 block text-sm font-semibold text-slate-700">Nama agenda / hari libur <span class="text-rose-600">*</span></label>
                    <input id="calendar-title" name="title" type="text" maxlength="255" required placeholder="Contoh: Libur Hari Raya / Rapat Koordinasi Tahunan" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-800 focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-100">
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="calendar-date-from" class="mb-1 block text-sm font-semibold text-slate-700">Tanggal mulai <span class="text-rose-600">*</span></label>
                        <input id="calendar-date-from" name="date_from" type="date" required class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-800">
                    </div>
                    <div>
                        <label for="calendar-date-to" class="mb-1 block text-sm font-semibold text-slate-700">Tanggal selesai <span class="text-rose-600">*</span></label>
                        <input id="calendar-date-to" name="date_to" type="date" required class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-800">
                        <p class="mt-1 text-xs text-slate-500">Tanggal sama untuk 1 hari, atau rentang untuk beberapa hari.</p>
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="calendar-type" class="mb-1 block text-sm font-semibold text-slate-700">Jenis agenda <span class="text-rose-600">*</span></label>
                        <select id="calendar-type" name="type" required class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-800">
                            <option value="holiday">Libur</option>
                            <option value="special_event">Agenda / dinas</option>
                            <option value="special_workday">Hari kerja khusus</option>
                            <option value="workday">Hari kerja</option>
                        </select>
                    </div>
                    <div>
                        <label for="calendar-impact" class="mb-1 block text-sm font-semibold text-slate-700">Dampak pada status rekap <span class="text-rose-600">*</span></label>
                        <select id="calendar-impact" name="is_working_day" data-calendar-impact required class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-800">
                            <option value="0">Hari libur (bebas kewajiban absen)</option>
                            <option value="1">Tetap masuk kerja</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="calendar-unit" class="mb-1 block text-sm font-semibold text-slate-700">Berlaku untuk unit <span class="text-rose-600">*</span></label>
                    <select id="calendar-unit" name="unit_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-800">
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->code }}</option>
                        @endforeach
                    </select>
                </div>
                <div data-calendar-time-fields class="hidden grid gap-4 sm:grid-cols-2">
                    <div><label for="calendar-start" class="mb-1 block text-sm font-semibold text-slate-700">Mulai</label><input id="calendar-start" name="start_time" type="time" class="w-full rounded-lg border border-slate-300 px-3 py-2.5"></div>
                    <div><label for="calendar-end" class="mb-1 block text-sm font-semibold text-slate-700">Selesai</label><input id="calendar-end" name="end_time" type="time" class="w-full rounded-lg border border-slate-300 px-3 py-2.5"></div>
                </div>
                <div>
                    <label for="calendar-description" class="mb-1 block text-sm font-semibold text-slate-700">Keterangan / catatan</label>
                    <textarea id="calendar-description" name="description" rows="3" placeholder="Opsional: keterangan surat edaran atau lokasi agenda..." class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-800"></textarea>
                </div>
                <input type="hidden" name="date" value="">
                <div class="flex flex-col-reverse gap-2 border-t border-slate-200 pt-4 sm:flex-row sm:justify-end">
                    <button type="button" data-close-calendar-dialog class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Batal</button>
                    <button type="submit" class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">Simpan agenda</button>
                </div>
                <p data-form-status role="status" aria-live="polite" class="text-sm text-slate-600"></p>
            </form>
        </div>
    </dialog>

    <div class="mt-6">
        <section class="calendar-section">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900">Libur Nasional &amp; Cuti Bersama</h3>
                    <p class="mt-1 text-xs text-slate-500">Data mengikuti keputusan bersama tiga menteri dan disinkronkan ke kalender operasional unit.</p>
                </div>
                <form data-api-form data-endpoint="{{ route('national-calendar-events.sync') }}" data-reload-on-success="true" class="flex items-center gap-2">
                <input type="number" name="year" value="{{ $selectedDate->year }}" min="2000" max="2100" required class="w-24 rounded-lg border border-slate-200 px-3 py-2 text-sm" aria-label="Tahun sinkronisasi">
                <button type="submit" class="calendar-primary-button">Sinkronkan API</button>
                    <p data-form-status role="status" aria-live="polite" class="text-xs text-slate-600"></p>
                </form>
            </div>
            <p class="mt-2 text-xs text-slate-500">Libur nasional dan cuti bersama otomatis diterapkan sebagai hari libur untuk unit TK dan SD. Catatan agenda lama disimpan saat tanggal yang sama diperbarui.</p>
            <div class="mt-4 max-h-[34rem] space-y-3 overflow-y-auto pr-1">
                @forelse ($nationalEvents as $event)
                    <div class="calendar-national-event">
                        <div>
                            <p class="font-semibold text-slate-900">{{ $event->local_name }}</p>
                            <p class="text-xs text-slate-500">{{ $event->date->format('d M Y') }} · {{ $event->name }} · {{ in_array('joint_leave', $event->types ?? [], true) ? 'Cuti bersama' : 'Libur nasional' }}</p>
                            <a href="{{ $event->source_url }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-flex text-xs font-medium text-cyan-700 underline underline-offset-2 hover:text-cyan-900">Sumber data API</a>
                        </div>
                        @php
                            $appliedUnitCount = $event->work_calendar_entries_count;
                            $expectedUnitCount = $units->count();
                            $appliedToAllUnits = $expectedUnitCount > 0 && $appliedUnitCount >= $expectedUnitCount;
                        @endphp
                        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold {{ $appliedToAllUnits ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ $expectedUnitCount === 0 ? 'Unit TK/SD belum tersedia' : ($appliedToAllUnits ? 'Masuk kalender operasional' : ($appliedUnitCount > 0 ? 'Masuk sebagian unit' : 'Belum diterapkan')) }}</span>
                    </div>
                @empty
                    <p class="rounded-xl border border-dashed border-slate-300 p-5 text-center text-sm text-slate-500">Belum ada data libur nasional. Sinkronkan API untuk mengambil dan menyimpan agenda otomatis.</p>
                @endforelse
            </div>
        </section>

    </div>
</x-app-layout>
