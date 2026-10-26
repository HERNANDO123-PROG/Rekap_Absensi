<?php

namespace App\Enums;

enum LeaveApprovalStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
}
