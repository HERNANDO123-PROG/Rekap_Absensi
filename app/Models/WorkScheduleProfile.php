<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkScheduleProfile extends Model
{
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'work_schedule_category_id',
        'name',
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

    public function workScheduleCategory(): BelongsTo
    {
        return $this->belongsTo(WorkScheduleCategory::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(WorkScheduleProfileRule::class, 'work_schedule_profile_id')
            ->orderBy('day_of_week');
    }
}
