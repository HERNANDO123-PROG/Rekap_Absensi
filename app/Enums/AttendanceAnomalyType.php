<?php

namespace App\Enums;

enum AttendanceAnomalyType: string
{
    case UnmatchedEmployee = 'unmatched_employee';
    case AmbiguousEmployee = 'ambiguous_employee';
    case MissingFingerprint = 'missing_fingerprint';
    case UnexpectedExit = 'unexpected_exit';
    case DuplicateData = 'duplicate_data';
    case InvalidTime = 'invalid_time';
    case Other = 'other';
    case ExitReentryWithoutLeaveRequest = 'exit_reentry_without_leave_request';
    case LeaveRequestWithoutFingerprint = 'leave_request_without_fingerprint';
    case OddFingerprintCountForExit = 'odd_fingerprint_count_for_exit';
    case MultipleLeaveRequests = 'multiple_leave_requests';
    case LeaveAbsenceConflictWithFingerprint = 'leave_absence_conflict_with_fingerprint';
    case DuplicateTimestamp = 'duplicate_timestamp';
    case InvalidSchedule = 'invalid_schedule';
    case MissingSchedule = 'missing_schedule';
}
