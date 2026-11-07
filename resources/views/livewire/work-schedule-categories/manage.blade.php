@php
    $categories = \App\Models\WorkScheduleCategory::query()
        ->with(['workScheduleProfile.rules', 'profiles.rules'])
        ->orderByDesc('is_active')
        ->orderBy('name')
        ->get();
    $days = [0 => 'Minggu', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-700">Master data</p>
                <h2 class="mt-1 text-2xl font-bold text-slate-900">Kategori Jam Kerja</h2>
            </div>
            <span class="rounded-full bg-cyan-100 px-3 py-1 text-xs font-semibold text-cyan-800">{{ $categories->count() }} kategori</span>
        </div>
    </x-slot>

    <div class="space-y-6">
        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 px-5 py-3">
                <span class="inline-flex items-center gap-2 rounded-lg bg-cyan-800 px-4 py-2 text-sm font-semibold text-white">
                    <span aria-hidden="true">▦</span>
                    Kategori Jam Normal
                </span>
                <span class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-slate-400" aria-disabled="true">
                    <span aria-hidden="true">★</span>
                    Agenda Prioritas
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-slate-500">Segera</span>
                </span>
            </div>

            <div class="grid gap-4 p-5 lg:grid-cols-2">
                @forelse ($categories as $category)
                    @php($normalProfile = $category->profiles->first(fn ($profile) => (int) $category->work_schedule_profile_id === (int) $profile->id))
                    <article x-data="{ editing: false }" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-4 py-4">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="rounded-md bg-cyan-800 px-2 py-1 text-[11px] font-bold uppercase tracking-wide text-white">{{ strtoupper(substr($category->name, 0, 12)) }}</span>
                                    <h3 class="font-semibold text-slate-900">{{ $category->name }}</h3>
                                </div>
                                <p class="mt-2 text-xs text-slate-500">{{ $normalProfile?->name ?? 'Profil jadwal normal belum dibuat' }}</p>
                                @if ($normalProfile)
                                    <span class="mt-2 inline-block rounded-full bg-cyan-50 px-2 py-1 text-[10px] font-semibold text-cyan-700">Jadwal Normal</span>
                                @endif
                            </div>
                            @if ($normalProfile)
                                <button
                                    type="button"
                                    x-on:click="editing = ! editing"
                                    class="rounded-lg border border-cyan-200 px-3 py-1.5 text-xs font-semibold text-cyan-800 hover:bg-cyan-50"
                                >
                                    <span x-text="editing ? 'Tutup' : 'Atur jam'"></span>
                                </button>
                            @endif
                        </div>
                        <div x-show="! editing" x-cloak class="overflow-x-auto">
                            <table class="w-full min-w-[520px] text-left text-xs">
                                <thead class="bg-slate-50 text-slate-500">
                                    <tr>
                                        <th class="px-4 py-2.5 font-semibold">Hari</th>
                                        <th class="px-4 py-2.5 font-semibold">Status</th>
                                        <th class="px-4 py-2.5 font-semibold">Masuk</th>
                                        <th class="px-4 py-2.5 font-semibold">Pulang</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ([1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 0 => 'Minggu'] as $day => $label)
                                        @php($rule = $normalProfile?->rules->firstWhere('day_of_week', $day))
                                        <tr>
                                            <td class="px-4 py-2.5 font-medium text-slate-800">{{ $label }}</td>
                                            <td class="px-4 py-2.5">
                                                <span class="rounded-full px-2 py-1 text-[11px] font-semibold {{ $rule?->is_working_day ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                                    {{ $rule?->is_working_day ? 'Hari Kerja' : 'Libur' }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2.5 text-slate-600">{{ $rule?->is_working_day ? substr((string) $rule->start_time, 0, 5) : '-' }}</td>
                                            <td class="px-4 py-2.5 text-slate-600">{{ $rule?->is_working_day ? substr((string) $rule->end_time, 0, 5) : '-' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($normalProfile)
                            <div x-show="editing" x-cloak class="border-t border-slate-200 bg-slate-50/60 p-4">
                                <form data-api-form data-endpoint="{{ route('work-schedule-categories.profiles.update', [$category, $normalProfile]) }}" data-reload-on-success="true">
                                    <input type="hidden" name="_method" value="PUT">
                                    <input type="hidden" name="name" value="{{ $normalProfile->name }}">
                                    <input type="hidden" name="is_normal" value="1">
                                    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                                        <table class="w-full min-w-[520px] text-left text-xs">
                                            <thead class="bg-slate-50 text-slate-500">
                                                <tr>
                                                    <th class="px-3 py-2 font-semibold">Hari</th>
                                                    <th class="px-3 py-2 font-semibold">Status</th>
                                                    <th class="px-3 py-2 font-semibold">Masuk</th>
                                                    <th class="px-3 py-2 font-semibold">Pulang</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100">
                                                @foreach ([1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 0 => 'Minggu'] as $day => $label)
                                                    @php($rule = $normalProfile->rules->firstWhere('day_of_week', $day))
                                                    <tr>
                                                        <td class="px-3 py-2 font-medium text-slate-800">{{ $label }}</td>
                                                        <td class="px-3 py-2">
                                                            <select name="rules[{{ $day }}][is_working_day]" class="rounded-lg border-slate-300 text-xs">
                                                                <option value="1" @selected($rule?->is_working_day)>Hari kerja</option>
                                                                <option value="0" @selected(! $rule?->is_working_day)>Libur</option>
                                                            </select>
                                                        </td>
                                                        <td class="px-3 py-2">
                                                            <input name="rules[{{ $day }}][start_time]" type="time" value="{{ $rule?->start_time ? substr((string) $rule->start_time, 0, 5) : '' }}" aria-label="Jam masuk {{ $label }}" class="rounded-lg border-slate-300 text-xs">
                                                        </td>
                                                        <td class="px-3 py-2">
                                                            <input name="rules[{{ $day }}][end_time]" type="time" value="{{ $rule?->end_time ? substr((string) $rule->end_time, 0, 5) : '' }}" aria-label="Jam pulang {{ $label }}" class="rounded-lg border-slate-300 text-xs">
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="mt-3 flex items-center gap-3">
                                        <button type="submit" class="rounded-lg bg-cyan-800 px-4 py-2 text-xs font-semibold text-white hover:bg-cyan-900">Simpan jam normal</button>
                                        <p data-form-status role="status" aria-live="polite" class="text-xs text-slate-600"></p>
                                    </div>
                                </form>
                            </div>
                        @endif
                    </article>
                @empty
                    <p class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500 lg:col-span-2">Belum ada kategori jam kerja.</p>
                @endforelse
            </div>
        </section>

    </div>
</x-app-layout>
