<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->boolean('is_working_day')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestamps();

            $table->unique(['unit_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedules');
    }
};
