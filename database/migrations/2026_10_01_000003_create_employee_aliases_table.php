<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('alias_name');
            $table->string('normalized_alias', 191);
            $table->timestamps();

            $table->index('employee_id');
            $table->index('normalized_alias');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_aliases');
    }
};
