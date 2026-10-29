<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_route_is_unavailable_without_email_accounts(): void
    {
        $this->get('/verify-email')->assertNotFound();
    }
}
