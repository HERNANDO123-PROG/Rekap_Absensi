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
            $table->string('namafinger')->nullable()->after('name');
            $table->string('nama_lengkap')->nullable()->after('namafinger');
        });

        DB::table('employees')
            ->whereNotNull('name')
            ->update([
                'namafinger' => DB::raw('name'),
                'nama_lengkap' => DB::raw('name'),
            ]);
    }

    public function down(): void
    {
        if (DB::table('employees')->whereRaw(
            "COALESCE(namafinger, '') != COALESCE(name, '') OR COALESCE(nama_lengkap, '') != COALESCE(name, '')",
        )->exists()) {
            throw new RuntimeException('Cannot remove employee name fields after they have diverged from the legacy name column.');
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['namafinger', 'nama_lengkap']);
        });
    }
};
