<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_import_batches', function (Blueprint $table): void {
            $table->json('processing_summary')->nullable()->after('error_details');
        });
    }

    public function down(): void
    {
        Schema::table('leave_import_batches', function (Blueprint $table): void {
            $table->dropColumn('processing_summary');
        });
    }
};
