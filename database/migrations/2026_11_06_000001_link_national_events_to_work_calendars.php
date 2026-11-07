<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_calendars', function (Blueprint $table): void {
            $table->foreignId('national_calendar_event_id')
                ->nullable()
                ->after('date')
                ->constrained('national_calendar_events')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_calendars', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('national_calendar_event_id');
        });
    }
};
