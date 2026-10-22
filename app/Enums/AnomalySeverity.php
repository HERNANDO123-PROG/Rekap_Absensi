<?php

namespace App\Enums;

enum AnomalySeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
}
