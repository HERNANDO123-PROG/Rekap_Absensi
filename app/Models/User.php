<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function fingerprintImportBatches(): HasMany
    {
        return $this->hasMany(FingerprintImportBatch::class, 'imported_by');
    }

    public function leaveImportBatches(): HasMany
    {
        return $this->hasMany(LeaveImportBatch::class, 'imported_by');
    }

    public function createdLeaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'created_by');
    }

    public function resolvedAttendanceAnomalies(): HasMany
    {
        return $this->hasMany(AttendanceAnomaly::class, 'resolved_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
