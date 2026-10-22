<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'unit_id',
        'category_id',
        'work_schedule_profile_id',
        'nip',
        'fingerprint_id',
        'namafinger',
        'nama_lengkap',
        'position',
        'status',
    ];

    protected $hidden = ['fingerprint_id', 'active_fingerprint_id'];

    protected function casts(): array
    {
        return ['status' => EmployeeStatus::class];
    }

    public function displayName(): string
    {
        return $this->nama_lengkap ?: ($this->namafinger ?: 'Pegawai #'.$this->id);
    }

    public function scopeOrderByDisplayName(Builder $query): Builder
    {
        return $query->orderByRaw("COALESCE(NULLIF(nama_lengkap, ''), NULLIF(namafinger, ''), '')");
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(WorkScheduleCategory::class, 'category_id');
    }

    public function workScheduleProfile(): BelongsTo
    {
        return $this->belongsTo(WorkScheduleProfile::class, 'work_schedule_profile_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(EmployeeAlias::class);
    }

    public function fingerprintLogs(): HasMany
    {
        return $this->hasMany(FingerprintLog::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
