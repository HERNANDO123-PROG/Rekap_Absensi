@php
    $selectedMonth = request('month', now()->format('Y-m'));
    $selectedDate = \Carbon\CarbonImmutable::createFromFormat('Y-m', $selectedMonth)->startOfMonth();
    $nextMonth = $selectedDate->copy()->addMonth();
    $previousMonth = $selectedDate->copy()->subMonth();
    $unitId = request('unit_id');
    $units = \App\Models\Unit::query()->whereIn('code', ['TK', 'SD'])->orderBy('code')->get();
    $categories = \App\Models\WorkScheduleCategory::query()->with('profiles.rules')->orderBy('name')->get();
    $periods = \App\Models\WorkSchedulePeriod::query()
        ->with(['unit', 'category', 'profile'])
        ->where('is_active', true)
        ->whereDate('start_date', '<=', $selectedDate->copy()->endOfMonth())
        ->whereDate('end_date', '>=', $selectedDate)
        ->get();
    $calendarEntries = \App\Models\WorkCalendar::query()
        ->with('unit')
        ->whereBetween('date', [$selectedDate->format('Y-m-01'), $selectedDate->copy()->endOfMonth()->format('Y-m-d')])
        ->when($unitId, fn ($query) => $query->where('unit_id', $unitId))
        ->orderBy('date')
        ->get()
        ->groupBy(fn ($entry) => $entry->date->format('Y-m-d'));
    $nationalEvents = \App\Models\NationalCalendarEvent::query()
        ->where('country_code', config('services.nager_date.country', 'ID'))
        ->whereYear('date', $selectedDate->year)
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
            <div class="flex items-center gap-2"><span class="rounded-full bg-pink-100 px-3 py-1 text-xs font-semibold text-pink-700">{{ \App\Models\WorkCalendar::query()->count() }} agenda</span><a href="{{ route('work-schedule.index') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Jadwal Mingguan</a></div>
        </div>
    </x-slot>

    <div class="app-panel mb-5 p-4">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Periode kalender</p>
                <h3 class="mt-1 text-lg font-semibold text-slate-900">{{ $selectedDate->translatedFormat('F Y') }}</h3>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('work-calendar.index', ['month' => $previousMonth->format('Y-m'), 'unit_id' => $unitId]) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Sebelumnya</a>
                <a href="{{ route('work-calendar.index', ['month' => $nextMonth->format('Y-m'), 'unit_id' => $unitId]) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Berikutnya</a>
                <form method="GET" action="{{ route('work-calendar.index') }}" class="flex items-center gap-2">
                    <input type="hidden" name="month" value="{{ $selectedMonth }}">
                    <select name="unit_id" aria-label="Filter unit" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option value="">Semua unit</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}" @selected((string) $unitId === (string) $unit->id)>{{ $unit->code }}</option>
                        @endforeach
                    </select>
                    <button class="rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700">Filter</button>
                </form>
            </div>
        </div>
    </div>

    <div class="calendar-shell mb-6">
        <div class="calendar-grid border-b border-slate-200 bg-slate-50 text-center text-xs font-semibold uppercase text-slate-500">
            @foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $weekday)
                <div class="py-3">{{ $weekday }}</div>
            @endforeach
        </div>
        <div class="calendar-grid">
            @foreach ($calendarDays as $day)
                @php
                    $dayEntries = $calendarEntries->get($day->format('Y-m-d'), collect());
                    $unitCode = $unitId === null ? null : $units->firstWhere('id', (int) $unitId)?->code;
                    $routineWorking = $unitCode === 'TK'
                        ? $day->dayOfWeekIso < 6
                        : ($unitCode === 'SD'
                            ? ($day->dayOfWeekIso < 6 || ($day->dayOfWeekIso === 6 && intdiv($day->day - 1, 7) + 1 < 5))
                            : null);
                    $isWorking = $unitCode === null
                        ? null
                        : ($dayEntries->first()?->is_working_day ?? $routineWorking);
                    $hasRamadan = $periods->contains(fn ($period) => (
                        $period->unit_id === null || $unitId === null || (int) $period->unit_id === (int) $unitId
                    ) && $period->start_date->lessThanOrEqualTo($day) && $period->end_date->greaterThanOrEqualTo($day));
                    $hasNationalEvent = $nationalEvents->contains(fn ($event) => $event->date->format('Y-m-d') === $day->format('Y-m-d'));
                @endphp
                <div class="calendar-day {{ $day->month !== $selectedDate->month ? 'is-outside-month' : '' }} {{ $day->isToday() ? 'is-today' : '' }}">
                    <div class="calendar-day__header">
                        <span class="calendar-day__number {{ $day->isToday() ? 'bg-cyan-100 text-cyan-700' : 'bg-slate-100 text-slate-700' }}">{{ $day->day }}</span>
                        @if ($day->month === $selectedDate->month)
                            <span class="calendar-day__badge {{ $isWorking === null ? 'bg-slate-100 text-slate-500' : ($isWorking ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600') }}">{{ $isWorking === null ? 'Pilih unit' : ($isWorking ? 'Kerja' : 'Libur') }}</span>
                        @endif
                    </div>
                    <div class="calendar-day__items">
                        @foreach ($dayEntries as $entry)
                            <span class="calendar-day__item {{ $entry->type->value === 'holiday' ? 'calendar-day__item--holiday' : (($entry->type->value === 'special_event' || $entry->type->value === 'special_workday') ? 'calendar-day__item--special' : 'calendar-day__item--work') }}">
                                {{ $entry->title ?: $entry->type->value }}{{ $entry->unit ? ' · '.$entry->unit->code : '' }}
                            </span>
                        @endforeach
                        @if ($hasRamadan)
                            <span class="calendar-day__item calendar-day__item--special">Jadwal periode</span>
                        @endif
                        @if ($hasNationalEvent)
                            <span class="calendar-day__item calendar-day__item--holiday">Kandidat nasional</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1.4fr_0.6fr]">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Agenda mendatang</h3>
            <div class="mt-5 space-y-3">
                @forelse ($calendarEntries->flatten()->take(10) as $entry)
                    <div class="flex items-center justify-between rounded-xl border border-slate-200 px-3 py-3">
                        <div>
                            <p class="font-semibold text-slate-900">{{ $entry->title ?: 'Agenda kerja' }}</p>
                            <p class="text-xs text-slate-500">{{ $entry->unit?->code ?? $entry->unit?->name }} · {{ $entry->date?->format('d M Y') }} · {{ $entry->description }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="rounded-full {{ $entry->type->value === 'holiday' ? 'bg-pink-100 text-pink-700' : 'bg-sky-100 text-sky-700' }} px-2.5 py-1 text-xs font-semibold">{{ $entry->type->value ?? 'workday' }}</span>
                            <button type="button" data-edit-calendar data-endpoint="{{ route('work-calendars.update', $entry) }}" data-unit="{{ $entry->unit_id }}" data-date="{{ $entry->date->format('Y-m-d') }}" data-type="{{ $entry->type->value }}" data-title="{{ $entry->title }}" data-description="{{ $entry->description }}" data-working="{{ $entry->is_working_day ? '1' : '0' }}" data-start="{{ substr((string) $entry->start_time, 0, 5) }}" data-end="{{ substr((string) $entry->end_time, 0, 5) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs">Ubah</button>
                            <form data-api-form data-endpoint="{{ route('work-calendars.destroy', $entry) }}" data-method="DELETE" data-reload-on-success="true">
                                <button type="submit" class="rounded-lg border border-rose-300 px-2 py-1 text-xs text-rose-700">Hapus</button>
                                <p data-form-status role="status" aria-live="polite" class="sr-only"></p>
                            </form>
                        </div>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-center text-sm text-slate-500">Belum ada agenda kerja.</div>
                @endforelse
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Tambah agenda</h3>
            <form id="calendar-entry-form" data-api-form data-store-endpoint="{{ route('work-calendars.store') }}" data-endpoint="{{ route('work-calendars.store') }}" data-reload-on-success="true" class="mt-5 space-y-4 text-sm">
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
                <button type="button" data-cancel-calendar-edit class="hidden w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700">Batal ubah</button>
                <p data-form-status role="status" aria-live="polite" class="text-sm text-slate-600"></p>
            </form>
        </div>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900">Kalender nasional (Nager.Date)</h3>
                    <p class="mt-1 text-xs text-slate-500">Hanya data kandidat; verifikasi tidak otomatis mengubah jadwal absensi.</p>
                </div>
                <form data-api-form data-endpoint="{{ route('national-calendar-events.sync') }}" data-reload-on-success="true" class="flex items-center gap-2">
                    <input type="number" name="year" value="{{ $selectedDate->year }}" min="2000" max="2100" required class="w-24 rounded-lg border border-slate-300 px-3 py-2 text-sm" aria-label="Tahun sinkronisasi">
                    <button type="submit" class="rounded-lg bg-cyan-700 px-3 py-2 text-sm font-semibold text-white">Sinkronkan API</button>
                    <p data-form-status role="status" aria-live="polite" class="sr-only"></p>
                </form>
            </div>
            <p class="mt-2 text-xs text-slate-500">Sumber hanya memuat hari libur publik yang diterbitkan API, bukan cuti bersama atau ketetapan Ramadan. Tanggal yang sudah diverifikasi tidak ditimpa sinkronisasi berikutnya.</p>
            <div class="mt-4 max-h-96 space-y-3 overflow-y-auto">
                @forelse ($nationalEvents as $event)
                    <div class="flex flex-col gap-2 rounded-xl border border-slate-200 p-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="font-semibold text-slate-900">{{ $event->local_name }}</p>
                            <p class="text-xs text-slate-500">{{ $event->date->format('d M Y') }} · {{ $event->name }} · {{ $event->source }}</p>
                            <a href="{{ $event->source_url }}" target="_blank" rel="noopener noreferrer" class="text-xs text-cyan-700 underline">Sumber data API</a>
                            <span class="ml-2 rounded-full px-2 py-0.5 text-xs {{ $event->verification_status === 'verified' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ $event->verification_status === 'verified' ? 'Terverifikasi sumber' : 'Menunggu verifikasi' }}</span>
                            @if ($event->verification_source_url)
                                <a href="{{ $event->verification_source_url }}" target="_blank" rel="noopener noreferrer" class="ml-2 text-xs text-emerald-700 underline">Lihat ketetapan resmi</a>
                            @endif
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2">
                            <button type="button" data-apply-national-event data-date="{{ $event->date->format('Y-m-d') }}" data-title="{{ $event->local_name }}" class="rounded-lg border border-pink-700 px-3 py-2 text-xs font-semibold text-pink-800">Atur operasional</button>
                            @if ($event->verification_status === 'pending')
                                <form data-api-form data-endpoint="{{ route('national-calendar-events.verify', $event) }}" data-reload-on-success="true" class="flex items-center gap-2">
                                    <input type="url" name="verification_source_url" required maxlength="500" placeholder="URL resmi .go.id" aria-label="URL sumber resmi pemerintah" class="w-40 rounded-lg border border-slate-300 px-2 py-2 text-xs">
                                    <button type="submit" class="rounded-lg border border-cyan-700 px-3 py-2 text-xs font-semibold text-cyan-800">Catat verifikasi</button>
                                    <p data-form-status role="status" aria-live="polite" class="sr-only"></p>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="rounded-xl border border-dashed border-slate-300 p-5 text-center text-sm text-slate-500">Belum ada data nasional. Sinkronkan untuk memuat kandidat.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Jadwal khusus periode (mis. Ramadan)</h3>
            <p class="mt-1 text-xs text-slate-500">Pilih profil kategori yang sudah disetujui. Periode berlaku otomatis pada unit, tanggal, dan hari yang ditetapkan profil.</p>
            <form id="schedule-period-form" data-api-form data-store-endpoint="{{ route('work-schedule-periods.store') }}" data-endpoint="{{ route('work-schedule-periods.store') }}" data-reload-on-success="true" class="mt-4 grid gap-3 sm:grid-cols-2">
                <input name="name" type="text" maxlength="255" required placeholder="Nama periode, mis. Ramadan" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <select name="unit_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">TK dan SD</option>
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}">{{ $unit->code }}</option>
                    @endforeach
                </select>
                <select name="work_schedule_category_id" required data-schedule-category-select class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">Pilih kategori pegawai</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" data-category-active="{{ $category->is_active ? 'true' : 'false' }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                <select name="work_schedule_profile_id" required data-schedule-profile-select class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">Pilih profil jadwal</option>
                    @foreach ($categories as $category)
                        @foreach ($category->profiles->where('is_active', true) as $profile)
                            <option value="{{ $profile->id }}" data-category-id="{{ $category->id }}">{{ $category->name }} · {{ $profile->name }}</option>
                        @endforeach
                    @endforeach
                </select>
                <label class="text-xs text-slate-600">Tanggal mulai<input name="start_date" type="date" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label>
                <label class="text-xs text-slate-600">Tanggal akhir<input name="end_date" type="date" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label>
                <label class="text-xs text-slate-600 sm:col-span-2">Status
                    <select name="is_active" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="1" selected>Aktif</option>
                        <option value="0">Nonaktif</option>
                    </select>
                </label>
                <button type="submit" class="rounded-lg bg-violet-700 px-3 py-2 text-sm font-semibold text-white sm:col-span-2">Simpan jadwal periode</button>
                <button type="button" data-cancel-period-edit class="hidden rounded-lg border border-slate-300 px-3 py-2 text-sm sm:col-span-2">Batal ubah</button>
                <p data-form-status role="status" aria-live="polite" class="text-sm text-slate-600 sm:col-span-2"></p>
            </form>
            <div class="mt-5 max-h-72 space-y-2 overflow-y-auto">
                @forelse (\App\Models\WorkSchedulePeriod::query()->with(['unit', 'category', 'profile'])->orderByDesc('start_date')->limit(30)->get() as $period)
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 p-3">
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-slate-900">{{ $period->name }} · {{ $period->unit?->code ?? 'TK & SD' }}</p>
                            <p class="text-xs text-slate-500">{{ $period->category?->name }} · {{ $period->profile?->name }} · {{ $period->start_date->format('d M Y') }}–{{ $period->end_date->format('d M Y') }} · {{ $period->is_active ? 'Aktif' : 'Nonaktif' }}</p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <button type="button" data-edit-period data-endpoint="{{ route('work-schedule-periods.update', $period) }}" data-name="{{ $period->name }}" data-unit="{{ $period->unit_id }}" data-category="{{ $period->work_schedule_category_id }}" data-profile="{{ $period->work_schedule_profile_id }}" data-start="{{ $period->start_date->format('Y-m-d') }}" data-end="{{ $period->end_date->format('Y-m-d') }}" data-active="{{ $period->is_active ? '1' : '0' }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs">Ubah</button>
                            <form data-api-form data-endpoint="{{ route('work-schedule-periods.destroy', $period) }}" data-method="DELETE" data-reload-on-success="true">
                                <button type="submit" class="rounded-lg border border-rose-300 px-2 py-1 text-xs text-rose-700">Hapus</button>
                                <p data-form-status role="status" aria-live="polite" class="sr-only"></p>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="rounded-xl border border-dashed border-slate-300 p-4 text-center text-sm text-slate-500">Belum ada periode jadwal khusus.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-app-layout>
