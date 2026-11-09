<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialWorkSchedulePeriodCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'special_work_schedule_period_id',
        'work_schedule_category_id',
        'work_schedule_profile_id',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(SpecialWorkSchedulePeriod::class, 'special_work_schedule_period_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(WorkScheduleCategory::class, 'work_schedule_category_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(WorkScheduleProfile::class, 'work_schedule_profile_id');
    }
}
