<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('work_period_rules');
        Schema::dropIfExists('work_periods');
        Schema::dropIfExists('employee_employment_periods');
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Retired period tables cannot be restored with their deleted data. Restore a verified database backup instead.',
        );
    }
};
