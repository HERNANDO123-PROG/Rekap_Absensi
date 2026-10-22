<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-600">Kebijakan kerja</p>
                <h2 class="mt-1 text-2xl font-bold text-slate-900">Jadwal Kerja</h2>
            </div>
            <span class="rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold text-indigo-700">{{ \App\Models\WorkSchedule::query()->count() }} konfigurasi</span>
        </div>
    </x-slot>

    <div class="grid gap-6 xl:grid-cols-[1.4fr_0.6fr]">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Jadwal mingguan</h3>
            <div class="mt-5 overflow-hidden rounded-xl border border-slate-200">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-4 py-3 font-medium">Hari</th>
                            <th class="px-4 py-3 font-medium">Unit</th>
                            <th class="px-4 py-3 font-medium">Jam masuk</th>
                            <th class="px-4 py-3 font-medium">Jam pulang</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @php($schedules = \App\Models\WorkSchedule::query()->with('unit')->latest()->limit(7)->get())
                        @forelse ($schedules as $schedule)
                            <tr>
                                <td class="px-4 py-3 font-medium text-slate-800">{{ $schedule->day_of_week }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ $schedule->unit?->code ?? $schedule->unit?->name }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ $schedule->start_time ?? '--:--' }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ $schedule->end_time ?? '--:--' }}</td>
                                <td class="px-4 py-3"><span class="rounded-full {{ $schedule->is_working_day ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700' }} px-2 py-1 text-xs font-semibold">{{ $schedule->is_working_day ? 'Kerja' : 'Libur' }}</span></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-slate-500">Belum ada jadwal kerja.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Atur jadwal</h3>
            <form data-api-form data-endpoint="{{ route('work-schedules.store') }}" data-reload-on-success="true" class="mt-5 space-y-4 text-sm">
                <div>
                    <label for="schedule-unit" class="mb-1 block text-sm font-medium text-slate-700">Unit</label>
                    <select id="schedule-unit" name="unit_id" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-slate-800 focus:border-cyan-500 focus:outline-none">
                        @foreach (\App\Models\Unit::query()->orderBy('name')->get() as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->code ?? $unit->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="schedule-day" class="mb-1 block text-sm font-medium text-slate-700">Hari</label>
                    <select id="schedule-day" name="day_of_week" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-slate-800 focus:border-cyan-500 focus:outline-none">
                        @foreach (['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'] as $day => $label)
                            <option value="{{ $day }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <input name="is_working_day" type="hidden" value="0" />
                <label class="flex items-center gap-2 text-sm text-slate-700"><input name="is_working_day" type="checkbox" value="1" checked class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-600" /> Hari kerja</label>
                <div class="grid grid-cols-2 gap-3">
                    <div><label for="schedule-start" class="mb-1 block text-sm font-medium text-slate-700">Masuk</label><input id="schedule-start" name="start_time" type="time" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5" /></div>
                    <div><label for="schedule-end" class="mb-1 block text-sm font-medium text-slate-700">Pulang</label><input id="schedule-end" name="end_time" type="time" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5" /></div>
                </div>
                <button type="submit" class="rounded-xl bg-indigo-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-indigo-800">Simpan</button>
                <p data-form-status role="status" aria-live="polite" class="text-sm text-slate-600"></p>
            </form>
        </div>
    </div>
</x-app-layout>
