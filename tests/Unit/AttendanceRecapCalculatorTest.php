<?php

namespace Tests\Unit;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Services\Reports\AttendanceRecapCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttendanceRecapCalculatorTest extends TestCase
{
    #[DataProvider('workdayScenarios')]
    public function test_workday_recap_metrics_use_stored_attendance_endpoints(
        ?string $actualIn,
        ?string $actualOut,
        int $expectedHadir,
        int $expectedLate,
        int $expectedEarlyLeave,
        int $expectedFinger,
        AttendanceStatus $status = AttendanceStatus::Present,
    ): void {
        $record = $this->record('2026-09-01', $actualIn, $actualOut, $status);

        $result = app(AttendanceRecapCalculator::class)->calculate($record);

        $this->assertTrue($result['is_workday']);
        $this->assertSame($expectedHadir, $result['hadir']);
        $this->assertSame($expectedLate, $result['terlambat']);
        $this->assertSame($expectedEarlyLeave, $result['pulang_awal']);
        $this->assertSame($expectedFinger, $result['total_finger']);
    }

    public static function workdayScenarios(): array
    {
        return [
            'early arrival and on-time departure' => ['06:48:00', '15:40:00', 1, 0, 0, 2],
            'late arrival and on-time departure' => ['07:15:00', '15:40:00', 1, 1, 0, 2, AttendanceStatus::Late],
            'early arrival and early departure' => ['06:50:00', '15:30:00', 1, 0, 1, 2],
            'late arrival and early departure coexist' => ['07:15:00', '15:30:00', 1, 1, 1, 2, AttendanceStatus::Late],
            'single morning fingerprint' => ['06:50:00', null, 1, 0, 1, 1],
            'single afternoon fingerprint is not inferred late' => ['15:40:00', null, 1, 0, 1, 1],
            'multiple scans use stored first and last endpoints' => ['06:48:00', '15:40:00', 1, 0, 0, 2],
            'no fingerprint endpoints' => [null, null, 0, 0, 0, 0, AttendanceStatus::Absent],
            'late status does not hide early departure' => ['07:15:00', '15:30:00', 1, 1, 1, 2, AttendanceStatus::Late],
        ];
    }

    public function test_sunday_attendance_is_excluded_from_recap_metrics_without_mutating_record(): void
    {
        $record = $this->record('2026-09-06', '07:15:00', '15:30:00', AttendanceStatus::Late);
        $original = $record->getAttributes();

        $result = app(AttendanceRecapCalculator::class)->calculate($record);

        $this->assertFalse($result['is_workday']);
        $this->assertSame(0, $result['hadir']);
        $this->assertSame(0, $result['terlambat']);
        $this->assertSame(0, $result['pulang_awal']);
        $this->assertSame(0, $result['total_finger']);
        $this->assertSame($original, $record->getAttributes());
    }

    public function test_lateness_uses_stored_actual_in_instead_of_the_model_accessor_value(): void
    {
        $record = $this->record('2026-09-01', '06:50:00', '15:40:00', AttendanceStatus::Late);

        $result = app(AttendanceRecapCalculator::class)->calculate($record);

        $this->assertSame('07:15', $record->actual_in);
        $this->assertSame('06:50', $result['actual_in']);
        $this->assertSame(0, $result['terlambat']);
    }

    private function record(
        string $date,
        ?string $actualIn,
        ?string $actualOut,
        AttendanceStatus $status,
    ): AttendanceRecord {
        $record = new AttendanceRecord;
        $record->setRawAttributes([
            'attendance_date' => $date,
            'status' => $status->value,
            'scheduled_in' => '07:00:00',
            'actual_in' => $actualIn,
            'scheduled_out' => '15:40:00',
            'actual_out' => $actualOut,
            'late_minutes' => $status === AttendanceStatus::Late ? 15 : 0,
            'early_leave_minutes' => 0,
        ]);
        $record->syncOriginal();

        return $record;
    }
}
