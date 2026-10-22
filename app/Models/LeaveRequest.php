<?php

namespace App\Models;

use App\Enums\AbsenceReason;
use App\Enums\LeaveType;
use App\Enums\MatchingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_batch_id',
        'source',
        'created_by',
        'unit_id',
        'employee_id',
        'raw_name',
        'normalized_name',
        'position',
        'request_date',
        'request_time',
        'leave_type',
        'raw_leave_type',
        'description',
        'attachment',
        'matching_status',
        'absence_reason',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date:Y-m-d',
            'leave_type' => LeaveType::class,
            'matching_status' => MatchingStatus::class,
            'absence_reason' => AbsenceReason::class,
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(LeaveImportBatch::class, 'import_batch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
