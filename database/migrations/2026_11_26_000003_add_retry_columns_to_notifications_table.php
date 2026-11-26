<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // When the next automatic retry is due (null when none is scheduled).
            $table->timestampTz('next_retry_at')->nullable()->after('last_error');
            // Overrides the configured max attempts; raised by a manual retry to grant a fresh budget.
            $table->unsignedSmallInteger('attempt_limit')->nullable()->after('attempts');

            // Recovery sweeps look for rows stuck in a non-final state.
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['status', 'updated_at']);
            $table->dropColumn(['next_retry_at', 'attempt_limit']);
        });
    }
};
