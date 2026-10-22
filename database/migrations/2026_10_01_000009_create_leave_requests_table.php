<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('leave_import_batches')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('raw_name');
            $table->string('normalized_name', 191);
            $table->string('position')->nullable();
            $table->date('request_date');
            $table->time('request_time')->nullable();
            $table->string('leave_type', 32);
            $table->string('raw_leave_type')->nullable();
            $table->text('description')->nullable();
            $table->string('attachment')->nullable();
            $table->string('matching_status', 24);
            $table->string('absence_reason', 32)->nullable();
            $table->timestamps();

            $table->index('import_batch_id');
            $table->index('unit_id');
            $table->index('employee_id');
            $table->index('normalized_name');
            $table->index('request_date');
            $table->index('leave_type');
            $table->index('matching_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
