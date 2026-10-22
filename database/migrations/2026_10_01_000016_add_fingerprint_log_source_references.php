<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fingerprint_logs', function (Blueprint $table) {
            $table->string('source_sheet')->nullable()->after('raw_value');
            $table->unsignedInteger('source_row')->nullable()->after('source_sheet');
            $table->string('source_cell', 32)->nullable()->after('source_row');
            $table->index(['source_sheet', 'source_row']);
        });
    }

    public function down(): void
    {
        Schema::table('fingerprint_logs', function (Blueprint $table) {
            $table->dropIndex(['source_sheet', 'source_row']);
            $table->dropColumn(['source_sheet', 'source_row', 'source_cell']);
        });
    }
};
