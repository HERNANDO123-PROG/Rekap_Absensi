<?php

namespace App\Models;

use App\Enums\WorkCalendarType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkCalendar extends Model
{
    use HasFactory;

    protected $fillable = [
        'unit_id',
        'date',
        'type',
        'title',
        'description',
        'is_working_day',
        'start_time',
        'end_time',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'type' => WorkCalendarType::class,
            'is_working_day' => 'boolean',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
