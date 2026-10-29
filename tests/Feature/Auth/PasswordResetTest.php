<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_password_reset_routes_are_unavailable_without_email_accounts(): void
    {
        $this->get('/forgot-password')->assertNotFound();
        $this->get('/reset-password/sample-token')->assertNotFound();
    }
}
