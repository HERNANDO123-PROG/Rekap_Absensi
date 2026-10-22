<?php

namespace App\Models;

use App\Enums\ImportBatchStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveImportBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'unit_id',
        'filename',
        'file_hash',
        'imported_by',
        'imported_at',
        'period_start',
        'period_end',
        'status',
        'total_rows',
        'success_rows',
        'error_rows',
        'error_details',
    ];

    protected $hidden = ['completed_file_hash'];

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'status' => ImportBatchStatus::class,
            'total_rows' => 'integer',
            'success_rows' => 'integer',
            'error_rows' => 'integer',
            'error_details' => 'array',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'import_batch_id');
    }
}
