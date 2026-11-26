<?php

namespace App\Services\Delivery;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Strict sliding-window rate limiter shared by all workers.
 *
 * Every granted request is stored in a Redis sorted set scored by its time (ms). A
 * request is allowed only if fewer than `limit` requests happened in the last
 * `window` ms, so the limit holds for *any* window: unlike a fixed window, two
 * adjacent windows can never add up to more than the limit within one second.
 *
 * The check-and-add runs as one Lua script (atomic in Redis) and uses Redis' own
 * clock, so workers on different hosts cannot disagree about time.
 */
class SlidingWindowRateLimiter
{
    private const SCRIPT = <<<'LUA'
        local key = KEYS[1]
        local window = tonumber(ARGV[1])
        local limit = tonumber(ARGV[2])
        local member = ARGV[3]

        local time = redis.call('TIME')
        local now = tonumber(time[1]) * 1000 + math.floor(tonumber(time[2]) / 1000)

        redis.call('ZREMRANGEBYSCORE', key, '-inf', now - window)

        if redis.call('ZCARD', key) < limit then
            redis.call('ZADD', key, now, member)
            redis.call('PEXPIRE', key, window)
            return 0
        end

        local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
        return math.max(1, tonumber(oldest[2]) + window - now)
        LUA;

    /**
     * @return int 0 when the request is allowed, otherwise milliseconds until a slot frees up
     */
    public function attempt(string $key, int $limit, int $windowMs = 1000): int
    {
        return (int) Redis::eval(self::SCRIPT, 1, $key, $windowMs, $limit, (string) Str::uuid7());
    }
}
