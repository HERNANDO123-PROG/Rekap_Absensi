<?php

namespace App\Enums;

enum AbsenceReason: string
{
    case Sick = 'sakit';
    case Permission = 'izin';
    case Unknown = 'tidak_diketahui';
}
