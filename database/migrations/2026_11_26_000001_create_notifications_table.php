<?php

use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->nullable();
            $table->string('idempotency_key', 255)->nullable()->unique();
            $table->string('correlation_id', 64)->nullable();

            $table->enum('channel', Channel::values());
            $table->string('recipient', 320);
            $table->string('subject', 255)->nullable();
            $table->text('content');
            $table->jsonb('metadata')->nullable();

            $table->enum('priority', Priority::values())->default(Priority::Normal->value);
            $table->enum('status', Status::values())->default(Status::Pending->value);

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider_message_id', 255)->nullable();
            $table->text('last_error')->nullable();

            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            // Listing / filtering: status + channel + date range
            $table->index(['status', 'channel', 'created_at']);
            $table->index('created_at');
            // Batch status lookups
            $table->index('batch_id');
            // Scheduler: due scheduled notifications
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
