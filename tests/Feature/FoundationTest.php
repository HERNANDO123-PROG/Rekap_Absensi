<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_from_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_dashboard_renders_livewire_component(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Fondasi absensi siap.');

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSee('Fondasi absensi siap.');
    }
}