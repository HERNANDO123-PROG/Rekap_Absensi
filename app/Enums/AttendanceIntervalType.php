<?php

namespace App\Enums;

enum AttendanceIntervalType: string
{
    case Attendance = 'attendance';
    case TemporaryExit = 'izin_keluar';
    case OfficialDuty = 'dinas';
    case Other = 'lainnya';
}
