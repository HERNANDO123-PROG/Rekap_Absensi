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
        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Tambah kategori</h3>
            <p class="mt-1 text-sm text-slate-500">Tambahkan nama kategori terlebih dahulu, lalu siapkan jadwal normal dan konfigurasi alternatifnya.</p>
            <form data-api-form data-endpoint="{{ route('work-schedule-categories.store') }}" data-reload-on-success="true" class="mt-4 flex flex-wrap items-end gap-3">
                <div class="w-full sm:max-w-md">
                    <label for="category-name" class="mb-1 block text-sm font-medium text-slate-700">Nama kategori</label>
                    <input id="category-name" name="name" required maxlength="255" placeholder="Contoh: Guru" class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <button type="submit" class="rounded-lg bg-cyan-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-900">Simpan kategori</button>
                <p data-form-status role="status" aria-live="polite" class="text-sm text-slate-600"></p>
            </form>
        </section>

        @forelse ($categories as $category)
            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-lg font-semibold text-slate-900">{{ $category->name }}</h3>
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $category->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">{{ $category->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                        </div>
                        <p class="mt-1 text-sm text-slate-500">Profil jadwal kategori berlaku bersama kalender kerja tanggal tertentu.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <form id="category-update-{{ $category->id }}" data-api-form data-endpoint="{{ route('work-schedule-categories.update', $category) }}" data-reload-on-success="true" class="flex items-center gap-2">
                            <input type="hidden" name="_method" value="PUT">
                            <label class="sr-only" for="category-edit-name-{{ $category->id }}">Nama kategori</label>
                            <input id="category-edit-name-{{ $category->id }}" name="name" value="{{ $category->name }}" required maxlength="255" class="w-40 rounded-lg border-slate-300 px-2 py-1.5 text-sm sm:w-52">
                            <button type="submit" class="text-xs font-semibold text-cyan-800 hover:text-cyan-950">Ubah nama</button>
                            <p data-form-status class="sr-only" role="status" aria-live="polite"></p>
                        </form>
                        <form data-api-form data-endpoint="{{ route($category->is_active ? 'work-schedule-categories.deactivate' : 'work-schedule-categories.activate', $category) }}" data-reload-on-success="true" data-confirm="{{ $category->is_active ? 'Nonaktifkan kategori ini?' : 'Aktifkan kategori ini?' }}">
                            <button type="submit" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ $category->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                            <p data-form-status class="sr-only" role="status" aria-live="polite"></p>
                        </form>
                    </div>
                </div>

                <div class="space-y-5 p-5">
                    @forelse ($category->profiles as $profile)
                        @php($isNormal = (int) $category->work_schedule_profile_id === (int) $profile->id)
                        @php($workingDays = $profile->rules->where('is_working_day', true)->count())
                        <details name="schedule-configurations-{{ $category->id }}" class="group rounded-xl border {{ $isNormal ? 'border-cyan-200 bg-cyan-50/40' : 'border-slate-200' }}">
                            <summary
                                id="profile-summary-{{ $profile->id }}"
                                aria-controls="profile-details-{{ $profile->id }}"
                                class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 rounded-xl p-4 text-left outline-none focus-visible:ring-2 focus-visible:ring-cyan-700 focus-visible:ring-offset-2 [&::-webkit-details-marker]:hidden"
                            >
                                <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1">
                                    <span class="font-semibold text-slate-900">{{ $profile->name }}</span>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $isNormal ? 'bg-cyan-100 text-cyan-900' : 'bg-slate-100 text-slate-700' }}">{{ $isNormal ? 'Jadwal Normal' : 'Jadwal Alternatif' }}</span>
                                    <span class="text-sm text-slate-500">{{ $workingDays }} hari kerja</span>
                                    @if (! $profile->is_active)
                                        <span class="rounded-full bg-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700">Nonaktif</span>
                                    @endif
                                </span>
                                <span class="flex items-center gap-2 text-sm font-semibold text-cyan-800">
                                    <span>Buka / edit</span>
                                    <svg aria-hidden="true" class="h-4 w-4 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M5.22 7.47a.75.75 0 0 1 1.06 0L10 11.19l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.53a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </span>
                            </summary>
                            <div id="profile-details-{{ $profile->id }}" aria-labelledby="profile-summary-{{ $profile->id }}" class="border-t border-slate-200 p-4">
                                <form
                                    data-api-form
                                    data-endpoint="{{ route('work-schedule-categories.profiles.update', [$category, $profile]) }}"
                                    data-reload-on-success="true"
                                >
                                    <input type="hidden" name="_method" value="PUT">
                                    <div class="flex flex-wrap items-end gap-3">
                                        <div class="min-w-52 flex-1">
                                            <label for="profile-name-{{ $profile->id }}" class="mb-1 block text-sm font-medium text-slate-700">Nama konfigurasi</label>
                                            <input id="profile-name-{{ $profile->id }}" name="name" value="{{ $profile->name }}" required maxlength="255" class="w-full rounded-lg border-slate-300 text-sm">
                                        </div>
                                        <div class="min-w-48">
                                            <label for="profile-kind-{{ $profile->id }}" class="mb-1 block text-sm font-medium text-slate-700">Jenis konfigurasi</label>
                                            <select id="profile-kind-{{ $profile->id }}" name="is_normal" class="w-full rounded-lg border-slate-300 text-sm">
                                                <option value="1" @selected($isNormal)>Jadwal Normal</option>
                                                <option value="0" @selected(! $isNormal)>Jadwal Alternatif</option>
                                            </select>
                                        </div>
                                        <button type="submit" class="rounded-lg bg-cyan-800 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-900">Simpan konfigurasi</button>
                                        <p data-form-status role="status" aria-live="polite" class="text-sm text-slate-600"></p>
                                    </div>

                                    <div class="mt-4 overflow-x-auto rounded-lg border border-slate-200 bg-white">
                                        <table class="min-w-[680px] w-full divide-y divide-slate-200 text-left text-sm">
                                            <thead class="bg-slate-50 text-slate-600">
                                                <tr>
                                                    <th class="px-3 py-2 font-medium">Hari</th>
                                                    <th class="px-3 py-2 font-medium">Status hari</th>
                                                    <th class="px-3 py-2 font-medium">Jam masuk</th>
                                                    <th class="px-3 py-2 font-medium">Jam pulang</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100">
                                                @foreach ($days as $day => $label)
                                                    @php($rule = $profile->rules->firstWhere('day_of_week', $day))
                                                    <tr>
                                                        <td class="whitespace-nowrap px-3 py-2 font-medium text-slate-800">{{ $label }}</td>
                                                        <td class="px-3 py-2">
                                                            <select name="rules[{{ $day }}][is_working_day]" class="w-full rounded-lg border-slate-300 text-sm">
                                                                <option value="1" @selected($rule?->is_working_day)>Hari kerja</option>
                                                                <option value="0" @selected(! $rule?->is_working_day)>Libur / tanpa jadwal</option>
                                                            </select>
                                                        </td>
                                                        <td class="px-3 py-2"><input name="rules[{{ $day }}][start_time]" type="time" value="{{ $rule?->start_time ? substr((string) $rule->start_time, 0, 5) : '' }}" aria-label="Jam masuk {{ $label }}" class="w-full rounded-lg border-slate-300 text-sm"></td>
                                                        <td class="px-3 py-2"><input name="rules[{{ $day }}][end_time]" type="time" value="{{ $rule?->end_time ? substr((string) $rule->end_time, 0, 5) : '' }}" aria-label="Jam pulang {{ $label }}" class="w-full rounded-lg border-slate-300 text-sm"></td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </form>
                            </div>
                        </details>
                    @empty
                        <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">Belum ada konfigurasi. Tambahkan jadwal normal agar absensi kategori ini dapat diproses dengan jadwal yang jelas.</p>
                    @endforelse

                    <details class="group rounded-xl border border-dashed border-slate-300">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-xl p-4 font-semibold text-cyan-800 outline-none focus-visible:ring-2 focus-visible:ring-cyan-700 focus-visible:ring-offset-2 [&::-webkit-details-marker]:hidden">
                            <span>Tambah konfigurasi jadwal</span>
                            <svg aria-hidden="true" class="h-4 w-4 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.22 7.47a.75.75 0 0 1 1.06 0L10 11.19l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.53a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                            </svg>
                        </summary>
                        <form data-api-form data-endpoint="{{ route('work-schedule-categories.profiles.store', $category) }}" data-reload-on-success="true" class="border-t border-slate-200 p-4">
                            <div class="flex flex-wrap items-end gap-3">
                                <div class="min-w-52 flex-1">
                                    <label for="new-profile-name-{{ $category->id }}" class="mb-1 block text-sm font-medium text-slate-700">Nama konfigurasi</label>
                                    <input id="new-profile-name-{{ $category->id }}" name="name" required maxlength="255" placeholder="Contoh: Ramadan" class="w-full rounded-lg border-slate-300 text-sm">
                                </div>
                                <div class="min-w-48">
                                    <label for="new-profile-kind-{{ $category->id }}" class="mb-1 block text-sm font-medium text-slate-700">Jenis konfigurasi</label>
                                    <select id="new-profile-kind-{{ $category->id }}" name="is_normal" class="w-full rounded-lg border-slate-300 text-sm">
                                        <option value="0">Jadwal Alternatif</option>
                                        <option value="1">Jadwal Normal</option>
                                    </select>
                                </div>
                                <button type="submit" class="rounded-lg bg-cyan-800 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-900">Simpan jadwal</button>
                                <p data-form-status role="status" aria-live="polite" class="text-sm text-slate-600"></p>
                            </div>
                            <div class="mt-4 overflow-x-auto rounded-lg border border-slate-200 bg-white">
                                <table class="min-w-[680px] w-full divide-y divide-slate-200 text-left text-sm">
                                    <thead class="bg-slate-50 text-slate-600">
                                        <tr><th class="px-3 py-2 font-medium">Hari</th><th class="px-3 py-2 font-medium">Status hari</th><th class="px-3 py-2 font-medium">Jam masuk</th><th class="px-3 py-2 font-medium">Jam pulang</th></tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($days as $day => $label)
                                            <tr>
                                                <td class="whitespace-nowrap px-3 py-2 font-medium text-slate-800">{{ $label }}</td>
                                                <td class="px-3 py-2">
                                                    <select name="rules[{{ $day }}][is_working_day]" class="w-full rounded-lg border-slate-300 text-sm">
                                                        <option value="1" @selected($day !== 0)>Hari kerja</option>
                                                        <option value="0" @selected($day === 0)>Libur / tanpa jadwal</option>
                                                    </select>
                                                </td>
                                                <td class="px-3 py-2"><input name="rules[{{ $day }}][start_time]" type="time" aria-label="Jam masuk {{ $label }}" class="w-full rounded-lg border-slate-300 text-sm"></td>
                                                <td class="px-3 py-2"><input name="rules[{{ $day }}][end_time]" type="time" aria-label="Jam pulang {{ $label }}" class="w-full rounded-lg border-slate-300 text-sm"></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    </details>
                </div>
            </section>
        @empty
            <section class="rounded-xl border border-slate-200 bg-white px-5 py-10 text-center text-sm text-slate-500">Belum ada kategori jam kerja.</section>
        @endforelse
    </div>
</x-app-layout>
