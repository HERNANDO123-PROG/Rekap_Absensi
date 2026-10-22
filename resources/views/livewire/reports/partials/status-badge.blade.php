@php
    $badge = match ($status) {
        'hadir' => ['Hadir', 'bg-emerald-100 text-emerald-800'],
        'terlambat' => ['Terlambat', 'bg-amber-100 text-amber-800'],
        'pulang_awal' => ['Pulang Awal', 'bg-orange-100 text-orange-800'],
        'izin' => ['Izin', 'bg-sky-100 text-sky-800'],
        'sakit' => ['Sakit', 'bg-blue-100 text-blue-800'],
        'dinas' => ['Dinas', 'bg-indigo-100 text-indigo-800'],
        'izin_keluar' => ['Izin Keluar', 'bg-teal-100 text-teal-800'],
        'alpha' => ['Alpha', 'bg-rose-100 text-rose-800'],
        'libur' => ['Libur', 'bg-slate-100 text-slate-700'],
        default => ['Perlu Verifikasi', 'bg-red-100 text-red-800'],
    };
@endphp
<span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold {{ $badge[1] }}">{{ $badge[0] }}</span>