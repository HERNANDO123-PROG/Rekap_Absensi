<x-app-layout>
    <x-slot name="header">
        <div class="flex min-w-0 flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <nav aria-label="Breadcrumb" class="mb-2 text-xs text-slate-500">
                    <a href="{{ route('dashboard') }}" class="hover:text-cyan-700">Dashboard</a>
                    <span aria-hidden="true" class="px-1.5">›</span>
                    <span aria-current="page" class="font-medium text-slate-700">Pegawai</span>
                </nav>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-600">Master data</p>
                <h2 class="mt-1 text-2xl font-bold text-slate-900">Kelola Pegawai</h2>
                <p class="mt-1 max-w-3xl text-sm text-slate-600">Kelola data pegawai dan profil jam kerja sesuai kebijakan yang berlaku.</p>
            </div>
            <span class="shrink-0 rounded-full bg-cyan-100 px-3 py-1 text-xs font-semibold text-cyan-700">{{ \App\Models\Employee::query()->count() }} data</span>
        </div>
    </x-slot>

    <div class="min-w-0 space-y-6">
        @php($activeCategories = $categories->where('is_active', true))
        <div class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ([
                ['label' => 'Pegawai aktif', 'count' => \App\Models\Employee::query()->where('status', \App\Enums\EmployeeStatus::Active->value)->count(), 'icon' => 'users', 'color' => 'cyan'],
                ['label' => 'Pegawai nonaktif', 'count' => \App\Models\Employee::query()->where('status', \App\Enums\EmployeeStatus::Inactive->value)->count(), 'icon' => 'user-minus', 'color' => 'slate'],
                ['label' => 'Alias tersedia', 'count' => \App\Models\EmployeeAlias::query()->count(), 'icon' => 'tag', 'color' => 'slate'],
            ] as $stat)
                <div class="flex min-w-0 items-center gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $stat['color'] === 'cyan' ? 'bg-cyan-50 text-cyan-700' : 'bg-slate-100 text-slate-600' }}" aria-hidden="true">
                        @if ($stat['icon'] === 'users')
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2m16 0v-2a4 4 0 0 0-3-3.87M14 3.13a4 4 0 0 1 0 7.75M14 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z"/></svg>
                        @elseif ($stat['icon'] === 'user-minus')
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2m16-8h-5M14 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z"/></svg>
                        @else
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m20.59 13.41-7.17 7.17a2 2 0 0 1-2.83 0L3 13V4h9l8.59 8.59a2 2 0 0 1 0 2.82ZM7.5 8.5h.01"/></svg>
                        @endif
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm text-slate-500">{{ $stat['label'] }}</p>
                        <p class="mt-1 text-3xl font-bold text-slate-900">{{ $stat['count'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        <section class="min-w-0 overflow-hidden rounded-2xl border border-blue-100 bg-white shadow-sm" aria-labelledby="mass-profile-readiness-title">
            <div class="flex min-w-0 flex-col gap-4 border-b border-blue-100 bg-blue-50/50 p-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-700" aria-hidden="true">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3 4.5 6v5.5c0 4.5 3.1 7.8 7.5 9.5 4.4-1.7 7.5-5 7.5-9.5V6L12 3Z"/><path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4"/></svg>
                    </span>
                    <div class="min-w-0">
                        <h3 id="mass-profile-readiness-title" class="text-lg font-bold text-slate-900">Penerapan Profil Massal</h3>
                        <p class="mt-1 text-sm text-slate-600">Penerapan profil jam kerja dilakukan sesuai cakupan yang disetujui.</p>
                        @unless ($massProfileActionsEnabled)
                            <p id="mass-profile-scope-lock" class="mt-2 text-sm font-medium text-amber-800">Cakupan penerapan belum disetujui. Tombol tetap nonaktif.</p>
                        @endunless
                    </div>
                </div>
                <button type="button" data-open-readiness class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg border border-blue-300 bg-white px-4 py-2.5 text-sm font-semibold text-blue-800 hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2" aria-haspopup="dialog" aria-controls="mass-profile-readiness-dialog">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m16 16 4 4"/></svg>
                    Lihat Detail Kesiapan
                </button>
            </div>

            <div class="grid min-w-0 gap-4 p-4 sm:p-5 xl:grid-cols-2">
                @foreach (['Normal' => 'normal', 'Liburan Semester' => 'liburan-semester'] as $targetName => $targetSlug)
                    @php($readiness = $massProfileReadiness[$targetName])
                    @php($targetReady = $readiness['is_ready'])
                    @php($targetActionEnabled = $massProfileActionsEnabled && $targetReady)
                    @php($targetBlockedCount = $readiness['blocked_categories']->count())
                    <article class="min-w-0 rounded-xl border {{ $targetReady ? 'border-emerald-200 bg-emerald-50/30' : 'border-amber-200 bg-amber-50/30' }} p-4 sm:p-5">
                        <div class="flex min-w-0 items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $targetReady ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}" aria-hidden="true">
                                    @if ($targetReady)
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg>
                                    @else
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 8v5m0 3h.01"/></svg>
                                    @endif
                                </span>
                                <div class="min-w-0">
                                    <h4 class="break-words font-bold text-slate-900">Profil {{ $targetName }}</h4>
                                    <p class="mt-1 break-words text-sm {{ $targetReady ? 'text-emerald-800' : 'text-amber-900' }}">
                                        {{ $targetReady ? 'Semua kategori aktif memiliki profil target valid.' : 'Belum siap, terdapat kategori aktif yang belum memiliki profil target valid.' }}
                                    </p>
                                </div>
                            </div>
                            <span class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold {{ $targetReady ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }}">
                                {{ $targetReady ? 'Siap' : 'Belum siap' }}
                            </span>
                        </div>

                        <div class="mt-4 flex min-w-0 items-start gap-2 border-t border-slate-200/80 pt-4 text-sm text-slate-600">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2m16 0v-2a4 4 0 0 0-3-3.87M14 3.13a4 4 0 0 1 0 7.75M14 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z"/></svg>
                            <span class="min-w-0 break-words"><strong data-ready-employee-count="{{ $readiness['ready_category_employee_count'] }}" class="font-semibold text-slate-800">{{ $readiness['ready_category_employee_count'] }}</strong> pegawai aktif di kategori dengan target siap</span>
                        </div>

                        @if (! $massProfileActionsEnabled)
                            <p class="mt-3 rounded-lg border border-amber-200 bg-white/70 p-3 text-sm text-amber-900">Menunggu persetujuan cakupan aktif-saja.</p>
                        @elseif (! $targetReady)
                            <p id="mass-profile-action-blocked-{{ $targetSlug }}" class="mt-3 rounded-lg border border-amber-200 bg-white/70 p-3 text-sm text-amber-900">
                                Masih ada {{ $targetBlockedCount }} kategori aktif yang belum memiliki profil {{ $targetName }} valid.
                            </p>
                        @else
                            <p id="mass-profile-action-ready-{{ $targetSlug }}" class="mt-3 rounded-lg border border-emerald-200 bg-white/70 p-3 text-sm text-emerald-800">
                                Semua kategori aktif memiliki profil {{ $targetName }} yang valid.
                            </p>
                        @endif

                        <button
                            type="button"
                            data-mass-profile-preview
                            data-target="{{ $targetSlug }}"
                            data-preview-endpoint="{{ route('employees.mass-profile.preview', $targetSlug) }}"
                            data-apply-endpoint="{{ route('employees.mass-profile.apply', $targetSlug) }}"
                            @disabled(! $targetActionEnabled)
                            aria-describedby="{{ ! $massProfileActionsEnabled ? 'mass-profile-scope-lock' : ($targetReady ? 'mass-profile-action-ready-'.$targetSlug : 'mass-profile-action-blocked-'.$targetSlug) }}"
                            class="mt-4 inline-flex w-full min-w-0 items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-offset-2 {{ $targetActionEnabled ? 'bg-blue-600 text-white hover:bg-blue-700 focus:ring-blue-500' : 'cursor-not-allowed bg-slate-200 text-slate-500 focus:ring-slate-400' }}"
                        >
                            @if ($targetActionEnabled)
                                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                            @else
                                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="10" rx="2"/><path stroke-linecap="round" d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                            @endif
                            Terapkan {{ $targetName }}
                        </button>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="min-w-0 rounded-2xl border border-blue-100 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="mass-profile-info-title">
            <div class="flex items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-100 font-bold text-blue-700" aria-hidden="true">i</span>
                <div class="min-w-0">
                    <h3 id="mass-profile-info-title" class="font-semibold text-slate-900">Informasi Penting</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm leading-6 text-slate-600">
                        <li>Cakupan penerapan hanya kategori aktif; kategori nonaktif dikecualikan.</li>
                        <li>Normal dan Liburan Semester memiliki pemeriksaan kesiapan yang terpisah.</li>
                        <li>Penerapan tidak berjalan otomatis. Pratinjau dan konfirmasi eksplisit tetap diperlukan.</li>
                    </ul>
                </div>
            </div>
        </section>

        <dialog id="mass-profile-readiness-dialog" aria-labelledby="mass-profile-readiness-dialog-title" class="m-auto w-[min(48rem,calc(100%-1rem))] max-h-[calc(100dvh-2rem)] overflow-hidden rounded-2xl border border-slate-200 p-0 shadow-2xl backdrop:bg-slate-950/50">
            <div class="grid max-h-[calc(100dvh-2rem)] grid-rows-[auto_minmax(0,1fr)_auto]">
                <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-4 sm:px-6">
                    <div class="min-w-0">
                        <h3 id="mass-profile-readiness-dialog-title" class="text-lg font-bold text-slate-900 sm:text-xl">Detail Kesiapan Profil</h3>
                        <p class="mt-1 text-sm text-slate-600">Status dan alasan kesiapan untuk setiap kategori.</p>
                    </div>
                    <form method="dialog" class="shrink-0">
                        <button type="submit" autofocus aria-label="Tutup detail kesiapan" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-500">Tutup</button>
                    </form>
                </div>
                <div class="min-h-0 overflow-y-auto px-4 py-4 sm:px-6">
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($massProfileReadiness as $targetName => $readiness)
                            <div class="min-w-0 rounded-xl border {{ $readiness['is_ready'] ? 'border-emerald-200 bg-emerald-50/50' : 'border-amber-200 bg-amber-50/50' }} p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <h4 class="min-w-0 break-words text-sm font-semibold text-slate-900">Profil {{ $targetName }}</h4>
                                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold {{ $readiness['is_ready'] ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }}">{{ $readiness['is_ready'] ? 'Siap' : 'Belum siap' }}</span>
                                </div>
                                <p class="mt-2 text-sm text-slate-700">{{ $readiness['ready_category_employee_count'] }} pegawai aktif pada kategori dengan target siap.</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-5 flex flex-wrap gap-2 border-b border-slate-200" role="group" aria-label="Pilih target kesiapan">
                        @foreach (['Normal', 'Liburan Semester'] as $targetName)
                            <button type="button" data-readiness-target="{{ $targetName }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}" class="rounded-t-lg border-b-2 px-4 py-2 text-sm font-semibold {{ $loop->first ? 'border-blue-600 text-blue-800' : 'border-transparent text-slate-600 hover:text-slate-900' }} focus:outline-none focus:ring-2 focus:ring-blue-500">
                                {{ $targetName }}
                            </button>
                        @endforeach
                    </div>

                    @foreach ($massProfileReadiness as $targetName => $readiness)
                        <section data-readiness-panel="{{ $targetName }}" @if ($targetName !== 'Normal') hidden @endif aria-label="Kesiapan profil {{ $targetName }}" class="mt-3">
                            <ul class="divide-y divide-slate-200 rounded-xl border border-slate-200">
                                @foreach ($readiness['categories'] as $categoryReadiness)
                                    <li class="grid min-w-0 gap-2 p-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start sm:p-4">
                                        <div class="min-w-0">
                                            <p class="break-words text-sm font-semibold text-slate-900">{{ $categoryReadiness['name'] }}{{ $categoryReadiness['is_active'] ? '' : ' (Nonaktif)' }}</p>
                                            <p class="mt-1 text-sm text-slate-600">{{ $categoryReadiness['active_employee_count'] }} pegawai aktif</p>
                                        </div>
                                        <p class="min-w-0 break-words text-sm sm:max-w-xs sm:text-right">
                                            @if ($categoryReadiness['is_excluded'])
                                                <span class="font-semibold text-slate-600">Dikecualikan</span>
                                                <span class="text-slate-600"> — {{ $categoryReadiness['reason'] }}</span>
                                            @elseif ($categoryReadiness['is_ready'])
                                                <span class="font-semibold text-emerald-700">Siap</span>
                                                <span class="text-slate-600"> — {{ $categoryReadiness['profile_name'] }}</span>
                                            @else
                                                <span class="font-semibold text-amber-800">Belum siap</span>
                                                <span class="text-amber-800"> — {{ $categoryReadiness['reason'] }}</span>
                                            @endif
                                        </p>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                </div>
                <div class="border-t border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600 sm:px-6">
                    Detail ini hanya menampilkan kesiapan. Membukanya tidak memulai pratinjau atau penerapan.
                </div>
            </div>
        </dialog>

        <dialog id="mass-profile-dialog" aria-labelledby="mass-profile-title" aria-describedby="mass-profile-dialog-description" class="m-auto w-[min(44rem,calc(100%-1rem))] max-h-[calc(100dvh-2rem)] overflow-y-auto rounded-2xl border border-slate-200 p-0 shadow-2xl backdrop:bg-slate-950/50">
            <div class="p-6">
                <h3 id="mass-profile-title" data-mass-profile-title class="text-xl font-bold text-slate-900"></h3>
                <p id="mass-profile-dialog-description" class="mt-2 text-sm text-slate-600">Periksa cakupan dan dampak sebelum melanjutkan.</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <p class="rounded-lg bg-slate-50 p-3 text-sm text-slate-700">Pegawai aktif memenuhi syarat: <strong data-mass-profile-eligible>0</strong></p>
                    <p class="rounded-lg bg-slate-50 p-3 text-sm text-slate-700">Assignment yang akan diganti: <strong data-mass-profile-replacements>0</strong></p>
                    <p class="rounded-lg bg-slate-50 p-3 text-sm text-slate-700 sm:col-span-2">Pegawai yang belum memiliki profil target: <strong data-mass-profile-new-assignments>0</strong></p>
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <h4 class="font-semibold text-slate-900">Kategori termasuk</h4>
                        <ul data-mass-profile-included class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-700"></ul>
                    </div>
                    <div>
                        <h4 class="font-semibold text-slate-900">Kategori dikecualikan</h4>
                        <ul data-mass-profile-excluded class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-700"></ul>
                    </div>
                </div>
                <div data-mass-profile-blockers-container class="mt-4 hidden rounded-lg border border-amber-200 bg-amber-50 p-3">
                    <h4 class="font-semibold text-amber-900">Penghalang — aksi tidak dapat dikonfirmasi</h4>
                    <ul data-mass-profile-blockers class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-900"></ul>
                </div>
                <p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                    Assignment profil pegawai akan diganti. Snapshot jadwal pada absensi historis tidak diubah langsung dan tidak ada pemrosesan ulang yang dijalankan.
                </p>
                <p data-mass-profile-status role="status" aria-live="polite" class="mt-3 text-sm text-slate-600"></p>
                <div class="mt-5 flex justify-end gap-2">
                    <form method="dialog"><button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Batal</button></form>
                    <button type="button" data-mass-profile-confirm disabled class="cursor-not-allowed rounded-lg bg-slate-300 px-4 py-2 text-sm font-semibold text-slate-600">Konfirmasi penerapan</button>
                </div>
            </div>
        </dialog>

        <section class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <h3 class="text-lg font-semibold text-slate-900">Tambah pegawai</h3>
            <form data-api-form data-endpoint="{{ route('employees.store') }}" data-reload-on-success="true" class="mt-4 grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div><label for="employee-unit" class="mb-1 block text-sm font-medium text-slate-700">Unit</label><select id="employee-unit" name="unit_id" required class="w-full rounded-lg border-slate-300 text-sm">@foreach ($units as $unit)<option value="{{ $unit->id }}">{{ $unit->code ?? $unit->name }}</option>@endforeach</select></div>
                <div><label for="employee-namafinger" class="mb-1 block text-sm font-medium text-slate-700">Nama Fingerprint</label><input id="employee-namafinger" value="" placeholder="Terisi dari import fingerprint" disabled class="w-full rounded-lg border-slate-300 bg-slate-100 text-sm"></div>
                <div><label for="employee-full-name" class="mb-1 block text-sm font-medium text-slate-700">Nama Lengkap</label><input id="employee-full-name" name="nama_lengkap" maxlength="255" class="w-full rounded-lg border-slate-300 text-sm"></div>
                <div><label for="employee-nip" class="mb-1 block text-sm font-medium text-slate-700">NIP</label><input id="employee-nip" name="nip" maxlength="255" class="w-full rounded-lg border-slate-300 text-sm"></div>
                <div><label for="employee-fingerprint-id" class="mb-1 block text-sm font-medium text-slate-700">ID Fingerprint</label><input id="employee-fingerprint-id" name="fingerprint_id" maxlength="64" class="w-full rounded-lg border-slate-300 text-sm"></div>
                <div><label for="employee-position" class="mb-1 block text-sm font-medium text-slate-700">Jabatan</label><input id="employee-position" name="position" maxlength="255" class="w-full rounded-lg border-slate-300 text-sm"></div>
                <div>
                    <label for="employee-category" class="mb-1 block text-sm font-medium text-slate-700">Kategori</label>
                    <select id="employee-category" name="category_id" required data-schedule-category-select class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="">Pilih kategori</option>
                        @foreach ($activeCategories as $category)
                            <option value="{{ $category->id }}" data-category-active="true">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="employee-work-profile" class="mb-1 block text-sm font-medium text-slate-700">Jam Kerja</label>
                    <select id="employee-work-profile" name="work_schedule_profile_id" data-schedule-profile-select class="w-full rounded-lg border-slate-300 text-sm" disabled>
                        <option value="">Belum dipilih</option>
                        @foreach ($activeCategories as $category)
                            @foreach ($selectableProfiles->get($category->id, collect()) as $profile)
                                <option value="{{ $profile->id }}" data-category-id="{{ $category->id }}" hidden>{{ $profile->name }}</option>
                            @endforeach
                        @endforeach
                    </select>
                </div>
                <div><label for="employee-status" class="mb-1 block text-sm font-medium text-slate-700">Status</label><select id="employee-status" name="status" required class="w-full rounded-lg border-slate-300 text-sm"><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select></div>
                <div class="flex flex-wrap items-center gap-3 sm:col-span-2 xl:col-span-4"><button type="submit" class="rounded-lg bg-cyan-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-800">Simpan pegawai</button><p data-form-status role="status" aria-live="polite" class="text-sm text-slate-600"></p></div>
            </form>
        </section>

        <div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-lg font-semibold text-slate-900">Daftar pegawai</h3>
                <a href="{{ route('dashboard') }}" class="rounded-xl border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Lihat dashboard</a>
            </div>

            <form method="GET" action="{{ route('employees.manage') }}" class="mb-4 grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.5fr)_auto_auto] xl:items-end">
                <div>
                    <label for="employee-filter-unit" class="mb-1 block text-sm font-medium text-slate-700">Unit</label>
                    <select id="employee-filter-unit" name="unit_id" class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="">Semua Unit</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}" @selected((string) request('unit_id') === (string) $unit->id)>{{ $unit->code ?? $unit->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="employee-filter-category" class="mb-1 block text-sm font-medium text-slate-700">Kategori Jam Kerja</label>
                    <select id="employee-filter-category" name="category_id" class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="">Semua Kategori</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) request('category_id') === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="employee-filter-status" class="mb-1 block text-sm font-medium text-slate-700">Status Pegawai</label>
                    <select id="employee-filter-status" name="status" class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="" @selected(! request()->filled('status'))>Semua</option>
                        <option value="active" @selected(request('status') === 'active')>Active</option>
                        <option value="inactive" @selected(request('status') === 'inactive')>Nonactive</option>
                    </select>
                </div>
                <div>
                    <label for="employee-filter-search" class="mb-1 block text-sm font-medium text-slate-700">Cari pegawai</label>
                    <input id="employee-filter-search" name="search" value="{{ request('search') }}" placeholder="Nama fingerprint, nama lengkap, NIP, atau ID" class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <button type="submit" class="rounded-lg bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">Filter</button>
                <a href="{{ route('employees.manage') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">Reset Filter</a>
            </form>

            <div class="employee-table-scroll min-w-0 overflow-x-auto rounded-lg border border-slate-200">
                <table class="min-w-[1120px] divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="min-w-64 px-4 py-3 font-medium">Nama Lengkap</th>
                            <th class="min-w-56 px-4 py-3 font-medium">Nama Fingerprint</th>
                            <th class="px-4 py-3 font-medium">Jabatan</th>
                            <th class="px-4 py-3 font-medium">Kategori</th>
                            <th class="px-4 py-3 font-medium">Jam Kerja</th>
                            <th class="px-4 py-3 font-medium">NIP</th>
                            <th class="px-4 py-3 font-medium">Unit</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="min-w-48 px-4 py-3 font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse ($employees as $employee)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <span data-edit-view>{{ $employee->nama_lengkap ?: '—' }}</span>
                                    <input form="employee-edit-{{ $employee->id }}" data-edit-input name="nama_lengkap" value="{{ $employee->nama_lengkap }}" maxlength="255" aria-label="Nama lengkap untuk {{ $employee->displayName() }}" placeholder="—" class="hidden w-full rounded-lg border-slate-300 px-2 py-1.5 text-sm font-medium text-slate-800" disabled>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-800">{{ $employee->namafinger ?: '—' }}</div>
                                </td>
                                <td class="min-w-56 px-4 py-3">
                                    <span data-edit-view>{{ $employee->position ?: '—' }}</span>
                                    <input form="employee-edit-{{ $employee->id }}" data-edit-input name="position" value="{{ $employee->position }}" maxlength="255" aria-label="Jabatan untuk {{ $employee->displayName() }}" placeholder="—" class="hidden w-full rounded-lg border-slate-300 px-2 py-1.5 text-sm" disabled>
                                </td>
                                <td class="min-w-48 px-4 py-3">
                                    <span data-edit-view class="text-sm font-medium text-slate-800">
                                        @if ($employee->category)
                                            {{ $employee->category->name }}
                                            @unless ($employee->category->is_active) (Nonaktif) @endunless
                                        @else
                                            Belum dikategorikan
                                        @endif
                                    </span>
                                    <select form="employee-edit-{{ $employee->id }}" data-edit-input data-schedule-category-select name="category_id" aria-label="Kategori untuk {{ $employee->displayName() }}" class="hidden w-full rounded-lg border-slate-300 px-2 py-1.5 text-xs" disabled>
                                        <option value="" @selected($employee->category_id === null)>Belum dikategorikan</option>
                                        @if ($employee->category && ! $employee->category->is_active)
                                            <option value="{{ $employee->category_id }}" data-category-active="false" selected>{{ $employee->category->name }} (Nonaktif)</option>
                                        @endif
                                        @foreach ($categories->where('is_active', true) as $category)
                                            <option value="{{ $category->id }}" data-category-active="true" @selected((int) $employee->category_id === (int) $category->id)>{{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="min-w-48 px-4 py-3">
                                    @if ($employee->workScheduleProfile)
                                        <span data-edit-view class="text-sm font-medium text-slate-800">
                                            {{ $employee->workScheduleProfile->name }}{{ $employee->workScheduleProfile->is_active ? '' : ' (Nonaktif)' }}
                                        </span>
                                    @else
                                        <span data-edit-view class="text-sm text-slate-500">Belum dipilih</span>
                                    @endif
                                    <select
                                        form="employee-edit-{{ $employee->id }}"
                                        data-edit-input
                                        data-schedule-profile-select
                                        name="work_schedule_profile_id"
                                        aria-label="Jam kerja untuk {{ $employee->displayName() }}"
                                        class="hidden w-full rounded-lg border-slate-300 px-2 py-1.5 text-xs"
                                        disabled
                                    >
                                        <option value="">Belum dipilih</option>
                                        @if ($employee->workScheduleProfile && $employee->category_id !== null && ! $selectableProfiles->get($employee->category_id, collect())->contains(fn ($profile) => (int) $profile->id === (int) $employee->work_schedule_profile_id))
                                            <option value="{{ $employee->work_schedule_profile_id }}" data-category-id="{{ $employee->category_id }}" data-selectable="false" disabled selected>{{ $employee->workScheduleProfile->name }} (tersimpan, tidak dapat dipilih)</option>
                                        @endif
                                        @foreach ($activeCategories as $category)
                                            @foreach ($selectableProfiles->get($category->id, collect()) as $profile)
                                                <option
                                                    value="{{ $profile->id }}"
                                                    data-category-id="{{ $category->id }}"
                                                    @if ((int) $employee->category_id !== (int) $category->id) hidden @endif
                                                    @selected((int) $employee->work_schedule_profile_id === (int) $profile->id)
                                                >{{ $profile->name }}</option>
                                            @endforeach
                                        @endforeach
                                    </select>
                                </td>
                                <td class="min-w-44 px-4 py-3 text-slate-600">
                                    <span data-edit-view>{{ $employee->nip ?: '—' }}</span>
                                    <input form="employee-edit-{{ $employee->id }}" data-edit-input name="nip" value="{{ $employee->nip }}" maxlength="255" aria-label="NIP untuk {{ $employee->displayName() }}" placeholder="—" class="hidden w-full rounded-lg border-slate-300 px-2 py-1.5 text-sm" disabled>
                                </td>
                                <td class="min-w-36 px-4 py-3 text-slate-600">
                                    <span data-edit-view>{{ $employee->unit?->code ?? $employee->unit?->name ?? '—' }}</span>
                                    <select form="employee-edit-{{ $employee->id }}" data-edit-input name="unit_id" required aria-label="Unit untuk {{ $employee->displayName() }}" class="hidden w-full rounded-lg border-slate-300 px-2 py-1.5 text-sm" disabled>
                                        @foreach ($units as $unit)
                                            <option value="{{ $unit->id }}" @selected((int) $employee->unit_id === (int) $unit->id)>{{ $unit->code ?? $unit->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $employee->status->value === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700' }}">
                                        {{ $employee->status->value === 'active' ? 'Active' : 'Nonactive' }}
                                    </span>
                                </td>
                                <td class="min-w-48 px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" data-employee-edit="{{ $employee->id }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Edit</button>
                                        <button type="submit" form="employee-edit-{{ $employee->id }}" data-edit-save class="hidden rounded-lg bg-cyan-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-cyan-800">Simpan</button>
                                        <button type="button" data-employee-cancel="{{ $employee->id }}" class="hidden rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Batal</button>
                                        @if ($employee->status->value === 'active')
                                            <form data-edit-hide data-api-form data-endpoint="{{ route('employees.deactivate', $employee) }}" data-reload-on-success="true" data-confirm="Nonaktifkan {{ $employee->displayName() }}? Data fingerprint dan riwayat pegawai akan tetap disimpan." class="inline">
                                                <button type="submit" class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">Nonaktifkan</button>
                                                <p data-form-status class="sr-only" role="status" aria-live="polite"></p>
                                            </form>
                                        @else
                                            <form data-edit-hide data-api-form data-endpoint="{{ route('employees.activate', $employee) }}" data-reload-on-success="true" data-confirm="Aktifkan {{ $employee->displayName() }}? Sistem akan memeriksa konflik ID fingerprint." class="inline">
                                                <button type="submit" class="rounded-lg border border-emerald-200 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">Aktifkan</button>
                                                <p data-form-status class="w-full text-xs text-slate-600" role="status" aria-live="polite"></p>
                                            </form>
                                        @endif
                                    </div>
                                    <form id="employee-edit-{{ $employee->id }}" data-api-form data-endpoint="{{ route('employees.update', $employee) }}" data-reload-on-success="true" class="hidden" data-employee-profile-form="{{ $employee->id }}">
                                        <input type="hidden" name="_method" value="PUT">
                                        <p data-form-status class="mt-2 text-xs" role="status" aria-live="polite"></p>
                                    </form>
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-xs font-medium text-slate-600">Nama alternatif pencocokan ({{ $employee->aliases->count() }})</summary>
                                        <div class="mt-2 space-y-2">
                                            @forelse ($employee->aliases as $alias)
                                                <span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-700">{{ $alias->alias_name }}</span>
                                            @empty
                                                <p class="text-xs text-slate-500">Belum ada nama alternatif.</p>
                                            @endforelse
                                            <form data-api-form data-endpoint="{{ route('employees.aliases.store', $employee) }}" data-reload-on-success="true" class="flex flex-wrap gap-2">
                                                <input name="alias_name" required maxlength="255" aria-label="Nama alternatif pencocokan untuk {{ $employee->displayName() }}" placeholder="Tambah nama alternatif" class="min-w-0 flex-1 rounded-lg border-slate-300 px-2 py-1 text-xs">
                                                <button type="submit" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">Tambah</button>
                                                <p data-form-status class="sr-only" role="status" aria-live="polite"></p>
                                            </form>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-8 text-center text-slate-500">Tidak ada pegawai yang sesuai dengan filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4 flex flex-col gap-3 border-t border-slate-200 pt-4 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between">
                <p>Menampilkan {{ $employees->firstItem() ?? 0 }}–{{ $employees->lastItem() ?? 0 }} dari {{ $employees->total() }} pegawai</p>
                <div>{{ $employees->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
