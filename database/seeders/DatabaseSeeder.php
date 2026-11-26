<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo data for exploring the API locally: `php artisan db:seed`.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        Notification::factory()->count(10)->sms()->status(Status::Pending)->create();
        Notification::factory()->count(5)->email()->scheduled()->create();
        Notification::factory()->count(5)->push()->inBatch((string) Str::uuid7())->create();

        Notification::factory()->count(5)->sms()->status(Status::Sent)->create()
            ->each(fn (Notification $n) => NotificationAttempt::factory()->for($n)->create());

        Notification::factory()->count(3)->email()->status(Status::Failed)->create()
            ->each(fn (Notification $n) => NotificationAttempt::factory()->for($n)->permanentFailure()->create());
    }
}
