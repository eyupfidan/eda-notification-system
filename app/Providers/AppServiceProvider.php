<?php

namespace App\Providers;

use App\Providers\Notifications\NotificationProvider;
use App\Providers\Notifications\WebhookSiteProvider;
use App\Services\Delivery\CircuitBreaker;
use App\Services\Delivery\RetryPolicy;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationProvider::class, fn ($app) => new WebhookSiteProvider(
            $app->make(HttpFactory::class),
            config('notifications.provider.url'),
            (float) config('notifications.provider.timeout'),
        ));

        $this->app->bind(RetryPolicy::class, fn () => RetryPolicy::fromConfig());
        $this->app->bind(CircuitBreaker::class, fn () => CircuitBreaker::fromConfig());
    }

    public function boot(): void
    {
        //
    }
}
