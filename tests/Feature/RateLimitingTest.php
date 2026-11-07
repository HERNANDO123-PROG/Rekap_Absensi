<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    public function test_sensitive_routes_have_rate_limits(): void
    {
        $this->assertRouteHasMiddleware('employees.search', 'throttle:employee-search');
        $this->assertRouteHasMiddleware('rekap.period', 'throttle:report-search');
        $this->assertRouteHasMiddleware('rekap.annual', 'throttle:report-search');
        $this->assertRouteHasMiddleware('reports.period', 'throttle:report-search');
        $this->assertRouteHasMiddleware('reports.annual', 'throttle:report-search');
        $this->assertRouteHasMiddleware('fingerprint-imports.preview', 'throttle:imports');
        $this->assertRouteHasMiddleware('fingerprint-imports.reprocess', 'throttle:imports');
        $this->assertRouteHasMiddleware('leave-imports.preview', 'throttle:imports');
        $this->assertRouteHasMiddleware('reports.period.export', 'throttle:exports');
        $this->assertRouteHasMiddleware('reports.fingerprint-period.export', 'throttle:exports');
        $this->assertRouteHasMiddleware('reports.annual.export', 'throttle:exports');
        $this->assertRouteHasMiddleware('reports.employee.export', 'throttle:exports');
    }

    public function test_rate_limiters_are_registered_and_applied_to_livewire_requests(): void
    {
        foreach (['employee-search', 'report-search', 'imports', 'exports'] as $name) {
            $this->assertNotNull(RateLimiter::limiter($name));
        }

        $middleware = app(PersistentMiddleware::class)->getPersistentMiddleware();

        $this->assertContains(ThrottleRequests::class, $middleware);
    }

    public function test_named_limiter_rejects_requests_after_its_allowance(): void
    {
        $user = (new User)->forceFill(['id' => random_int(1, PHP_INT_MAX)]);
        $this->actingAs($user);

        Route::middleware('throttle:employee-search')->get(
            '/__rate-limit-test',
            fn () => response()->noContent(),
        );

        for ($attempt = 0; $attempt < 120; $attempt++) {
            $this->get('/__rate-limit-test')->assertNoContent();
        }

        $this->get('/__rate-limit-test')->assertStatus(429);
    }

    private function assertRouteHasMiddleware(string $routeName, string $middleware): void
    {
        $route = app('router')->getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "Route [{$routeName}] was not registered.");
        $this->assertContains($middleware, $route->gatherMiddleware());
    }
}
