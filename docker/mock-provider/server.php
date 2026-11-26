<?php

/**
 * Local stand-in for the external notification provider (same contract as the
 * webhook.site setup), for load and failure testing without request quotas.
 *
 *   POST /notify  {"to": "...", "channel": "sms|email|push", "content": "..."}
 *     → 202 {"messageId": "<uuid>", "status": "accepted", "timestamp": "<ISO8601>"}
 *   GET  /stats   counters since start (or last reset), incl. peak requests per second
 *   POST /reset   reset counters
 *
 * Behaviour is tuned with environment variables (probabilities between 0 and 1):
 *   MOCK_FAILURE_RATE     → 503 Service Unavailable
 *   MOCK_RATE_LIMIT_RATE  → 429 Too Many Requests (Retry-After: MOCK_RETRY_AFTER, default 2)
 *   MOCK_TIMEOUT_RATE     → sleeps MOCK_TIMEOUT_SECONDS (default 10) before answering
 *   MOCK_LATENCY_MS       → normal response latency, "min-max" (default "20-80")
 */
const STATS_FILE = '/tmp/mock-provider-stats.json';

function env_float(string $key, float $default = 0.0): float
{
    $value = getenv($key);

    return $value === false || $value === '' ? $default : (float) $value;
}

function chance(float $probability): bool
{
    return $probability > 0 && mt_rand() / mt_getrandmax() < $probability;
}

function respond(int $status, array $body = [], array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');

    foreach ($headers as $name => $value) {
        header("{$name}: {$value}");
    }

    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Shared counters across PHP built-in server workers, guarded by flock.
 */
function stats(?callable $mutate = null): array
{
    $handle = fopen(STATS_FILE, 'c+');
    flock($handle, LOCK_EX);

    $data = json_decode(stream_get_contents($handle) ?: '', true) ?: [
        'total' => 0, 'by_status' => [], 'by_channel' => [], 'per_second' => [], 'started_at' => gmdate('c'),
    ];

    if ($mutate !== null) {
        $data = $mutate($data);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($data));
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    return $data;
}

function record(int $status, ?string $channel): void
{
    stats(function (array $data) use ($status, $channel) {
        $second = (string) time();
        $data['total']++;
        $data['by_status'][$status] = ($data['by_status'][$status] ?? 0) + 1;

        if ($channel !== null) {
            $data['by_channel'][$channel] = ($data['by_channel'][$channel] ?? 0) + 1;
        }

        $data['per_second'][$second] = ($data['per_second'][$second] ?? 0) + 1;
        // Keep only the last 5 minutes of per-second buckets.
        $data['per_second'] = array_slice($data['per_second'], -300, null, true);

        return $data;
    });
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($method === 'GET' && $path === '/stats') {
    $data = stats();
    $data['peak_requests_per_second'] = $data['per_second'] === [] ? 0 : max($data['per_second']);
    unset($data['per_second']);
    respond(200, $data);
}

if ($method === 'POST' && $path === '/reset') {
    @unlink(STATS_FILE);
    respond(200, ['reset' => true]);
}

if ($method === 'GET' && $path === '/health') {
    respond(200, ['status' => 'ok']);
}

if ($method !== 'POST' || $path !== '/notify') {
    respond(404, ['error' => 'not found']);
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
$channel = is_array($payload) && is_string($payload['channel'] ?? null) ? $payload['channel'] : null;

if (! is_array($payload) || ! isset($payload['to'], $payload['channel'], $payload['content'])) {
    record(400, $channel);
    respond(400, ['error' => 'to, channel and content are required']);
}

if (chance(env_float('MOCK_TIMEOUT_RATE'))) {
    sleep((int) env_float('MOCK_TIMEOUT_SECONDS', 10));
}

if (chance(env_float('MOCK_RATE_LIMIT_RATE'))) {
    record(429, $channel);
    respond(429, ['error' => 'rate limited'], ['Retry-After' => (string) (int) env_float('MOCK_RETRY_AFTER', 2)]);
}

if (chance(env_float('MOCK_FAILURE_RATE'))) {
    record(503, $channel);
    respond(503, ['error' => 'temporarily unavailable']);
}

[$min, $max] = array_map('intval', explode('-', getenv('MOCK_LATENCY_MS') ?: '20-80') + [1 => 80]);
usleep(mt_rand($min, max($min, $max)) * 1000);

record(202, $channel);

respond(202, [
    'messageId' => sprintf('%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
        mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFF),
        mt_rand(0x8000, 0xBFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF)),
    'status' => 'accepted',
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
]);
