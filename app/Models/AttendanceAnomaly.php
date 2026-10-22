<?php

namespace App\Models;

use App\Enums\AnomalySeverity;
use App\Enums\AttendanceAnomalyType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceAnomaly extends Model
{
    use HasFactory;

    protected $fillable = [
        'attendance_record_id',
        'type',
        'message',
        'severity',
        'is_resolved',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => AttendanceAnomalyType::class,
            'severity' => AnomalySeverity::class,
            'is_resolved' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
