<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fingerprint_import_batches', function (Blueprint $table) {
            $table->char('completed_file_hash', 64)
                ->nullable()
                ->virtualAs("CASE WHEN status = 'completed' THEN file_hash ELSE NULL END");
            $table->unique(['unit_id', 'completed_file_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('fingerprint_import_batches', function (Blueprint $table) {
            $table->dropUnique(['unit_id', 'completed_file_hash']);
            $table->dropColumn('completed_file_hash');
        });
    }
};
