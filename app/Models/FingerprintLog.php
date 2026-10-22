<?php

namespace App\Models;

use App\Enums\MatchingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FingerprintLog extends Model
{
    use HasFactory;

    public const EMPLOYMENT_PERIOD_NOTE_MARKER = '[periode-kerja]';

    protected $fillable = [
        'import_batch_id',
        'unit_id',
        'employee_id',
        'fingerprint_id',
        'employee_name_raw',
        'employee_department_raw',
        'source_category_id',
        'attendance_date',
        'attendance_time',
        'raw_value',
        'source_sheet',
        'source_row',
        'source_cell',
        'matching_status',
        'needs_verification',
        'verification_note',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date:Y-m-d',
            'source_row' => 'integer',
            'matching_status' => MatchingStatus::class,
            'needs_verification' => 'boolean',
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(FingerprintImportBatch::class, 'import_batch_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function sourceCategory(): BelongsTo
    {
        return $this->belongsTo(WorkScheduleCategory::class, 'source_category_id');
    }
}
