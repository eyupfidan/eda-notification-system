<?php

namespace Tests\Unit;

use App\Enums\Status;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StatusTest extends TestCase
{
    /**
     * @return array<string, array{Status, Status}>
     */
    public static function allowedTransitions(): array
    {
        return [
            'scheduled -> pending' => [Status::Scheduled, Status::Pending],
            'scheduled -> cancelled' => [Status::Scheduled, Status::Cancelled],
            'pending -> queued' => [Status::Pending, Status::Queued],
            'pending -> cancelled' => [Status::Pending, Status::Cancelled],
            'queued -> processing' => [Status::Queued, Status::Processing],
            'queued -> cancelled' => [Status::Queued, Status::Cancelled],
            'processing -> sent' => [Status::Processing, Status::Sent],
            'processing -> queued (retry)' => [Status::Processing, Status::Queued],
            'processing -> failed' => [Status::Processing, Status::Failed],
            'failed -> queued (manual retry)' => [Status::Failed, Status::Queued],
        ];
    }

    /**
     * @return array<string, array{Status, Status}>
     */
    public static function forbiddenTransitions(): array
    {
        return [
            'processing -> cancelled' => [Status::Processing, Status::Cancelled],
            'sent -> queued' => [Status::Sent, Status::Queued],
            'sent -> failed' => [Status::Sent, Status::Failed],
            'cancelled -> queued' => [Status::Cancelled, Status::Queued],
            'pending -> sent (skips processing)' => [Status::Pending, Status::Sent],
            'failed -> cancelled' => [Status::Failed, Status::Cancelled],
        ];
    }

    #[DataProvider('allowedTransitions')]
    public function test_allowed_transitions(Status $from, Status $to): void
    {
        $this->assertTrue($from->canTransitionTo($to));
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_forbidden_transitions(Status $from, Status $to): void
    {
        $this->assertFalse($from->canTransitionTo($to));
    }

    public function test_only_states_before_delivery_are_cancellable(): void
    {
        $cancellable = array_filter(Status::cases(), fn (Status $s) => $s->isCancellable());

        $this->assertEqualsCanonicalizing(
            [Status::Scheduled, Status::Pending, Status::Queued],
            array_values($cancellable),
        );
    }

    public function test_final_states_have_no_outgoing_transitions(): void
    {
        foreach (Status::cases() as $status) {
            if ($status->isFinal()) {
                $this->assertSame([], $status->allowedTransitions(), "{$status->value} should be final");
            }
        }
    }
}
