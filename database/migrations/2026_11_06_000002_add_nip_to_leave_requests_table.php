<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->string('nip', 64)->nullable()->after('employee_id');
            $table->index(['unit_id', 'nip']);
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropIndex(['unit_id', 'nip']);
            $table->dropColumn('nip');
        });
    }
};
