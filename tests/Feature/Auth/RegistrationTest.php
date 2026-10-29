<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response
            ->assertOk()
            ->assertSeeVolt('pages.auth.register');
    }

    public function test_new_users_can_register(): void
    {
        $component = Volt::test('pages.auth.register')
            ->set('name', 'Test User')
            ->set('password', 'password')
            ->set('password_confirmation', 'password');

        $component->call('register');

        $component->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['name' => 'Test User']);
        $this->assertTrue(Hash::check('password', User::query()->where('name', 'Test User')->firstOrFail()->password));
        $this->assertFalse(Schema::hasColumn('users', 'email'));
        $this->assertFalse(Schema::hasTable('password_reset_tokens'));

        Auth::logout();

        $login = Volt::test('pages.auth.login')
            ->set('form.username', 'Test User')
            ->set('form.password', 'password');

        $login->call('login');
        $login->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
    }

    public function test_duplicate_username_is_rejected(): void
    {
        User::factory()->create(['name' => 'Test User']);

        $component = Volt::test('pages.auth.register')
            ->set('name', 'Test User')
            ->set('password', 'password')
            ->set('password_confirmation', 'password');

        $component->call('register');

        $component->assertHasErrors(['name']);
    }

    public function test_password_confirmation_must_match(): void
    {
        $component = Volt::test('pages.auth.register')
            ->set('name', 'Another User')
            ->set('password', 'password')
            ->set('password_confirmation', 'different-password');

        $component->call('register');

        $component->assertHasErrors(['password']);
    }
}
