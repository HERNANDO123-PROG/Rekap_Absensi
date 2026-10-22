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
            $table->string('nip')->nullable()->after('employee_code');
        });
    }

    public function down(): void
    {
        if (DB::table('employees')->whereNotNull('nip')->exists()) {
            throw new RuntimeException('Cannot remove the NIP column while employee NIP values are present.');
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('nip');
        });
    }
};
