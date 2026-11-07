<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\HrdAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response
            ->assertOk()
            ->assertSeeVolt('pages.auth.login');
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();
        app(HrdAccountService::class)->setUser($user);
        $sessionId = session()->getId();

        $component = Volt::test('pages.auth.login')
            ->set('form.username', $user->name)
            ->set('form.password', 'password');

        $component->call('login');

        $component
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $this->assertNotSame($sessionId, session()->getId());
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();
        app(HrdAccountService::class)->setUser($user);

        $component = Volt::test('pages.auth.login')
            ->set('form.username', $user->name)
            ->set('form.password', 'wrong-password');

        $component->call('login');

        $component
            ->assertHasErrors()
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_non_hrd_users_cannot_authenticate(): void
    {
        $hrd = User::factory()->create();
        $otherUser = User::factory()->create();
        app(HrdAccountService::class)->setUser($hrd);

        $component = Volt::test('pages.auth.login')
            ->set('form.username', $otherUser->name)
            ->set('form.password', 'password')
            ->call('login');

        $component->assertHasErrors()->assertNoRedirect();
        $this->assertGuest();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        $user = User::factory()->create();
        app(HrdAccountService::class)->setUser($user);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            Volt::test('pages.auth.login')
                ->set('form.username', $user->name)
                ->set('form.password', 'wrong-password')
                ->call('login')
                ->assertHasErrors();
        }

        $component = Volt::test('pages.auth.login')
            ->set('form.username', $user->name)
            ->set('form.password', 'password')
            ->call('login');

        $component->assertHasErrors('form.username');
        $this->assertGuest();
    }

    public function test_navigation_menu_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->get('/dashboard');

        $response
            ->assertOk()
            ->assertSeeVolt('layout.navigation');
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('layout.navigation');

        $component->call('logout');

        $component
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
    }
}
