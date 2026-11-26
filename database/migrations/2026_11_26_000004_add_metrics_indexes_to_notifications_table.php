<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Partial indexes for the metrics endpoint's time-window queries
     * (only rows that actually have the timestamp are indexed).
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX notifications_sent_at_index ON notifications (sent_at) WHERE sent_at IS NOT NULL');
        DB::statement('CREATE INDEX notifications_failed_at_index ON notifications (failed_at) WHERE failed_at IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS notifications_sent_at_index');
        DB::statement('DROP INDEX IF EXISTS notifications_failed_at_index');
    }
};
