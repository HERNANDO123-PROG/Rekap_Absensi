<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->date('attendance_date');
            $table->string('status', 32);
            $table->time('scheduled_in')->nullable();
            $table->time('actual_in')->nullable();
            $table->time('scheduled_out')->nullable();
            $table->time('actual_out')->nullable();
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->string('absence_reason', 32)->nullable();
            $table->foreignId('leave_request_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_holiday')->default(false);
            $table->boolean('activity_on_holiday')->default(false);
            $table->boolean('needs_verification')->default(false);
            $table->text('verification_note')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'attendance_date']);
            $table->index('employee_id');
            $table->index('unit_id');
            $table->index('attendance_date');
            $table->index(['unit_id', 'attendance_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
