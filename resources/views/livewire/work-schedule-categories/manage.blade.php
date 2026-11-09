@php
    $categories = \App\Models\WorkScheduleCategory::query()
        ->with(['workScheduleProfile.rules', 'profiles.rules'])
        ->orderByDesc('is_active')
        ->orderBy('name')
        ->get();
    $days = [0 => 'Minggu', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu'];
    $activeTab = request()->query('tab') === 'periode-khusus' ? 'periode-khusus' : 'jam-normal';
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
                <a href="{{ route('work-schedule-categories.manage') }}" class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold {{ $activeTab === 'jam-normal' ? 'bg-cyan-800 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    <span aria-hidden="true">▦</span>
                    Kategori Jam Normal
                </a>
                <a href="{{ route('work-schedule-categories.manage', ['tab' => 'periode-khusus']) }}" class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold {{ $activeTab === 'periode-khusus' ? 'bg-cyan-800 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    <span aria-hidden="true">★</span>
                    Periode Khusus
                </a>
            </div>

            @if ($activeTab === 'jam-normal')
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
            @else
            <div
                x-data="{
                    periods: [],
                    formOpen: false,
                    editingPeriodId: null,
                    periodName: '',
                    startDate: '',
                    endDate: '',
                    categories: @js($categories->map(fn ($category) => ['id' => $category->id, 'name' => $category->name])->values()),
                    selectedCategories: [],
                    activeCategory: null,
                    async loadPeriods() {
                        const response = await fetch('{{ route('special-work-schedule-periods.index') }}', { headers: { Accept: 'application/json' } });
                        if (!response.ok) return;
                        const payload = await response.json();
                        this.periods = payload.data.map(period => ({
                            id: period.id,
                            name: period.name,
                            startDate: period.start_date.substring(0, 10),
                            endDate: period.end_date.substring(0, 10),
                            categories: period.period_categories.map(entry => ({
                                id: entry.work_schedule_category_id,
                                name: entry.category.name,
                                profileId: entry.work_schedule_profile_id,
                                rules: entry.profile.rules
                            }))
                        }));
                    },
                    openPeriodForm(period = null) {
                        this.editingPeriodId = period?.id ?? null;
                        this.periodName = period?.name ?? '';
                        this.startDate = period?.startDate ?? '';
                        this.endDate = period?.endDate ?? '';
                        this.selectedCategories = period?.categories.map(category => category.id) ?? [];
                        this.activeCategory = null;
                        this.formOpen = true;
                    },
                    async addPeriod() {
                        if (!this.periodName.trim() || !this.startDate || !this.endDate) return;
                        const editing = this.editingPeriodId !== null;
                        const url = editing
                            ? `/special-work-schedule-periods/${this.editingPeriodId}`
                            : '{{ route('special-work-schedule-periods.store') }}';
                        const response = await fetch(url, {
                            method: editing ? 'PUT' : 'POST',
                            headers: {
                                Accept: 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                            },
                            body: JSON.stringify({
                                name: this.periodName.trim(),
                                start_date: this.startDate,
                                end_date: this.endDate,
                                category_ids: this.selectedCategories
                            })
                        });
                        if (!response.ok) return;
                        this.editingPeriodId = null;
                        this.periodName = '';
                        this.startDate = '';
                        this.endDate = '';
                        this.selectedCategories = [];
                        this.activeCategory = null;
                        this.formOpen = false;
                        await this.loadPeriods();
                    },
                    async saveSpecialSchedule(category) {
                        const form = document.querySelector('[data-special-category-form]');
                        const rows = form.querySelectorAll('[data-special-day]');
                        const data = new FormData();
                        data.append('_method', 'PUT');
                        data.append('name', category.name);
                        data.append('is_normal', '0');

                        rows.forEach((row) => {
                            const day = row.dataset.specialDay;
                            const working = row.querySelector('[data-special-working]').value === '1';
                            data.append(`rules[${day}][is_working_day]`, working ? '1' : '0');
                            data.append(`rules[${day}][start_time]`, row.querySelector('[data-special-start]').value || '');
                            data.append(`rules[${day}][end_time]`, row.querySelector('[data-special-end]').value || '');
                        });

                        await fetch(`/work-schedule-categories/${category.id}/profiles/${category.profileId}`, {
                            method: 'POST',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                            },
                            body: data,
                        });
                        await this.loadPeriods();
                        this.formOpen = false;
                        this.activeCategory = null;
                    },
                    formatDate(value) {
                        if (!value) return '-';
                        return new Date(value + 'T00:00:00').toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
                    }
                }"
                x-init="loadPeriods()"
                class="p-5"
            >
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 pb-4">
                    <div>
                        <h3 class="font-semibold text-slate-900">Periode Khusus</h3>
                        <p class="mt-1 text-xs text-slate-500">Kelola periode dengan jadwal yang berbeda dari jam normal.</p>
                    </div>
                    <button
                        type="button"
                        x-on:click="openPeriodForm()"
                        class="rounded-lg bg-cyan-800 px-4 py-2 text-xs font-semibold text-white hover:bg-cyan-900"
                    >
                        + Tambah Periode
                    </button>
                </div>

                <div class="mt-5 grid gap-5">
                    <div class="space-y-4">
                        <template x-if="periods.length === 0 && ! formOpen">
                            <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-5 py-12 text-center">
                                <p class="text-sm font-semibold text-slate-700">Belum ada periode khusus</p>
                                <p class="mt-1 text-xs text-slate-500">Tekan "Tambah Periode" untuk mencoba membuat periode.</p>
                            </div>
                        </template>

                        <template x-for="period in periods" x-bind:key="period.name + period.startDate">
                            <article class="rounded-xl border border-slate-200 bg-white">
                                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-4 py-4">
                                    <div>
                                        <h4 class="font-semibold text-slate-900" x-text="period.name"></h4>
                                        <p class="mt-1 text-xs text-slate-500">
                                            <span x-text="formatDate(period.startDate)"></span>
                                            <span class="px-1">s.d.</span>
                                            <span x-text="formatDate(period.endDate)"></span>
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        x-on:click="openPeriodForm(period)"
                                        class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:border-cyan-700 hover:bg-cyan-50"
                                    >
                                        Edit
                                    </button>
                                </div>
                                <div class="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">
                                    <template x-for="category in period.categories" x-bind:key="category.id">
                                        <button
                                            type="button"
                                            x-on:click="activeCategory = category; formOpen = true"
                                            class="rounded-lg border border-slate-300 px-4 py-5 text-center text-sm font-medium text-slate-700 hover:border-cyan-700 hover:bg-cyan-50"
                                            x-text="category.name"
                                        ></button>
                                    </template>
                                    <p x-show="period.categories.length === 0" class="text-xs text-slate-500 sm:col-span-2 xl:col-span-3">Belum ada kategori dipilih.</p>
                                </div>
                            </article>
                        </template>
                    </div>

                </div>

                <div x-show="formOpen" x-cloak x-on:keydown.escape.window="formOpen = false" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <aside x-on:click.outside="formOpen = false" x-bind:class="activeCategory ? 'max-w-4xl' : 'max-w-lg'" class="w-full rounded-xl bg-white shadow-xl">
                        <template x-if="activeCategory === null">
                            <div>
                                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                                    <h3 class="font-semibold text-slate-900" x-text="editingPeriodId ? 'Edit Periode' : 'Tambah Periode'"></h3>
                                    <button type="button" x-on:click="formOpen = false" class="text-2xl leading-none text-slate-400 hover:text-slate-700">&times;</button>
                                </div>
                                <div class="space-y-3 p-5">
                                    <div>
                                        <label class="mb-1 block text-xs font-semibold text-slate-700">Nama periode</label>
                                        <input x-model="periodName" type="text" class="w-full rounded-lg border-slate-300 text-sm" placeholder="">
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-semibold text-slate-700">Tanggal mulai</label>
                                        <input x-model="startDate" type="date" class="w-full rounded-lg border-slate-300 text-sm">
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-semibold text-slate-700">Tanggal selesai</label>
                                        <input x-model="endDate" type="date" class="w-full rounded-lg border-slate-300 text-sm">
                                    </div>
                                    <fieldset>
                                        <legend class="mb-2 text-xs font-semibold text-slate-700">Daftar kategori</legend>
                                        <div class="grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:grid-cols-2">
                                            <template x-for="category in categories" x-bind:key="category.id">
                                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                                    <input type="checkbox" x-bind:value="category.id" x-model="selectedCategories" class="rounded border-slate-300 text-cyan-700">
                                                    <span x-text="category.name"></span>
                                                </label>
                                            </template>
                                            <p x-show="categories.length === 0" class="text-xs text-slate-500 sm:col-span-2">Belum ada kategori yang tersedia.</p>
                                        </div>
                                    </fieldset>
                                </div>
                                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-4">
                                    <button type="button" x-on:click="formOpen = false" class="rounded-lg border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Batal</button>
                                    <button type="button" x-on:click="addPeriod()" class="rounded-lg bg-cyan-800 px-4 py-2 text-xs font-semibold text-white hover:bg-cyan-900" x-text="editingPeriodId ? 'Simpan Perubahan' : 'Simpan Periode'"></button>
                                </div>
                            </div>
                        </template>

                        <template x-if="activeCategory !== null">
                            <div>
                                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                                    <div>
                                        <h3 class="font-semibold text-slate-900" x-text="activeCategory.name"></h3>
                                        <p class="mt-1 text-xs text-slate-500">Jadwal periode khusus</p>
                                    </div>
                                    <button type="button" x-on:click="formOpen = false; activeCategory = null" class="text-2xl leading-none text-slate-400 hover:text-slate-700">&times;</button>
                                </div>
                                <div data-special-category-form class="max-h-[70vh] overflow-y-auto p-5">
                                    <button type="button" x-on:click="activeCategory = null; formOpen = false" class="text-xs font-semibold text-cyan-700 hover:text-cyan-900">&larr; Kembali ke periode</button>
                                    <div class="mt-4 overflow-x-auto rounded-lg border border-slate-200 bg-white">
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
                                                @php($rule = null)
                                                <tr data-special-day="{{ $day }}">
                                                    <td class="px-3 py-2 font-medium text-slate-800">{{ $label }}</td>
                                                    <td class="px-3 py-2">
                                                        <select data-special-working x-bind:value="activeCategory?.rules.find(rule => Number(rule.day_of_week) === {{ $day }})?.is_working_day ? '1' : '0'" class="rounded-lg border-slate-300 py-1 text-xs">
                                                            <option value="1">Hari Kerja</option>
                                                            <option value="0">Libur</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-3 py-2"><input data-special-start x-bind:value="activeCategory?.rules.find(rule => Number(rule.day_of_week) === {{ $day }})?.start_time?.substring(0, 5) || ''" type="time" class="w-full rounded-lg border-slate-300 py-1 text-xs"></td>
                                                    <td class="px-3 py-2"><input data-special-end x-bind:value="activeCategory?.rules.find(rule => Number(rule.day_of_week) === {{ $day }})?.end_time?.substring(0, 5) || ''" type="time" class="w-full rounded-lg border-slate-300 py-1 text-xs"></td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between gap-3 border-t border-slate-200 px-5 py-4">
                                    <p class="text-xs text-slate-500">Perubahan hanya tersimpan sementara pada frontend.</p>
                                    <button type="button" x-on:click="saveSpecialSchedule(activeCategory)" class="rounded-lg bg-cyan-800 px-4 py-2 text-xs font-semibold text-white hover:bg-cyan-900">Simpan jam</button>
                                </div>
                            </div>
                        </template>
                    </aside>
                </div>
            </div>
            @endif
        </section>

    </div>
</x-app-layout>
