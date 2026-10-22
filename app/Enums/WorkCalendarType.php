<?php

namespace App\Enums;

enum WorkCalendarType: string
{
    case Workday = 'workday';
    case Holiday = 'holiday';
    case SpecialWorkday = 'special_workday';
    case SpecialEvent = 'special_event';
}
