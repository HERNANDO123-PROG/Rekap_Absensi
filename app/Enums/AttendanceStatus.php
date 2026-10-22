<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'hadir';
    case Late = 'terlambat';
    case EarlyDeparture = 'pulang_awal';
    case Permission = 'izin';
    case Sick = 'sakit';
    case OfficialDuty = 'dinas';
    case TemporaryExit = 'izin_keluar';
    case Absent = 'alpha';
    case Holiday = 'libur';
    case NeedsVerification = 'perlu_verifikasi';
}
