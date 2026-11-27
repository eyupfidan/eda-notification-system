<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Kept apart from RefreshDatabase tests: these deliberately break the connections.
 */
class HealthDependencyFailureTest extends TestCase
{
    public function test_health_is_down_when_the_database_is_unreachable(): void
    {
        config(['database.connections.pgsql.port' => 1]);
        DB::purge('pgsql');

        $this->getJson('/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'down')
            ->assertJsonPath('checks.database.status', 'down')
            ->assertJsonPath('checks.redis.status', 'ok');
    }

    public function test_health_is_down_when_redis_is_unreachable(): void
    {
        // The Redis manager keeps its config once built: rebuild it with the broken port.
        config(['database.redis.default.port' => 1]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();

        $this->getJson('/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'down')
            ->assertJsonPath('checks.redis.status', 'down')
            ->assertJsonPath('checks.database.status', 'ok');
    }

    public function test_the_health_dashboard_keeps_the_503_status(): void
    {
        config(['database.connections.pgsql.port' => 1]);
        DB::purge('pgsql');

        $this->get('/health', ['Accept' => 'text/html'])
            ->assertStatus(503)
            ->assertViewIs('observability.health')
            ->assertViewHas('health', fn (array $h) => $h['status'] === 'down');
    }
}
