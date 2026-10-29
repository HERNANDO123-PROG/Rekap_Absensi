<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('password_reset_tokens');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn(['email', 'email_verified_at']);
        });

        $duplicateNames = DB::table('users')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('name');

        foreach ($duplicateNames as $name) {
            $users = DB::table('users')
                ->where('name', $name)
                ->orderBy('id')
                ->get(['id', 'name']);

            foreach ($users->skip(1) as $user) {
                $suffix = (string) $user->id;
                $baseName = mb_substr($user->name, 0, 255 - mb_strlen($suffix) - 1);
                $candidate = $baseName.'-'.$suffix;
                $counter = 1;

                while (DB::table('users')->where('name', $candidate)->exists()) {
                    $extra = '-'.$suffix.'-'.$counter++;
                    $candidate = mb_substr($baseName, 0, 255 - mb_strlen($extra)).$extra;
                }

                DB::table('users')->where('id', $user->id)->update(['name' => $candidate]);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
        });

        DB::table('users')->orderBy('id')->each(function (object $user): void {
            DB::table('users')
                ->where('id', $user->id)
                ->update(['email' => 'user-'.$user->id.'@absensi.local']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique('email');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }
};
