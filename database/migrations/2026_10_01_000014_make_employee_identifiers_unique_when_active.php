<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['unit_id', 'fingerprint_id']);
            $table->string('active_fingerprint_id', 64)
                ->nullable()
                ->virtualAs("CASE WHEN status = 'active' THEN fingerprint_id ELSE NULL END");
            $table->string('active_employee_code')
                ->nullable()
                ->virtualAs("CASE WHEN status = 'active' THEN employee_code ELSE NULL END");
            $table->unique(['unit_id', 'active_fingerprint_id']);
            $table->unique(['unit_id', 'active_employee_code']);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['unit_id', 'active_fingerprint_id']);
            $table->dropUnique(['unit_id', 'active_employee_code']);
            $table->dropColumn(['active_fingerprint_id', 'active_employee_code']);
            $table->unique(['unit_id', 'fingerprint_id']);
        });
    }
};
