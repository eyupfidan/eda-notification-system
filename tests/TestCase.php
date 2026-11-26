<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** Redis database reserved for the test suite (see phpunit.xml). */
    private const TEST_REDIS_DB = 15;

    protected function setUp(): void
    {
        parent::setUp();

        // Safety net: never let RefreshDatabase wipe a non-test database.
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to run tests against database [{$database}]; expected a *_test database.");
        }

        // Rate-limit windows, circuit breakers and heartbeats live in Redis: start every
        // test from a clean slate so tests cannot influence each other.
        if ((int) config('database.redis.default.database') !== self::TEST_REDIS_DB) {
            throw new RuntimeException('Refusing to flush Redis: tests must use database '.self::TEST_REDIS_DB.'.');
        }

        Redis::flushdb();
    }
}
