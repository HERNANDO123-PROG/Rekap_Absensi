<?php

namespace App\Models;

use App\Enums\AbsenceReason;
use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'unit_id',
        'attendance_date',
        'status',
        'scheduled_in',
        'actual_in',
        'scheduled_out',
        'actual_out',
        'late_minutes',
        'early_leave_minutes',
        'absence_reason',
        'leave_request_id',
        'is_holiday',
        'activity_on_holiday',
        'needs_verification',
        'verification_note',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date:Y-m-d',
            'status' => AttendanceStatus::class,
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'absence_reason' => AbsenceReason::class,
            'is_holiday' => 'boolean',
            'activity_on_holiday' => 'boolean',
            'needs_verification' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    public function getActualInAttribute($value): ?string
    {
        if ($this->late_minutes > 0 && ! empty($this->scheduled_in)) {
            return \Carbon\CarbonImmutable::parse($this->scheduled_in)
                ->addMinutes((int) $this->late_minutes)
                ->format('H:i');
        }

        return $value;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function intervals(): HasMany
    {
        return $this->hasMany(AttendanceInterval::class);
    }

    public function anomalies(): HasMany
    {
        return $this->hasMany(AttendanceAnomaly::class);
    }
}
