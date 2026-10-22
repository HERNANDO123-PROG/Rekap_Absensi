<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code'];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function workSchedules(): HasMany
    {
        return $this->hasMany(WorkSchedule::class);
    }

    public function workCalendars(): HasMany
    {
        return $this->hasMany(WorkCalendar::class);
    }

    public function fingerprintImportBatches(): HasMany
    {
        return $this->hasMany(FingerprintImportBatch::class);
    }

    public function fingerprintLogs(): HasMany
    {
        return $this->hasMany(FingerprintLog::class);
    }

    public function leaveImportBatches(): HasMany
    {
        return $this->hasMany(LeaveImportBatch::class);
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
