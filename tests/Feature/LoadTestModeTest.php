<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** The load-test switch (Product Details/LOAD_TEST.md) only lifts the login rate limit outside production. */
class LoadTestModeTest extends TestCase
{
    private function loginLimit(): int
    {
        $limit = RateLimiter::limiter('login')(Request::create('/login', 'POST'));

        return $limit->maxAttempts;
    }

    public function test_login_is_limited_to_ten_per_minute_by_default(): void
    {
        config(['inspection.loadtest' => false]);
        $this->assertSame(10, $this->loginLimit());
    }

    public function test_load_test_mode_lifts_the_login_limit_locally(): void
    {
        config(['inspection.loadtest' => true]);
        $this->assertGreaterThan(1000000, $this->loginLimit());
    }

    public function test_load_test_mode_is_ignored_in_production(): void
    {
        config(['inspection.loadtest' => true]);
        $this->app['env'] = 'production';
        $this->assertSame(10, $this->loginLimit());
    }
}
