<div class="dashboard-content">
    <form method="GET" action="{{ route('dashboard') }}" class="dashboard-filter">
        <div class="dashboard-filter__field">
            <label for="unit_id" class="dashboard-filter__label">Unit</label>
            <select id="unit_id" name="unit_id" class="dashboard-filter__control">
                    <option value="all" {{ ($selectedUnitId ?? 'all') === 'all' ? 'selected' : '' }}>Semua Unit</option>
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}" {{ (string) ($selectedUnitId ?? 'all') === (string) $unit->id ? 'selected' : '' }}>{{ $unit->name }}</option>
                    @endforeach
            </select>
        </div>

        <div class="dashboard-filter__field">
            <label for="date" class="dashboard-filter__label">Tanggal</label>
            <input id="date" name="date" type="date" value="{{ $selectedDate }}" class="dashboard-filter__control" />
        </div>

        <div class="dashboard-filter__action">
            <button type="submit" class="dashboard-filter__submit">Terapkan</button>
        </div>
    </form>

    <section class="dashboard-statistics" aria-label="Ringkasan absensi">
        <div class="dashboard-section-heading">
            <div>
                <h3 class="dashboard-section-heading__title">Ringkasan Kehadiran</h3>
                <p class="dashboard-section-heading__description">Statistik untuk unit dan tanggal yang dipilih</p>
            </div>
        </div>

        <div class="dashboard-stat-grid">
            <article class="dashboard-stat-card dashboard-stat-card--total">
                <div class="dashboard-stat-card__top">
                    <p class="dashboard-stat-card__label">Total Pegawai Aktif</p>
                    <span class="dashboard-stat-card__indicator" aria-hidden="true"></span>
                </div>
                <div class="dashboard-stat-card__bottom">
                    <span class="dashboard-stat-card__value">{{ $stats['totalEmployees'] }}</span>
                    <span class="dashboard-stat-card__badge">{{ $selectedUnitName }}</span>
                </div>
            </article>

            <article class="dashboard-stat-card dashboard-stat-card--present">
                <div class="dashboard-stat-card__top">
                    <p class="dashboard-stat-card__label">Hadir</p>
                    <span class="dashboard-stat-card__indicator" aria-hidden="true"></span>
                </div>
                <div class="dashboard-stat-card__bottom">
                    <span class="dashboard-stat-card__value">{{ $stats['hadir'] }}</span>
                    <span class="dashboard-stat-card__badge">Tercatat</span>
                </div>
            </article>

            <article class="dashboard-stat-card dashboard-stat-card--late">
                <div class="dashboard-stat-card__top">
                    <p class="dashboard-stat-card__label">Terlambat</p>
                    <span class="dashboard-stat-card__indicator" aria-hidden="true"></span>
                </div>
                <div class="dashboard-stat-card__bottom">
                    <span class="dashboard-stat-card__value">{{ $stats['terlambat'] }}</span>
                    <span class="dashboard-stat-card__badge">Perlu perhatian</span>
                </div>
            </article>

            <article class="dashboard-stat-card dashboard-stat-card--permission">
                <div class="dashboard-stat-card__top">
                    <p class="dashboard-stat-card__label">Izin</p>
                    <span class="dashboard-stat-card__indicator" aria-hidden="true"></span>
                </div>
                <div class="dashboard-stat-card__bottom">
                    <span class="dashboard-stat-card__value">{{ $stats['izin'] }}</span>
                    <span class="dashboard-stat-card__badge">Status</span>
                </div>
            </article>

            <article class="dashboard-stat-card dashboard-stat-card--sick">
                <div class="dashboard-stat-card__top">
                    <p class="dashboard-stat-card__label">Sakit</p>
                    <span class="dashboard-stat-card__indicator" aria-hidden="true"></span>
                </div>
                <div class="dashboard-stat-card__bottom">
                    <span class="dashboard-stat-card__value">{{ $stats['sakit'] }}</span>
                    <span class="dashboard-stat-card__badge">Status</span>
                </div>
            </article>

            <article class="dashboard-stat-card dashboard-stat-card--duty">
                <div class="dashboard-stat-card__top">
                    <p class="dashboard-stat-card__label">Dinas</p>
                    <span class="dashboard-stat-card__indicator" aria-hidden="true"></span>
                </div>
                <div class="dashboard-stat-card__bottom">
                    <span class="dashboard-stat-card__value">{{ $stats['dinas'] }}</span>
                    <span class="dashboard-stat-card__badge">Status</span>
                </div>
            </article>

            <article class="dashboard-stat-card dashboard-stat-card--absent">
                <div class="dashboard-stat-card__top">
                    <p class="dashboard-stat-card__label">Alpha</p>
                    <span class="dashboard-stat-card__indicator" aria-hidden="true"></span>
                </div>
                <div class="dashboard-stat-card__bottom">
                    <span class="dashboard-stat-card__value">{{ $stats['alpha'] }}</span>
                    <span class="dashboard-stat-card__badge">Status</span>
                </div>
            </article>

        </div>
    </section>

    <section class="dashboard-quick-access">
        <div class="dashboard-section-heading">
            <div>
                <h3 class="dashboard-section-heading__title">Akses Cepat</h3>
                <p class="dashboard-section-heading__description">Menu utama pengelolaan absensi</p>
            </div>
        </div>

        <div class="dashboard-quick-grid">
            @foreach ([
                ['label' => 'Upload Fingerprint', 'href' => route('fingerprint-import.index'), 'icon' => 'upload'],
                ['label' => 'Upload Data Izin', 'href' => route('leave-import.index'), 'icon' => 'document'],
                ['label' => 'Pegawai', 'href' => route('employees.manage'), 'icon' => 'users'],
                ['label' => 'Kalender Kerja', 'href' => route('work-calendar.index'), 'icon' => 'calendar'],
                ['label' => 'Kategori Jam Kerja', 'href' => route('work-schedule-categories.manage'), 'icon' => 'clock'],
                ['label' => 'Rekap Periode', 'href' => route('rekap.period'), 'icon' => 'chart'],
            ] as $item)
                <a href="{{ $item['href'] }}" class="dashboard-quick-link">
                    <span class="dashboard-quick-link__icon" aria-hidden="true">
                        @switch($item['icon'])
                            @case('upload')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 14.5v3A2.5 2.5 0 007.5 20h9a2.5 2.5 0 002.5-2.5v-3"/></svg>
                                @break
                            @case('document')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3.75h7l4 4v12.5H7a2 2 0 01-2-2v-12.5a2 2 0 012-2zM14 4v4h4m-9 5h6m-6 3.5h6"/></svg>
                                @break
                            @case('users')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 19v-1.5a3.5 3.5 0 00-3.5-3.5h-5A3.5 3.5 0 004 17.5V19m6-8a3.5 3.5 0 100-7 3.5 3.5 0 000 7zm6-6.5a3.5 3.5 0 010 6.8m2 3.2a3.5 3.5 0 012 3.2V19"/></svg>
                                @break
                            @case('calendar')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3v3m10-3v3M4.5 9h15m-14-4h13A1.5 1.5 0 0120 6.5v12a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 18.5v-12A1.5 1.5 0 015.5 5zM8 13h2m4 0h2m-8 4h2"/></svg>
                                @break
                            @case('clock')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="8.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3.5 2"/></svg>
                                @break
                            @case('chart')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19.5h16M6.5 16V11m5.5 5V5m5.5 11V8"/></svg>
                                @break
                        @endswitch
                    </span>
                    <span class="dashboard-quick-link__label">{{ $item['label'] }}</span>
                    <svg class="dashboard-quick-link__arrow" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10h11m-4-4 4 4-4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
            @endforeach
        </div>
    </section>
</div>
