<?php

namespace App\Services\Reports;

use App\Data\Reports\AttendanceFingerprintPeriodReportFilters;
use App\Enums\ImportBatchStatus;
use App\Enums\LeaveType;
use App\Enums\MatchingStatus;
use App\Models\Employee;
use App\Models\FingerprintLog;
use App\Models\LeaveRequest;
use App\Models\WorkCalendar;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfileRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AttendanceFingerprintPeriodReportService
{
    public function generate(AttendanceFingerprintPeriodReportFilters $filters): array
    {
        $allUnitEmployees = Employee::query()
            ->where('unit_id', $filters->unitId)
            ->with(['category.workScheduleProfile.rules', 'workScheduleProfile.rules'])
            ->orderBy('id')
            ->get();

        $employees = Employee::query()
            ->where('unit_id', $filters->unitId)
            ->when(
                $filters->employeeId !== null,
                fn ($query) => $query->whereKey($filters->employeeId),
            )
            ->when(
                $filters->categoryId !== null,
                fn ($query) => $query->where('category_id', $filters->categoryId),
            )
            ->when($filters->search !== null, function ($query) use ($filters): void {
                $term = '%'.$filters->search.'%';
                $query->where(function ($query) use ($term): void {
                    $query->where('nama_lengkap', 'like', $term)
                        ->orWhere('namafinger', 'like', $term)
                        ->orWhere('nip', 'like', $term);
                });
            })
            ->with(['category.workScheduleProfile.rules', 'workScheduleProfile.rules'])
            ->orderByDisplayName()
            ->paginate($filters->perPage, ['*'], 'page', $filters->page);

        $dates = $this->dates($filters);
        $logsByDate = $this->fingerprints($filters);
        $leavesByEmployeeDate = $this->leaveRequests($filters);
        $calendarByDate = WorkCalendar::query()
            ->where('unit_id', $filters->unitId)
            ->whereBetween('date', [$filters->startDate, $filters->endDate])
            ->get()
            ->keyBy(fn (WorkCalendar $calendar): string => $calendar->date->format('Y-m-d'));
        $weeklyByDay = WorkSchedule::query()
            ->where('unit_id', $filters->unitId)
            ->get()
            ->keyBy('day_of_week');

        $dayStatuses = [];
        $provisionalDates = [];
        $unknownDates = [];

        foreach ($dates as $date) {
            $calendar = $calendarByDate->get($date);
            $weekly = $weeklyByDay->get(CarbonImmutable::parse($date)->dayOfWeek);

            if ($calendar !== null) {
                $dayStatuses[$date] = [
                    'status' => $calendar->is_working_day ? 'working' : 'non_working',
                    'source' => 'calendar',
                    'is_provisional' => false,
                    'is_ambiguous' => false,
                ];

                continue;
            }

            if ($weekly !== null) {
                $dayStatuses[$date] = [
                    'status' => $weekly->is_working_day ? 'working' : 'non_working',
                    'source' => 'weekly_schedule',
                    'is_provisional' => false,
                    'is_ambiguous' => false,
                ];

                continue;
            }

            $denominator = $allUnitEmployees->count();
            $uniqueScanned = $logsByDate->get($date, collect())
                ->pluck('employee_id')
                ->filter()
                ->unique()
                ->count();
            $status = $denominator > 0 && $uniqueScanned / $denominator > 0.5
                ? 'working'
                : 'unknown';
            $dayStatuses[$date] = [
                'status' => $status,
                'source' => $status === 'working' ? 'fingerprint_inference' : null,
                'is_provisional' => $status === 'working',
                'is_ambiguous' => $status === 'unknown',
            ];
            if ($status === 'working') {
                $provisionalDates[] = $date;
            } else {
                $unknownDates[] = $date;
            }
        }

        $rows = $employees->getCollection()
            ->map(function (Employee $employee) use (
                $dates,
                $dayStatuses,
                $logsByDate,
                $leavesByEmployeeDate,
                $calendarByDate,
                $weeklyByDay,
            ): array {
                $daily = [];

                foreach ($dates as $date) {
                    $day = $dayStatuses[$date];
                    $logs = $logsByDate->get($date, collect())
                        ->where('employee_id', $employee->id)
                        ->values();
                    $leaveRequests = $leavesByEmployeeDate
                        ->get($employee->id, collect())
                        ->get($date, collect());
                    $leaveTypes = $leaveRequests->pluck('leave_type')->unique()->values();
                    $leaveUnverifiable = $leaveRequests->isNotEmpty();
                    $schedule = $this->scheduleForDate(
                        $employee,
                        $date,
                        $calendarByDate->get($date),
                        $weeklyByDay->get(CarbonImmutable::parse($date)->dayOfWeek),
                    );
                    $scanResult = $this->evaluateScans($logs, $schedule);
                    $statusUnknown = $day['status'] === 'unknown';
                    $isWorking = $day['status'] === 'working';
                    $holiday = $day['status'] === 'non_working';
                    $leaveConflict = $leaveTypes->count() > 1 || $leaveRequests->count() > 1;
                    $ambiguous = $day['is_ambiguous']
                        || $scanResult['is_ambiguous']
                        || (! $holiday && ! $schedule['valid'] && $logs->isNotEmpty())
                        || $leaveConflict
                        || $leaveUnverifiable;

                    $metrics = [
                        'hari_kerja' => $statusUnknown ? null : (int) $isWorking,
                        'total_finger' => $statusUnknown ? null : ($isWorking ? 2 : 0),
                        'hadir' => null,
                        'terlambat' => null,
                        'pulang_awal' => null,
                        'tidak_hadir' => null,
                        'izin_keluar' => null,
                        'dinas' => null,
                    ];

                    if (! $statusUnknown && ! $holiday) {
                        $metrics['hadir'] = (int) ($isWorking && $logs->isNotEmpty());
                        $metrics['tidak_hadir'] = (int) ($isWorking && $logs->isEmpty());
                        $metrics['izin_keluar'] = $leaveUnverifiable ? null : 0;
                        $metrics['dinas'] = $leaveUnverifiable ? null : 0;

                        if ($isWorking && $logs->isNotEmpty()) {
                            if ($scanResult['schedule_valid']) {
                                $metrics['terlambat'] = $scanResult['late'];
                                $metrics['pulang_awal'] = $scanResult['early'];
                            }
                        } elseif ($isWorking && $schedule['valid']) {
                            $metrics['terlambat'] = 0;
                            $metrics['pulang_awal'] = 0;
                        } elseif (! $isWorking) {
                            $metrics['terlambat'] = 0;
                            $metrics['pulang_awal'] = 0;
                        }
                    } elseif ($holiday) {
                        $metrics['hadir'] = 0;
                        $metrics['terlambat'] = 0;
                        $metrics['pulang_awal'] = 0;
                        $metrics['tidak_hadir'] = 0;
                        $metrics['izin_keluar'] = $leaveUnverifiable ? null : 0;
                        $metrics['dinas'] = $leaveUnverifiable ? null : 0;
                    }

                    $daily[] = [
                        'date' => $date,
                        'day_status' => $day['status'],
                        'source' => $day['source'],
                        'is_provisional' => $day['is_provisional'],
                        'is_ambiguous' => $ambiguous,
                        'history_limited' => $schedule['history_limited'] || $day['source'] === 'weekly_schedule',
                        'schedule_start' => $schedule['start'],
                        'schedule_end' => $schedule['end'],
                        'schedule_source' => $schedule['source'],
                        'raw_log_count' => $logs->count(),
                        'distinct_time_count' => $scanResult['distinct_count'],
                        'selected_in' => $scanResult['selected_in'],
                        'selected_out' => $scanResult['selected_out'],
                        'candidate_status' => $scanResult['candidate_status'],
                        'leave_types' => $leaveTypes->map(
                            fn (LeaveType $type): string => $type->value,
                        )->all(),
                        'leave_status' => $leaveUnverifiable ? 'unverifiable' : 'none',
                        'metrics' => $metrics,
                        'reason_or_warning' => $this->warning(
                            $statusUnknown,
                            $leaveUnverifiable,
                            $leaveConflict,
                            $scanResult,
                            $schedule,
                            $holiday,
                        ),
                    ];
                }

                $metricKeys = [
                    'hari_kerja', 'total_finger', 'hadir', 'terlambat',
                    'pulang_awal', 'tidak_hadir', 'izin_keluar', 'dinas',
                ];
                $totals = [];
                foreach ($metricKeys as $key) {
                    $values = array_column(array_column($daily, 'metrics'), $key);
                    $totals[$key] = in_array(null, $values, true) ? null : array_sum($values);
                }

                return [
                    'employee_id' => $employee->id,
                    'name' => $employee->displayName(),
                    'nip' => $employee->nip,
                    'category_id' => $employee->category_id,
                    'category_name' => $employee->category?->name,
                    ...$totals,
                    'is_provisional' => collect($daily)->contains('is_provisional', true),
                    'is_ambiguous' => collect($daily)->contains('is_ambiguous', true),
                    'history_limited' => collect($daily)->contains('history_limited', true),
                    'daily' => $daily,
                ];
            })
            ->values()
            ->all();
        $pageAmbiguousEmployeeDayCount = collect($rows)->sum(
            fn (array $row): int => collect($row['daily'])
                ->where('is_ambiguous', true)
                ->count(),
        );

        return [
            'filters' => $filters->filters(),
            'summary' => [
                'employee_count' => $employees->total(),
                'provisional_day_count' => count($provisionalDates),
                'unknown_date_count' => count($unknownDates),
                'page_ambiguous_employee_day_count' => $pageAmbiguousEmployeeDayCount,
            ],
            'data' => $rows,
            'pagination' => [
                'current_page' => $employees->currentPage(),
                'per_page' => $employees->perPage(),
                'total' => $employees->total(),
                'last_page' => $employees->lastPage(),
            ],
        ];
    }

    private function fingerprints(AttendanceFingerprintPeriodReportFilters $filters): Collection
    {
        return FingerprintLog::query()
            ->join('fingerprint_import_batches', 'fingerprint_import_batches.id', '=', 'fingerprint_logs.import_batch_id')
            ->join('employees as matched_employees', 'matched_employees.id', '=', 'fingerprint_logs.employee_id')
            ->where('fingerprint_logs.unit_id', $filters->unitId)
            ->where('fingerprint_import_batches.unit_id', $filters->unitId)
            ->where('matched_employees.unit_id', $filters->unitId)
            ->where('fingerprint_logs.matching_status', MatchingStatus::Matched->value)
            ->where('fingerprint_import_batches.status', ImportBatchStatus::Completed->value)
            ->whereNotNull('fingerprint_logs.employee_id')
            ->whereBetween('fingerprint_logs.attendance_date', [$filters->startDate, $filters->endDate])
            ->orderBy('fingerprint_logs.attendance_date')
            ->orderBy('fingerprint_logs.attendance_time')
            ->orderBy('fingerprint_logs.id')
            ->get([
                'fingerprint_logs.id',
                'fingerprint_logs.employee_id',
                'fingerprint_logs.attendance_date',
                'fingerprint_logs.attendance_time',
            ])
            ->groupBy(fn (FingerprintLog $log): string => $log->attendance_date->format('Y-m-d'));
    }

    private function leaveRequests(AttendanceFingerprintPeriodReportFilters $filters): Collection
    {
        return LeaveRequest::query()
            ->where('unit_id', $filters->unitId)
            ->whereBetween('request_date', [$filters->startDate, $filters->endDate])
            ->whereNotNull('employee_id')
            ->where('matching_status', MatchingStatus::Matched->value)
            ->with('importBatch')
            ->get()
            ->filter(function (LeaveRequest $request): bool {
                return $request->import_batch_id === null
                    || $request->importBatch?->status === ImportBatchStatus::Completed;
            })
            ->groupBy('employee_id')
            ->map(fn (Collection $requests) => $requests->groupBy(
                fn (LeaveRequest $request): string => $request->request_date->format('Y-m-d'),
            ));
    }

    private function scheduleForDate(
        Employee $employee,
        string $date,
        ?WorkCalendar $calendar,
        ?WorkSchedule $weekly,
    ): array {
        $start = null;
        $end = null;
        $source = null;
        $historyLimited = false;

        if ($calendar !== null && $calendar->is_working_day) {
            if ($this->validTime($calendar->start_time) && $this->validTime($calendar->end_time)) {
                $start = substr((string) $calendar->start_time, 0, 5);
                $end = substr((string) $calendar->end_time, 0, 5);
                $source = 'calendar';
            }
        }

        if (($start === null || $end === null) && $employee->category !== null) {
            $dayOfWeek = CarbonImmutable::parse($date)->dayOfWeek;
            $individualProfile = $employee->workScheduleProfile;
            $categoryProfile = $employee->category->workScheduleProfile;
            $profile = $individualProfile !== null
                && (int) $individualProfile->work_schedule_category_id === (int) $employee->category_id
                && $individualProfile->is_active
                    ? $individualProfile
                    : $categoryProfile;
            $profileRule = $profile?->rules->firstWhere('day_of_week', $dayOfWeek);

            if (
                $profileRule instanceof WorkScheduleProfileRule
                && $profileRule->is_working_day
                && $this->validTime($profileRule->start_time)
                && $this->validTime($profileRule->end_time)
            ) {
                $start = substr((string) $profileRule->start_time, 0, 5);
                $end = substr((string) $profileRule->end_time, 0, 5);
                $source = 'current_profile_rule';
                $historyLimited = true;
            } elseif (
                $profile !== null
                && $profile->is_active
                && $this->validTime($profile->start_time)
                && $this->validTime($profile->end_time)
            ) {
                $start = substr((string) $profile->start_time, 0, 5);
                $end = substr((string) $profile->end_time, 0, 5);
                $source = 'current_profile_fallback';
                $historyLimited = true;
            } elseif (
                $this->validTime($employee->category->start_time)
                && $this->validTime($employee->category->end_time)
            ) {
                $start = $this->validTime($employee->category->start_time)
                    ? substr((string) $employee->category->start_time, 0, 5)
                    : null;
                $end = $this->validTime($employee->category->end_time)
                    ? substr((string) $employee->category->end_time, 0, 5)
                    : null;
                $source = 'current_category_fallback';
                $historyLimited = true;
            }
        }

        if (($start === null || $end === null) && $weekly !== null) {
            $start = $this->validTime($weekly->start_time) ? substr((string) $weekly->start_time, 0, 5) : null;
            $end = $this->validTime($weekly->end_time) ? substr((string) $weekly->end_time, 0, 5) : null;
            $source = 'weekly_schedule';
        }

        $hasValidTimes = $this->validTime($start) && $this->validTime($end);
        $overnight = $hasValidTimes && $end <= $start;
        $valid = $hasValidTimes && ! $overnight;
        $historyLimited = $historyLimited
            || ($valid && $source !== 'calendar')
            || ($calendar === null && $weekly !== null);

        return [
            'start' => $valid ? $start : null,
            'end' => $valid ? $end : null,
            'source' => $source,
            'valid' => $valid,
            'overnight' => $overnight,
            'history_limited' => $historyLimited,
        ];
    }

    private function evaluateScans(Collection $logs, array $schedule): array
    {
        $groups = [];
        foreach ($logs as $log) {
            $time = substr((string) $log->attendance_time, 0, 5);
            $minutes = $this->minutes($time);
            $lastIndex = array_key_last($groups);
            if ($lastIndex === null || $minutes - $groups[$lastIndex]['minutes'] > 2) {
                $groups[] = ['time' => $time, 'minutes' => $minutes];
            }
        }

        $result = [
            'distinct_count' => count($groups),
            'selected_in' => null,
            'selected_out' => null,
            'candidate_status' => 'not_evaluated',
            'is_ambiguous' => false,
            'schedule_valid' => $schedule['valid'],
            'late' => null,
            'early' => null,
        ];

        if ($groups === []) {
            $result['candidate_status'] = 'no_fingerprint';

            return $result;
        }
        if ($schedule['overnight']) {
            $result['candidate_status'] = 'overnight_shift_undetermined';
            $result['is_ambiguous'] = true;
            $result['schedule_valid'] = false;

            return $result;
        }
        if (! $schedule['valid']) {
            $result['candidate_status'] = 'schedule_unavailable';
            $result['is_ambiguous'] = true;

            return $result;
        }

        $start = $this->minutes($schedule['start']);
        $end = $this->minutes($schedule['end']);
        if ($end <= $start) {
            $result['candidate_status'] = 'overnight_shift_undetermined';
            $result['is_ambiguous'] = true;
            $result['schedule_valid'] = false;

            return $result;
        }

        if (count($groups) === 1) {
            $scan = $groups[0];
            $distanceIn = abs($scan['minutes'] - $start);
            $distanceOut = abs($scan['minutes'] - $end);
            if ($distanceIn === $distanceOut) {
                $result['candidate_status'] = 'ambiguous_single_scan_role';
                $result['is_ambiguous'] = true;

                return $result;
            }
            if ($distanceIn < $distanceOut) {
                $result['selected_in'] = $scan['time'];
                $result['late'] = (int) ($scan['minutes'] > $start);
            } else {
                $result['selected_out'] = $scan['time'];
                $result['early'] = (int) ($scan['minutes'] < $end);
            }
            $result['candidate_status'] = 'single_scan_assigned';

            return $result;
        }

        $inCandidates = $this->rankCandidates($groups, $start);
        $selectedIn = $inCandidates[0];
        $outCandidates = $this->rankCandidates($groups, $end);
        $outCandidates = array_values(array_filter(
            $outCandidates,
            fn (int $candidate): bool => $candidate !== $selectedIn,
        ));

        $result['selected_in'] = $groups[$selectedIn]['time'];
        $result['late'] = (int) ($groups[$selectedIn]['minutes'] > $start);
        if ($outCandidates === []) {
            $result['candidate_status'] = 'ambiguous_missing_out_candidate';
            $result['is_ambiguous'] = true;

            return $result;
        }

        $result['selected_out'] = $groups[$outCandidates[0]]['time'];
        $result['early'] = (int) ($groups[$outCandidates[0]]['minutes'] < $end);
        $result['candidate_status'] = 'selected';

        return $result;
    }

    private function rankCandidates(array $groups, int $scheduled): array
    {
        $indices = array_keys($groups);
        usort($indices, function (int $left, int $right) use ($groups, $scheduled): int {
            $leftDistance = abs($groups[$left]['minutes'] - $scheduled);
            $rightDistance = abs($groups[$right]['minutes'] - $scheduled);
            if ($leftDistance !== $rightDistance) {
                return $leftDistance <=> $rightDistance;
            }
            $leftBefore = $groups[$left]['minutes'] <= $scheduled;
            $rightBefore = $groups[$right]['minutes'] <= $scheduled;
            if ($leftBefore !== $rightBefore) {
                return $leftBefore ? -1 : 1;
            }

            return $groups[$left]['minutes'] <=> $groups[$right]['minutes'];
        });

        return $indices;
    }

    private function warning(
        bool $statusUnknown,
        bool $leaveUnverifiable,
        bool $leaveConflict,
        array $scan,
        array $schedule,
        bool $holiday,
    ): ?string {
        return match (true) {
            $statusUnknown => 'Status hari belum dapat ditentukan.',
            $leaveConflict => 'Terdapat beberapa izin yang cocok; metrik terkait belum dapat ditentukan.',
            $leaveUnverifiable => 'Cakupan atau persetujuan izin tidak dapat diverifikasi dari data yang tersedia.',
            $holiday => 'Kalender resmi menetapkan hari nonkerja.',
            $scan['is_ambiguous'] => $scan['candidate_status'],
            ! $schedule['valid'] => 'Jam jadwal tidak tersedia atau tidak dapat dipakai.',
            default => null,
        };
    }

    private function dates(AttendanceFingerprintPeriodReportFilters $filters): array
    {
        $dates = [];
        for (
            $date = CarbonImmutable::parse($filters->startDate);
            $date <= CarbonImmutable::parse($filters->endDate);
            $date = $date->addDay()
        ) {
            $dates[] = $date->toDateString();
        }

        return $dates;
    }

    private function validTime(mixed $time): bool
    {
        return is_string($time)
            && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time) === 1;
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($hour * 60) + $minute;
    }
}
