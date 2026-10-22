<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedule_profile_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_schedule_profile_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->boolean('is_working_day')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestamps();

            $table->unique(
                ['work_schedule_profile_id', 'day_of_week'],
                'work_schedule_profile_rules_profile_day_unique',
            );
        });

        $now = now();
        $profiles = DB::table('work_schedule_profiles')->orderBy('id')->get([
            'id',
            'start_time',
            'end_time',
            'created_at',
            'updated_at',
        ]);

        foreach ($profiles as $profile) {
            $rules = [];

            for ($day = 0; $day <= 6; $day++) {
                $isWorkingDay = $day !== 0;
                $rules[] = [
                    'work_schedule_profile_id' => $profile->id,
                    'day_of_week' => $day,
                    'is_working_day' => $isWorkingDay,
                    'start_time' => $isWorkingDay ? $profile->start_time : null,
                    'end_time' => $isWorkingDay ? $profile->end_time : null,
                    'created_at' => $profile->created_at ?? $now,
                    'updated_at' => $profile->updated_at ?? $now,
                ];
            }

            DB::table('work_schedule_profile_rules')->insert($rules);
        }

        Schema::table('work_schedule_profiles', function (Blueprint $table): void {
            $table->time('start_time')->nullable()->change();
            $table->time('end_time')->nullable()->change();
        });

        Schema::table('work_periods', function (Blueprint $table): void {
            $table->foreignId('category_id')
                ->nullable()
                ->after('unit_id')
                ->constrained('work_schedule_categories')
                ->restrictOnDelete();
            $table->foreignId('work_schedule_profile_id')
                ->nullable()
                ->after('category_id')
                ->constrained('work_schedule_profiles')
                ->restrictOnDelete();
            $table->index(
                ['unit_id', 'category_id', 'is_active', 'start_date', 'end_date'],
                'work_periods_schedule_scope_dates_idx',
            );
        });
    }

    public function down(): void
    {
        if (DB::table('work_schedule_profiles')
            ->whereNull('start_time')
            ->orWhereNull('end_time')
            ->exists()) {
            throw new RuntimeException(
                'Cannot restore required single-range profile times while a daily-only schedule profile exists.',
            );
        }

        Schema::table('work_periods', function (Blueprint $table): void {
            $table->dropIndex('work_periods_schedule_scope_dates_idx');
            $table->dropConstrainedForeignId('work_schedule_profile_id');
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::table('work_schedule_profiles', function (Blueprint $table): void {
            $table->time('start_time')->nullable(false)->change();
            $table->time('end_time')->nullable(false)->change();
        });

        Schema::dropIfExists('work_schedule_profile_rules');
    }
};
