<?php

namespace App\Enums;

enum AttendanceIntervalSource: string
{
    case Fingerprint = 'fingerprint';
    case Leave = 'leave';
    case LeaveRequest = 'leave_request';
    case LeaveForm = 'leave_form';
    case System = 'system';
    case Manual = 'manual';
    case FingerprintAndLeaveRequest = 'fingerprint+leave_request';
}
