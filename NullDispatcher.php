<?php

declare(strict_types=1);

namespace Voyager\Signals;

use Voyager\Contracts\Signals\SignalDispatcher as DispatcherContract;
use Voyager\NutsAndBolts\Concerns\ForwardsCalls;

class NullDispatcher implements DispatcherContract
{
    use ForwardsCalls;

    /**
     * The underlying event dispatcher instance.
     */
    protected DispatcherContract $dispatcher;

    /**
     * Create a new event dispatcher instance that does not fire.
     */
    public function __construct(DispatcherContract $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * The dispatcher this one wraps.
     */
    public function getDispatcher(): DispatcherContract
    {
        return $this->dispatcher;
    }

    /**
     * Don't fire an event.
     */
    public function dispatch(string|object $event, mixed $payload = [], bool $halt = false): mixed
    {
        return null;
    }

    /**
     * Don't register an event and payload to be fired later.
     */
    public function push(string $event, array $payload = []): void
    {
        //
    }

    /**
     * Don't dispatch an event.
     */
    public function until(string|object $event, mixed $payload = []): mixed
    {
        return null;
    }

    /**
     * Register an event listener with the dispatcher.
     */
    public function listen(callable|string|array $events, mixed $listener = null): void
    {
        $this->dispatcher->listen($events, $listener);
    }

    /**
     * Determine if a given event has listeners.
     */
    public function hasListeners(string $event_name): bool
    {
        return $this->dispatcher->hasListeners($event_name);
    }

    /**
     * Register an event subscriber with the dispatcher.
     */
    public function subscribe(object|string $subscriber): void
    {
        $this->dispatcher->subscribe($subscriber);
    }

    /**
     * Don't flush a set of pushed events.
     */
    public function flush(string $event): void
    {
        //
    }

    /**
     * Remove a set of listeners from the dispatcher.
     */
    public function forget(string $event): void
    {
        $this->dispatcher->forget($event);
    }

    /**
     * Forget every queued listener.
     */
    public function forgetPushed(): void
    {
        $this->dispatcher->forgetPushed();
    }

    /**
     * Dynamically pass method calls to the underlying dispatcher.
     *
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo($this->dispatcher, $method, $parameters);
    }
}
