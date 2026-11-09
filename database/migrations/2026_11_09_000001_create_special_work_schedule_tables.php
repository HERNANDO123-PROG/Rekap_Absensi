<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('work_schedule_profiles', 'schedule_type')) {
            Schema::table('work_schedule_profiles', function (Blueprint $table): void {
                $table->string('schedule_type', 20)->default('normal')->after('work_schedule_category_id');
            });
        }

        Schema::create('special_work_schedule_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'start_date', 'end_date'], 'special_period_active_dates_idx');
        });

        Schema::create('special_work_schedule_period_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('special_work_schedule_period_id');
            $table->foreignId('work_schedule_category_id');
            $table->foreignId('work_schedule_profile_id');

            $table->foreign(
                'special_work_schedule_period_id',
                'special_period_category_period_fk',
            )->references('id')->on('special_work_schedule_periods')->cascadeOnDelete();
            $table->foreign(
                'work_schedule_category_id',
                'special_period_category_category_fk',
            )->references('id')->on('work_schedule_categories')->restrictOnDelete();
            $table->foreign(
                'work_schedule_profile_id',
                'special_period_category_profile_fk',
            )->references('id')->on('work_schedule_profiles')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['special_work_schedule_period_id', 'work_schedule_category_id'],
                'special_period_category_unique',
            );
            $table->unique('work_schedule_profile_id', 'special_period_profile_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_work_schedule_period_categories');
        Schema::dropIfExists('special_work_schedule_periods');

        Schema::table('work_schedule_profiles', function (Blueprint $table): void {
            $table->dropColumn('schedule_type');
        });
    }
};
