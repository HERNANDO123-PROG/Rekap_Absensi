<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedule_categories', function (Blueprint $table): void {
            $table->time('start_time')->nullable()->change();
            $table->time('end_time')->nullable()->change();
        });
    }

    public function down(): void
    {
        $hasCategoriesWithoutHours = DB::table('work_schedule_categories')
            ->whereNull('start_time')
            ->orWhereNull('end_time')
            ->exists();

        if ($hasCategoriesWithoutHours) {
            throw new RuntimeException(
                'Cannot make work schedule category times required while categories have unset hours.'
            );
        }

        Schema::table('work_schedule_categories', function (Blueprint $table): void {
            $table->time('start_time')->nullable(false)->change();
            $table->time('end_time')->nullable(false)->change();
        });
    }
};
