<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedule_categories', function (Blueprint $table): void {
            $table->foreignId('work_schedule_profile_id')
                ->nullable()
                ->after('name')
                ->constrained('work_schedule_profiles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_schedule_categories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('work_schedule_profile_id');
        });
    }
};
