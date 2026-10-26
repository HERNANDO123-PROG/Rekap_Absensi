<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NationalCalendarEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'country_code',
        'date',
        'local_name',
        'name',
        'types',
        'verification_status',
        'verification_source_url',
        'verified_by',
        'verified_at',
        'source',
        'source_url',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'types' => 'array',
            'last_synced_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }
}
