<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\HrdAccountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class SetupHrdUser extends Command
{
    protected $signature = 'hrd:setup';

    protected $description = 'Create the initial HRD account or securely update its password';

    public function handle(): int
    {
        $hrdAccount = app(HrdAccountService::class);
        $configuredUserId = $hrdAccount->userId();
        $isNewUser = false;
        $isNewConfiguration = $configuredUserId === null;

        if ($configuredUserId !== null) {
            $user = User::query()->find($configuredUserId);

            if ($user === null) {
                $this->error('The configured HRD account does not exist. Check the hrd_account_settings record.');

                return self::FAILURE;
            }
        } elseif (User::query()->exists()) {
            $users = User::query()->orderBy('id')->get(['id', 'name']);
            $this->table(['ID', 'Nama'], $users->map(fn (User $existingUser): array => [
                $existingUser->id,
                $existingUser->name,
            ]));

            $userId = (int) $this->choice(
                'Select the existing user ID to use as the HRD account',
                $users->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            );
            $user = $users->firstWhere('id', $userId);
        } else {
            $username = trim((string) $this->ask('HRD username'));

            if ($username === '' || mb_strlen($username) > 255) {
                $this->error('The HRD username must contain between 1 and 255 characters.');

                return self::FAILURE;
            }

            $user = new User(['name' => $username]);
            $isNewUser = true;
        }

        $password = $this->secret('Password (minimum 12 characters)');
        $passwordConfirmation = $this->secret('Confirm password');
        $validator = Validator::make(
            [
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
            ],
            [
                'password' => ['required', 'string', 'confirmed', Password::min(12)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $password, $hrdAccount): void {
            $user->password = $password;
            $user->save();
            $hrdAccount->setUser($user);
        });

        if ($isNewUser) {
            $this->info('Initial HRD account created and configured.');
        } elseif ($isNewConfiguration) {
            $this->info('Existing user selected and configured as the HRD account.');
        } else {
            $this->info('HRD password updated.');
        }

        return self::SUCCESS;
    }
}
