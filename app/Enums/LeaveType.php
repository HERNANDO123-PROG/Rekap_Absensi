<?php

namespace App\Enums;

enum LeaveType: string
{
    case Late = 'terlambat';
    case Absent = 'tidak_hadir';
    case EarlyDeparture = 'pulang_awal';
    case OfficialDuty = 'dinas';
    case TemporaryExit = 'keluar';
}
