<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_route_and_endpoint_are_unavailable(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Public User',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }
}
