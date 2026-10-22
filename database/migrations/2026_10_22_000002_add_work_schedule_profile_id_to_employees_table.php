<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('work_schedule_profile_id')
                ->nullable()
                ->after('category_id')
                ->constrained('work_schedule_profiles')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('employees')->whereNotNull('work_schedule_profile_id')->exists()) {
            throw new RuntimeException(
                'Cannot remove employee work schedule profile assignments. Clear or back up assigned values before rolling back.',
            );
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('work_schedule_profile_id');
        });
    }
};
