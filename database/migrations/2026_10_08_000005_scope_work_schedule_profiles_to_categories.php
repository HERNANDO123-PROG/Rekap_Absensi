<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedule_profiles', function (Blueprint $table): void {
            $table->foreignId('work_schedule_category_id')
                ->nullable()
                ->after('id')
                ->constrained('work_schedule_categories')
                ->restrictOnDelete();
        });

        Schema::table('work_schedule_profiles', function (Blueprint $table): void {
            $table->dropUnique('work_schedule_profiles_name_unique');
        });

        $sharedProfiles = DB::table('work_schedule_categories')
            ->select('work_schedule_profile_id')
            ->whereNotNull('work_schedule_profile_id')
            ->groupBy('work_schedule_profile_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('work_schedule_profile_id');

        foreach ($sharedProfiles as $profileId) {
            $categories = DB::table('work_schedule_categories')
                ->where('work_schedule_profile_id', $profileId)
                ->orderBy('id')
                ->get(['id']);
            $profile = DB::table('work_schedule_profiles')->where('id', $profileId)->first();

            if ($profile === null) {
                throw new RuntimeException("Work schedule profile {$profileId} was referenced but not found.");
            }

            foreach ($categories as $index => $category) {
                if ($index === 0) {
                    DB::table('work_schedule_profiles')
                        ->where('id', $profileId)
                        ->update(['work_schedule_category_id' => $category->id]);

                    continue;
                }

                $clonedProfileId = DB::table('work_schedule_profiles')->insertGetId([
                    'work_schedule_category_id' => $category->id,
                    'name' => $profile->name,
                    'start_time' => $profile->start_time,
                    'end_time' => $profile->end_time,
                    'is_active' => $profile->is_active,
                    'created_at' => $profile->created_at,
                    'updated_at' => $profile->updated_at,
                ]);

                DB::table('work_schedule_categories')
                    ->where('id', $category->id)
                    ->update(['work_schedule_profile_id' => $clonedProfileId]);
            }
        }

        $categoryAssignments = DB::table('work_schedule_categories')
            ->whereNotNull('work_schedule_profile_id')
            ->get(['id', 'work_schedule_profile_id']);

        foreach ($categoryAssignments as $assignment) {
            DB::table('work_schedule_profiles')
                ->where('id', $assignment->work_schedule_profile_id)
                ->update(['work_schedule_category_id' => $assignment->id]);
        }

        Schema::table('work_schedule_profiles', function (Blueprint $table): void {
            $table->unique(
                ['work_schedule_category_id', 'name'],
                'work_schedule_profiles_category_name_unique',
            );
        });
    }

    public function down(): void
    {
        $hasDuplicateNames = DB::table('work_schedule_profiles')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateNames) {
            throw new RuntimeException(
                'Cannot restore global profile-name uniqueness while different categories have profiles with the same name.'
            );
        }

        Schema::table('work_schedule_profiles', function (Blueprint $table): void {
            $table->dropUnique('work_schedule_profiles_category_name_unique');
            $table->dropConstrainedForeignId('work_schedule_category_id');
            $table->unique('name');
        });
    }
};
