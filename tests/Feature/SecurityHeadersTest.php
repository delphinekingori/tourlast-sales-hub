<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_carry_the_basic_browser_protections(): void
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_https_requests_are_told_to_stay_on_https(): void
    {
        $this->get('https://sales.example.test/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_the_api_carries_the_protections_too(): void
    {
        $this->getJson('/api/v1/me')->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
