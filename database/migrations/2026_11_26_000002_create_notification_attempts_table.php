<?php

use App\Enums\AttemptOutcome;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('notification_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->enum('outcome', AttemptOutcome::values());
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['notification_id', 'attempt']);
            // Metrics: success/failure rates and latency over a time window
            $table->index(['created_at', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_attempts');
    }
};
