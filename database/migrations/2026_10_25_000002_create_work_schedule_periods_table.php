<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedule_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('work_schedule_category_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('work_schedule_profile_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(
                ['unit_id', 'work_schedule_category_id', 'is_active', 'start_date', 'end_date'],
                'work_schedule_periods_scope_dates_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedule_periods');
    }
};
