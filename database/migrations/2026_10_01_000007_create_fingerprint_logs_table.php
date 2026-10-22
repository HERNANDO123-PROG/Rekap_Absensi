<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fingerprint_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('fingerprint_import_batches')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fingerprint_id', 64);
            $table->string('employee_name_raw')->nullable();
            $table->date('attendance_date');
            $table->time('attendance_time');
            $table->text('raw_value')->nullable();
            $table->string('matching_status', 24);
            $table->timestamps();

            $table->index('import_batch_id');
            $table->index('unit_id');
            $table->index('employee_id');
            $table->index('fingerprint_id');
            $table->index('attendance_date');
            $table->index('attendance_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fingerprint_logs');
    }
};
