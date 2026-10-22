<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fingerprint_logs', function (Blueprint $table) {
            $table->string('employee_department_raw')->nullable()->after('employee_name_raw');
            $table->foreignId('source_category_id')
                ->nullable()
                ->after('employee_department_raw')
                ->constrained('work_schedule_categories')
                ->nullOnDelete();
            $table->boolean('needs_verification')->nullable()->after('matching_status');
            $table->text('verification_note')->nullable()->after('needs_verification');
        });
    }

    public function down(): void
    {
        Schema::table('fingerprint_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_category_id');
            $table->dropColumn(['employee_department_raw', 'needs_verification', 'verification_note']);
        });
    }
};