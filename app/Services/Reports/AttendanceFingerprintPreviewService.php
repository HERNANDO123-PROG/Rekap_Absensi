<?php

namespace App\Services\Reports;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveType;
use App\Enums\MatchingStatus;
use App\Models\Employee;
use App\Models\FingerprintLog;
use App\Models\LeaveRequest;
use App\Models\Unit;
use App\Models\WorkScheduleCategory;
use App\Services\Attendance\AttendanceDateResolver;
use App\Services\Attendance\AttendanceFingerprintAnalyzer;
use App\Services\Attendance\AttendanceIntervalBuilder;
use App\Services\Attendance\AttendanceLeaveResolver;
use App\Services\Attendance\AttendanceStatusResolver;
use App\Services\EmployeeEligibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AttendanceFingerprintPreviewService
{
    public function __construct(
        private readonly EmployeeEligibilityService $eligibility,
        private readonly AttendanceDateResolver $dateResolver,
        private readonly AttendanceFingerprintAnalyzer $fingerprintAnalyzer,
        private readonly AttendanceLeaveResolver $leaveResolver,
        private readonly AttendanceIntervalBuilder $intervalBuilder,
        private readonly AttendanceStatusResolver $statusResolver,
    ) {}

    public function generate(
        int $unitId,
        int $categoryId,
        string $startDate,
        string $endDate,
        array $employeeIds,
    ): array {
        if ($startDate !== '2026-09-01' || $endDate !== '2026-09-30') {
            throw ValidationException::withMessages([
                'period' => ['Pratinjau fingerprint hanya tersedia untuk 1–30 September 2026.'],
            ]);
        }

        $unit = Unit::query()->find($unitId);
        $category = WorkScheduleCategory::query()->find($categoryId);

        if (
            $unit?->code !== 'SD'
            || $category?->name !== 'BTQ/Madin'
            || ! $category->is_active
        ) {
            throw ValidationException::withMessages([
                'category' => ['Pratinjau membutuhkan kategori BTQ/Madin aktif.'],
            ]);
        }

        $employees = Employee::query()
            ->with(['category.workScheduleProfile.rules', 'workScheduleProfile.rules'])
            ->whereIn('id', $employeeIds)
            ->where('unit_id', $unitId)
            ->where('category_id', $categoryId)
            ->orderByDisplayName()
            ->get();

        if ($employees->count() !== count(array_unique($employeeIds))) {
            throw ValidationException::withMessages([
                'employee_id' => ['Daftar pegawai pratinjau harus hanya berisi pegawai BTQ/Madin unit SD yang sedang difilter.'],
            ]);
        }

        $logsByEmployeeAndDate = FingerprintLog::query()
            ->where('unit_id', $unitId)
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->where('matching_status', MatchingStatus::Matched->value)
            ->orderBy('attendance_date')
            ->orderBy('attendance_time')
            ->orderBy('id')
            ->get(['id', 'employee_id', 'attendance_date', 'attendance_time'])
            ->groupBy('employee_id')
            ->map(fn (Collection $logs) => $logs->groupBy(fn (FingerprintLog $log) => $log->attendance_date->format('Y-m-d')));
        $leaveRequestsByEmployeeAndDate = LeaveRequest::query()
            ->where('unit_id', $unitId)
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereBetween('request_date', [$startDate, $endDate])
            ->where('matching_status', MatchingStatus::Matched->value)
            ->orderBy('request_date')
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $requests) => $requests->groupBy(fn (LeaveRequest $request) => $request->request_date->format('Y-m-d')));

        $rows = [];

        foreach ($employees as $employee) {
            $employeeLogs = $logsByEmployeeAndDate->get($employee->id, collect());
            $days = [];
            $workingDaysWithLogs = 0;
            $conditionalLateDays = 0;
            $conditionalEarlyLeaveDays = 0;
            $ambiguousDays = 0;
            $singleTimeDays = 0;

            foreach ($employeeLogs as $date => $logs) {
                $eligibleForProcessing = $this->eligibility->isEligibleOnDate($employee, $date);
                $leaveRequests = $leaveRequestsByEmployeeAndDate
                    ->get($employee->id, collect())
                    ->get($date, collect());
                $schedule = $eligibleForProcessing
                    ? $this->dateResolver->resolve(
                        $unitId,
                        $date,
                        (int) $employee->category_id,
                        $employee,
                    )
                    : null;
                $fingerprints = $this->fingerprintAnalyzer->analyze($logs);
                $resolvedLeaves = $this->leaveResolver->resolve($leaveRequests);
                $leave = $resolvedLeaves['primary'];
                $exitTimeline = $leave?->leave_type->value === LeaveType::TemporaryExit->value
                    ? $this->intervalBuilder->build($fingerprints['times'], $date)
                    : null;
                $decision = $eligibleForProcessing
                    ? $this->statusResolver->resolve(
                        $schedule,
                        $fingerprints,
                        $resolvedLeaves,
                        $exitTimeline,
                    )
                    : null;
                $isWorkingDay = $schedule !== null
                    && $schedule['is_working_day'] === true
                    && ! $schedule['is_holiday'];
                $hasValidSchedule = $eligibleForProcessing
                    && $schedule !== null
                    && ! $schedule['missing_schedule']
                    && ! $schedule['invalid_schedule']
                    && ! ($schedule['missing_category'] ?? false);
                $canEstimate = $hasValidSchedule
                    && $isWorkingDay
                    && ! $decision['needs_verification']
                    && ! in_array($decision['status'], [AttendanceStatus::OfficialDuty, AttendanceStatus::Absent], true);
                $lateEstimate = ! $eligibleForProcessing || $schedule === null
                    ? null
                    : (! $hasValidSchedule
                        ? null
                        : (! $isWorkingDay
                            ? false
                            : ($canEstimate ? $decision['late_minutes'] > 0 : null)));
                $earlyLeaveEstimate = ! $eligibleForProcessing || $schedule === null
                    ? null
                    : (! $hasValidSchedule
                        ? null
                        : (! $isWorkingDay
                            ? false
                            : ($canEstimate ? $decision['early_leave_minutes'] > 0 : null)));

                if ($isWorkingDay) {
                    $workingDaysWithLogs++;
                }

                if ($fingerprints['possible_exit_reentry']) {
                    $ambiguousDays++;
                } elseif ($fingerprints['distinct_count'] === 1) {
                    $singleTimeDays++;
                }

                $conditionalLateDays += $lateEstimate === true ? 1 : 0;
                $conditionalEarlyLeaveDays += $earlyLeaveEstimate === true ? 1 : 0;

                $days[] = [
                    'date' => $date,
                    'raw_log_count' => $fingerprints['raw_count'],
                    'distinct_time_count' => $fingerprints['distinct_count'],
                    'first_time' => $fingerprints['actual_in'],
                    'last_time' => $fingerprints['actual_out'],
                    'schedule_start' => $schedule['start_time'] ?? null,
                    'schedule_end' => $schedule['end_time'] ?? null,
                    'schedule_source' => $schedule['source'] ?? null,
                    'late_estimate' => $lateEstimate,
                    'early_leave_estimate' => $earlyLeaveEstimate,
                    'status_estimate' => $decision['status']->value ?? null,
                    'needs_verification' => $decision['needs_verification'] ?? false,
                    'schedule_status' => $this->scheduleStatus($schedule, $eligibleForProcessing),
                    'time_status' => $fingerprints['possible_exit_reentry']
                        ? 'Lebih dari dua waktu unik; perlu pemeriksaan'
                        : ($fingerprints['distinct_count'] === 1
                            ? 'Satu waktu unik; pasangan waktu belum lengkap'
                            : 'Waktu tersedia'),
                    'eligible_for_official_processing' => $eligibleForProcessing,
                ];
            }

            $rows[] = [
                'employee_id' => $employee->id,
                'unit_id' => $employee->unit_id,
                'name' => $employee->displayName(),
                'nip' => $employee->nip,
                'unit' => $unit->code,
                'raw_log_count' => $employeeLogs->flatten(1)->count(),
                'logged_day_count' => $employeeLogs->count(),
                'working_days_with_logs' => $workingDaysWithLogs,
                'conditional_late_days' => $conditionalLateDays,
                'conditional_early_leave_days' => $conditionalEarlyLeaveDays,
                'ambiguous_day_count' => $ambiguousDays,
                'single_time_day_count' => $singleTimeDays,
                'all_logged_days_eligible' => $days !== []
                    && collect($days)->every(fn (array $day) => $day['eligible_for_official_processing']),
                'days' => $days,
            ];
        }

        return $rows;
    }

    private function scheduleStatus(?array $schedule, bool $eligible): string
    {
        if (! $eligible) {
            return 'Tidak memenuhi syarat pemrosesan';
        }

        if ($schedule['is_holiday']) {
            return 'Libur';
        }

        if ($schedule['missing_category'] ?? false) {
            return 'Kategori belum aktif atau tidak tersedia';
        }

        if ($schedule['missing_schedule']) {
            return 'Jadwal belum tersedia';
        }

        if ($schedule['invalid_schedule']) {
            return 'Jadwal tidak valid';
        }

        return $schedule['is_working_day'] ? 'Hari kerja' : 'Libur';
    }
}
