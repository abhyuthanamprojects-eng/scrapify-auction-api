<?php

namespace Tests\Feature;

use Tests\TestCase;

class UnauthenticatedApiResponseTest extends TestCase
{
    public function test_guest_without_accept_header_gets_401_json_not_a_login_redirect(): void
    {
        // No Accept: application/json header — previously this tried to
        // redirect to a non-existent "login" route and returned a 500.
        $response = $this->get('/api/v1/auth/me');

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_guest_with_json_accept_header_gets_401_json(): void
    {
        $this->getJson('/api/v1/admin/auth/me')
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }
}
