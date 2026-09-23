<?php

namespace Voyager\Signals;

use ReflectionException;
use Voyager\NutsAndBolts\ServiceProvider;
use Voyager\Contracts\Queue\Factory as QueueFactoryContract;

class SignalServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     * @throws ReflectionException
     */
    public function register(): void
    {
        $this->app->registerSingleton('signals', function ($app) {
            return new SignalDispatcher($app)
                ->setQueueResolver(function () {
                    return app(QueueFactoryContract::class);
                })->setTransactionManagerResolver(function () {
                    return app()->isBound('db.transactions')
                        ? app('db.transactions')
                        : null;
                });
        });
    }
}