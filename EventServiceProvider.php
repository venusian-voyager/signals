<?php

namespace Voyager\Events;

use Voyager\Contracts\Queue\Factory as QueueFactoryContract;
use Voyager\NutsAndBolts\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton('events', function ($app) {
            return (new Dispatcher($app))->setQueueResolver(function () {
                return app(QueueFactoryContract::class);
            })->setTransactionManagerResolver(function () {
                return app()->bound('db.transactions')
                    ? app('db.transactions')
                    : null;
            });
        });
    }
}
