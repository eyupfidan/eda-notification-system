<?php

namespace App\Services\Observability;

/**
 * Renders the metrics snapshot in the Prometheus text exposition format,
 * so the same endpoint can be scraped by Prometheus / Grafana Agent.
 */
class PrometheusFormatter
{
    /** @var list<string> */
    private array $lines = [];

    /**
     * @param  array<string, mixed>  $m  output of MetricsCollector::collect()
     */
    public function format(array $m): string
    {
        $this->lines = [];
        $window = $m['window'];

        $this->metric('notifications_queue_depth', 'gauge', 'Jobs waiting in each priority queue.');
        foreach ($m['queues'] as $queue => $depth) {
            foreach (['ready', 'delayed', 'reserved'] as $state) {
                $this->sample('notifications_queue_depth', ['queue' => $queue, 'state' => $state], $depth[$state]);
            }
        }

        $this->metric('notifications_status', 'gauge', 'Notifications currently in each status.');
        foreach ($m['notifications_by_status'] as $status => $count) {
            $this->sample('notifications_status', ['status' => $status], $count);
        }

        $this->metric('notifications_delivered', 'gauge', 'Notifications that reached a final outcome in the window.');
        $this->metric('notifications_delivery_latency_ms', 'gauge', 'Accepted-to-sent latency in the window.');
        foreach ($m['delivery'] as $channel => $d) {
            if ($channel === 'all') {
                continue;
            }
            $this->sample('notifications_delivered', ['channel' => $channel, 'outcome' => 'sent', 'window' => $window], $d['sent']);
            $this->sample('notifications_delivered', ['channel' => $channel, 'outcome' => 'failed', 'window' => $window], $d['failed']);
            $this->latencySamples('notifications_delivery_latency_ms', $channel, $window, $d['latency_ms']);
        }

        $this->metric('notifications_provider_attempts', 'gauge', 'Provider calls in the window by outcome.');
        $this->metric('notifications_provider_latency_ms', 'gauge', 'Provider call latency in the window.');
        foreach ($m['provider_attempts'] as $channel => $a) {
            if ($channel === 'all') {
                continue;
            }
            foreach (['success', 'retryable_failure', 'permanent_failure'] as $outcome) {
                $this->sample('notifications_provider_attempts', ['channel' => $channel, 'outcome' => $outcome, 'window' => $window], $a[$outcome]);
            }
            $this->latencySamples('notifications_provider_latency_ms', $channel, $window, $a['latency_ms']);
        }

        $this->metric('notifications_circuit_open', 'gauge', '1 when the channel circuit breaker is open.');
        foreach ($m['circuit_breakers'] as $channel => $breaker) {
            $this->sample('notifications_circuit_open', ['channel' => $channel], $breaker['state'] === 'open' ? 1 : 0);
        }

        $this->metric('notifications_provider_failover_active', 'gauge', '1 while messages go to the fallback provider.');
        $this->sample('notifications_provider_failover_active', [], $m['provider']['active'] === 'fallback' ? 1 : 0);

        $this->metric('notifications_workers_active', 'gauge', 'Queue workers that sent a heartbeat recently.');
        $this->sample('notifications_workers_active', [], $m['workers']['active']);

        return implode("\n", $this->lines)."\n";
    }

    private function metric(string $name, string $type, string $help): void
    {
        $this->lines[] = "# HELP {$name} {$help}";
        $this->lines[] = "# TYPE {$name} {$type}";
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function sample(string $name, array $labels, int|float $value): void
    {
        $pairs = array_map(fn ($k, $v) => $k.'="'.addcslashes((string) $v, '"\\').'"', array_keys($labels), $labels);
        $this->lines[] = $name.($pairs ? '{'.implode(',', $pairs).'}' : '').' '.$value;
    }

    /**
     * @param  array<string, ?int>  $latency
     */
    private function latencySamples(string $name, string $channel, string $window, array $latency): void
    {
        foreach (['p50' => '0.5', 'p95' => '0.95', 'p99' => '0.99'] as $key => $quantile) {
            if ($latency[$key] !== null) {
                $this->sample($name, ['channel' => $channel, 'quantile' => $quantile, 'window' => $window], $latency[$key]);
            }
        }
    }
}
