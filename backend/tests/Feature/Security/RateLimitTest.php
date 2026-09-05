<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Rate limiting is only real if the limiter is attached to a route group.
 * Defining RateLimiter::for(...) without naming it in the middleware stack
 * silently does nothing, which is exactly the kind of gap that looks configured
 * and protects nothing - so it is asserted rather than assumed.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        RateLimiter::clear('api');
    }

    public function test_authenticated_endpoints_are_actually_throttled(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $limit = 120;   // matches the 'api' limiter
        $sawTooMany = false;

        for ($i = 0; $i < $limit + 15; $i++) {
            $response = $this->getJson('/api/v1/reviews/counts');
            if ($response->status() === 429) {
                $sawTooMany = true;
                break;
            }
        }

        $this->assertTrue($sawTooMany,
            'an authenticated endpoint served more than the configured limit without throttling');
    }

    public function test_throttled_response_uses_the_standard_error_envelope(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $status = 200;
        for ($i = 0; $i < 140 && $status !== 429; $i++) {
            $response = $this->getJson('/api/v1/reviews/counts');
            $status = $response->status();
        }

        $this->assertSame(429, $status);
        $response->assertJsonStructure(['data', 'error' => ['code', 'message']])
            ->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_login_is_throttled_far_more_tightly_than_general_traffic(): void
    {
        $blocked = false;
        for ($i = 0; $i < 12; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'nobody@example.test',
                'password' => 'wrong-password',
            ]);
            if ($response->status() === 429) {
                $blocked = true;
                break;
            }
        }

        $this->assertTrue($blocked,
            'repeated failed logins must be throttled to make credential stuffing impractical');
        $this->assertLessThan(12, $i, 'the login limiter should bite well before 12 attempts');
    }
}
