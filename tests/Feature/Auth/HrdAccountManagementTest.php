<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\HrdAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HrdAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_hrd_account_is_created_with_a_hashed_password(): void
    {
        $this->artisan('hrd:setup')
            ->expectsQuestion('HRD username', 'hrd-admin')
            ->expectsQuestion('Password (minimum 12 characters)', 'Sufficiently-Long-Password-1')
            ->expectsQuestion('Confirm password', 'Sufficiently-Long-Password-1')
            ->expectsOutputToContain('Initial HRD account created and configured.')
            ->doesntExpectOutputToContain('Sufficiently-Long-Password-1')
            ->assertExitCode(0);

        $user = User::query()->sole();

        $this->assertSame('hrd-admin', $user->name);
        $this->assertTrue(Hash::check('Sufficiently-Long-Password-1', $user->password));
        $this->assertSame($user->id, app(HrdAccountService::class)->userId());
    }

    public function test_setup_selects_one_existing_user_without_deleting_other_users(): void
    {
        $hrd = User::factory()->create(['name' => 'chosen-hrd']);
        $otherUser = User::factory()->create(['name' => 'other-user']);

        $this->artisan('hrd:setup')
            ->expectsChoice('Select the existing user ID to use as the HRD account', (string) $hrd->id, [(string) $hrd->id, (string) $otherUser->id])
            ->expectsQuestion('Password (minimum 12 characters)', 'Selected-Hrd-Password-2')
            ->expectsQuestion('Confirm password', 'Selected-Hrd-Password-2')
            ->expectsOutputToContain('Existing user selected and configured as the HRD account.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('users', 2);
        $this->assertSame($hrd->id, app(HrdAccountService::class)->userId());
        $this->assertTrue(Hash::check('Selected-Hrd-Password-2', $hrd->refresh()->password));
    }

    public function test_setup_updates_only_the_configured_hrd_password(): void
    {
        $hrd = User::factory()->create();
        User::factory()->create();
        app(HrdAccountService::class)->setUser($hrd);

        $this->artisan('hrd:setup')
            ->expectsQuestion('Password (minimum 12 characters)', 'Changed-Secure-Password-2')
            ->expectsQuestion('Confirm password', 'Changed-Secure-Password-2')
            ->expectsOutputToContain('HRD password updated.')
            ->assertExitCode(0);

        $this->assertSame(2, User::query()->count());
        $this->assertTrue(Hash::check('Changed-Secure-Password-2', $hrd->refresh()->password));
    }
}
