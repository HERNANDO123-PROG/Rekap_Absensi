<?php

namespace App\Models;

use App\Enums\AttendanceIntervalSource;
use App\Enums\AttendanceIntervalType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceInterval extends Model
{
    use HasFactory;

    protected $fillable = [
        'attendance_record_id',
        'type',
        'start_time',
        'end_time',
        'duration_minutes',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => AttendanceIntervalType::class,
            'duration_minutes' => 'integer',
            'source' => AttendanceIntervalSource::class,
        ];
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }
}
