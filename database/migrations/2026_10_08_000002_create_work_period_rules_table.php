<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_period_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_period_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->boolean('is_working_day')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestamps();

            $table->unique(['work_period_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_period_rules');
    }
};
