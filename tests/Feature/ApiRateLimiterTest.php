<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiRateLimiterTest extends TestCase
{
    public function test_bearer_tokens_from_the_same_ip_have_separate_limits(): void
    {
        $headers = ['Authorization' => 'Bearer token-a'];
        $limit = config('myconfig.rate_limit.api_per_minute');

        for ($request = 0; $request < $limit; $request++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
                ->getJson('/api/health', $headers)
                ->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
            ->getJson('/api/health', $headers)
            ->assertTooManyRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
            ->getJson('/api/health', ['Authorization' => 'Bearer token-b'])
            ->assertOk();
    }

    public function test_anonymous_requests_from_the_same_ip_share_the_limit(): void
    {
        $limit = config('myconfig.rate_limit.api_per_minute');

        for ($request = 0; $request < $limit; $request++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
                ->getJson('/api/health')
                ->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->getJson('/api/health')
            ->assertTooManyRequests();
    }
}
