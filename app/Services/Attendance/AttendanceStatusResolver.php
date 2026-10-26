<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveType;

class AttendanceStatusResolver
{
    public function resolve(array $schedule, array $fingerprints, array $leaveResolution, ?array $exitTimeline = null): array
    {
        $requests = $leaveResolution['requests'];
        $leaveRequest = $leaveResolution['primary'];
        $fingerprintCount = $fingerprints['distinct_count'];
        $actualIn = $exitTimeline !== null && array_key_exists('actual_in', $exitTimeline)
            ? $exitTimeline['actual_in']
            : $fingerprints['actual_in'];
        $actualOut = $exitTimeline !== null && array_key_exists('actual_out', $exitTimeline)
            ? $exitTimeline['actual_out']
            : $fingerprints['actual_out'];
        $lateMinutes = $this->lateMinutes($schedule['start_time'], $actualIn);
        $earlyLeaveMinutes = $this->earlyLeaveMinutes(
            $schedule['start_time'],
            $schedule['end_time'],
            $actualIn,
            $actualOut,
        );
        $needsVerification = false;
        $verificationNote = null;
        $absenceReason = null;
        $status = AttendanceStatus::Present;

        if ($schedule['is_holiday']) {
            $status = AttendanceStatus::Holiday;
        } elseif ($schedule['schedule_conflict'] ?? false) {
            $needsVerification = true;
            $verificationNote = 'Terdapat konflik periode jadwal aktif. Selesaikan konflik sebelum menghitung absensi.';
        } elseif ($leaveResolution['has_conflict']) {
            $needsVerification = true;
            $verificationNote = 'Terdapat lebih dari satu pengajuan izin yang cocok pada tanggal ini.';
        } elseif ($leaveRequest !== null) {
            $leaveType = $leaveRequest->leave_type->value;

            if ($leaveType === LeaveType::OfficialDuty->value) {
                $status = AttendanceStatus::OfficialDuty;
            } elseif ($leaveType === LeaveType::Absent->value) {
                $absenceReason = $leaveRequest->absence_reason?->value;
                $status = AttendanceStatus::Absent;
            } elseif ($schedule['missing_schedule'] || $schedule['invalid_schedule'] || ($schedule['missing_category'] ?? false)) {
                $needsVerification = true;
                $verificationNote = match (true) {
                    $schedule['missing_schedule'] => 'Kalender kerja untuk unit dan tanggal ini belum dikonfigurasi.',
                    $schedule['missing_category'] ?? false => 'Karyawan belum memiliki kategori jam kerja aktif.',
                    default => 'Jam masuk atau keluar pada kategori jam kerja belum valid.',
                };
            } elseif (in_array($leaveType, [LeaveType::Late->value, LeaveType::EarlyDeparture->value], true)) {
                if ($fingerprintCount === 0) {
                    $needsVerification = true;
                    $verificationNote = 'Pengajuan memerlukan fingerprint untuk memverifikasi waktu aktual.';
                } elseif ($fingerprints['possible_exit_reentry']) {
                    $needsVerification = true;
                    $verificationNote = 'Fingerprint menunjukkan kemungkinan keluar dan kembali tanpa pengajuan Keluar.';
                } else {
                    $status = $this->regularStatus($lateMinutes, $earlyLeaveMinutes);
                }
            } elseif ($leaveType === LeaveType::TemporaryExit->value) {
                if ($fingerprintCount === 0) {
                    $needsVerification = true;
                    $verificationNote = 'Pengajuan Keluar tidak memiliki fingerprint untuk membuktikan waktu keluar dan kembali.';
                } elseif (($exitTimeline['odd_count'] ?? true) || ($exitTimeline['intervals'] ?? []) === []) {
                    $needsVerification = true;
                    $verificationNote = 'Jumlah fingerprint untuk pengajuan Keluar tidak membentuk pasangan lengkap.';
                } else {
                    $status = $lateMinutes > 0
                        ? AttendanceStatus::Late
                        : ($earlyLeaveMinutes > 0 ? AttendanceStatus::EarlyDeparture : AttendanceStatus::TemporaryExit);
                }
            }
        } elseif ($schedule['missing_schedule'] || $schedule['invalid_schedule'] || ($schedule['missing_category'] ?? false)) {
            $needsVerification = true;
            $verificationNote = match (true) {
                $schedule['missing_schedule'] => 'Kalender kerja untuk unit dan tanggal ini belum dikonfigurasi.',
                $schedule['missing_category'] ?? false => 'Karyawan belum memiliki kategori jam kerja aktif.',
                default => 'Jam masuk atau keluar pada kategori jam kerja belum valid.',
            };
        } elseif ($fingerprintCount === 0) {
            $status = AttendanceStatus::Absent;
        } elseif ($fingerprints['possible_exit_reentry']) {
            $needsVerification = true;
            $verificationNote = 'Fingerprint menunjukkan kemungkinan keluar dan kembali tanpa pengajuan Keluar.';
        } else {
            $status = $this->regularStatus($lateMinutes, $earlyLeaveMinutes);
        }

        if ($needsVerification) {
            $status = AttendanceStatus::NeedsVerification;
        }

        return [
            'status' => $status,
            'scheduled_in' => $schedule['start_time'],
            'scheduled_out' => $schedule['end_time'],
            'actual_in' => $actualIn,
            'actual_out' => $actualOut,
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyLeaveMinutes,
            'absence_reason' => $absenceReason,
            'leave_request_id' => $leaveRequest?->id,
            'is_holiday' => $schedule['is_holiday'],
            'activity_on_holiday' => $schedule['is_holiday'] && $fingerprintCount > 0,
            'needs_verification' => $needsVerification,
            'verification_note' => $verificationNote,
        ];
    }

    private function regularStatus(int $lateMinutes, int $earlyLeaveMinutes): AttendanceStatus
    {
        if ($lateMinutes > 0) {
            return AttendanceStatus::Late;
        }

        if ($earlyLeaveMinutes > 0) {
            return AttendanceStatus::EarlyDeparture;
        }

        return AttendanceStatus::Present;
    }

    private function lateMinutes(?string $scheduledIn, ?string $actualIn): int
    {
        if ($scheduledIn === null || $actualIn === null) {
            return 0;
        }

        return max(0, $this->minutesOfDay($actualIn) - $this->minutesOfDay($scheduledIn));
    }

    private function earlyLeaveMinutes(
        ?string $scheduledIn,
        ?string $scheduledOut,
        ?string $actualIn,
        ?string $actualOut,
    ): int {
        if ($scheduledIn === null || $scheduledOut === null || $actualOut === null) {
            return 0;
        }

        $scheduledStart = $this->minutesOfDay($scheduledIn);
        $scheduledEnd = $this->minutesOfDay($scheduledOut);
        $actualEnd = $this->minutesOfDay($actualOut);

        if ($scheduledEnd <= $scheduledStart) {
            $scheduledEnd += 1440;
        }

        if ($actualIn !== null && $actualEnd < $this->minutesOfDay($actualIn)) {
            $actualEnd += 1440;
        }

        return max(0, $scheduledEnd - $actualEnd);
    }

    private function minutesOfDay(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($hour * 60) + $minute;
    }
}
