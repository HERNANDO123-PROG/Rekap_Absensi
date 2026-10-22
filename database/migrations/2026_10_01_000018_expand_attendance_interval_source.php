<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_intervals', function (Blueprint $table) {
            $table->string('source', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_intervals', function (Blueprint $table) {
            $table->string('source', 24)->change();
        });
    }
};
