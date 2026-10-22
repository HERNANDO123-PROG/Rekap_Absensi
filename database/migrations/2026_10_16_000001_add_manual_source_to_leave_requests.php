<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->change();
            $table->string('source', 16)->default('import')->after('import_batch_id')->index();
            $table->foreignId('created_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('leave_requests')->whereNull('import_batch_id')->exists()) {
            throw new RuntimeException(
                'Cannot roll back manual leave requests while rows without an import batch exist.',
            );
        }

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropIndex(['source']);
            $table->dropColumn('source');
            $table->foreignId('import_batch_id')->nullable(false)->change();
        });
    }
};
