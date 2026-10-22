<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkScheduleCategory extends Model
{
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'work_schedule_profile_id',
        'start_time',
        'end_time',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'category_id');
    }

    public function workScheduleProfile(): BelongsTo
    {
        return $this->belongsTo(WorkScheduleProfile::class);
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(WorkScheduleProfile::class);
    }

    public function effectiveWorkTime(): array
    {
        $profile = $this->workScheduleProfile;

        if ($profile !== null) {
            if ((int) $profile->work_schedule_category_id !== (int) $this->getKey()) {
                throw new \LogicException('The selected work schedule profile belongs to a different category.');
            }

            return [
                'start_time' => $profile->start_time,
                'end_time' => $profile->end_time,
            ];
        }

        return [
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
        ];
    }
}