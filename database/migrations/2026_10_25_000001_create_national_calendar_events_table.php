<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('national_calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->string('country_code', 2);
            $table->date('date');
            $table->string('local_name');
            $table->string('name');
            $table->json('types')->nullable();
            $table->string('verification_status', 24)->default('pending');
            $table->string('verification_source_url', 500)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('source')->default('Nager.Date');
            $table->string('source_url', 500);
            $table->timestamp('last_synced_at')->useCurrent();
            $table->timestamps();

            $table->unique(
                ['country_code', 'date', 'name'],
                'national_calendar_events_country_date_name_unique',
            );
            $table->index(['date', 'verification_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('national_calendar_events');
    }
};
