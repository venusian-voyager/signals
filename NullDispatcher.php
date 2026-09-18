<?php

namespace Voyager\Events;

use Voyager\Contracts\Events\Dispatcher as DispatcherContract;
use Voyager\NutsAndBolts\Concerns\ForwardsCalls;

class NullDispatcher implements DispatcherContract
{
    use ForwardsCalls;

    /**
     * The underlying event dispatcher instance.
     *
     * @var \Voyager\Contracts\Events\Dispatcher
     */
    protected DispatcherContract $dispatcher;

    /**
     * Create a new event dispatcher instance that does not fire.
     *
     * @param  \Voyager\Contracts\Events\Dispatcher  $dispatcher
     */
    public function __construct(DispatcherContract $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Don't fire an event.
     *
     * @param  string|object  $event
     * @param  mixed  $payload
     * Typed `mixed` to stay interchangeable with \Voyager\Events\Dispatcher::dispatch(),
     * which returns the bare listener response when halting. Parameters stay untyped
     * because \Voyager\Contracts\Events\Dispatcher declares them without types.
     *
     * @param  bool  $halt
     * @return mixed
     */
    public function dispatch($event, $payload = [], $halt = false): mixed
    {
        return null;
    }

    /**
     * Don't register an event and payload to be fired later.
     *
     * @param  string  $event
     * @param  array  $payload
     * @return void
     */
    public function push($event, $payload = []): void
    {
        //
    }

    /**
     * Don't dispatch an event.
     *
     * @param  string|object  $event
     * @param  mixed  $payload
     * @return mixed
     */
    public function until($event, $payload = []): mixed
    {
        return null;
    }

    /**
     * Register an event listener with the dispatcher.
     *
     * @param  \Closure|string|array  $events
     * @param  \Closure|string|array|null  $listener
     * @return void
     */
    public function listen($events, $listener = null): void
    {
        $this->dispatcher->listen($events, $listener);
    }

    /**
     * Determine if a given event has listeners.
     *
     * @param  string  $eventName
     * @return bool
     */
    public function hasListeners($eventName): bool
    {
        return $this->dispatcher->hasListeners($eventName);
    }

    /**
     * Register an event subscriber with the dispatcher.
     *
     * @param  object|string  $subscriber
     * @return void
     */
    public function subscribe($subscriber): void
    {
        $this->dispatcher->subscribe($subscriber);
    }

    /**
     * Flush a set of pushed events.
     *
     * @param  string  $event
     * @return void
     */
    public function flush($event): void
    {
        $this->dispatcher->flush($event);
    }

    /**
     * Remove a set of listeners from the dispatcher.
     *
     * @param  string  $event
     * @return void
     */
    public function forget($event): void
    {
        $this->dispatcher->forget($event);
    }

    /**
     * Forget every queued listener.
     *
     * @return void
     */
    public function forgetPushed(): void
    {
        $this->dispatcher->forgetPushed();
    }

    /**
     * Dynamically pass method calls to the underlying dispatcher.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardDecoratedCallTo($this->dispatcher, $method, $parameters);
    }
}
