<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class HrdAccountService
{
    public function userId(): ?int
    {
        $userId = DB::table('hrd_account_settings')->where('id', 1)->value('user_id');

        $validatedUserId = filter_var($userId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $validatedUserId === false ? null : $validatedUserId;
    }

    public function setUser(User $user): void
    {
        $now = now();
        $createdAt = DB::table('hrd_account_settings')->where('id', 1)->value('created_at') ?? $now;

        DB::table('hrd_account_settings')->updateOrInsert(
            ['id' => 1],
            [
                'user_id' => $user->getKey(),
                'created_at' => $createdAt,
                'updated_at' => $now,
            ],
        );
    }
}
