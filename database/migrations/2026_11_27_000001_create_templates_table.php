<?php

use App\Enums\Channel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->enum('channel', Channel::values());
            $table->string('subject', 255)->nullable();
            $table->text('content');
            $table->timestampsTz();
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignUuid('template_id')->nullable()->after('batch_id')->constrained()->nullOnDelete();
            $table->jsonb('template_variables')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_id');
            $table->dropColumn('template_variables');
        });

        Schema::dropIfExists('templates');
    }
};
