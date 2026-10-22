<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->string('module', 100);
            $table->string('record_type')->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->text('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index(['record_type', 'record_id']);
            $table->index('module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
