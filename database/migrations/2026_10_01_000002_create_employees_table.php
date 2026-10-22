<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('employee_code')->nullable();
            $table->string('fingerprint_id', 64)->nullable();
            $table->string('name');
            $table->string('normalized_name', 191);
            $table->string('position')->nullable();
            $table->string('status', 24);
            $table->timestamps();

            $table->unique(['unit_id', 'fingerprint_id']);
            $table->index('unit_id');
            $table->index('fingerprint_id');
            $table->index('normalized_name');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
