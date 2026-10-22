<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkScheduleProfileRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'work_schedule_profile_id',
        'day_of_week',
        'is_working_day',
        'start_time',
        'end_time',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_working_day' => 'boolean',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(WorkScheduleProfile::class, 'work_schedule_profile_id');
    }
}
