<?php

namespace Voyager\Signals;

use Closure;
use Exception;
use ReflectionClass;
use ReflectionException;
use Voyager\Vessel\ControlPanel;
use Voyager\Contracts\Queue\Queue;
use Voyager\NutsAndBolts\Collection;
use Voyager\Contracts\Queue\ShouldQueue;
use Voyager\NutsAndBolts\DataObjects\Arr;
use Voyager\NutsAndBolts\DataObjects\Str;
use Voyager\Contracts\Signals\NamedSignal;
use Voyager\NutsAndBolts\Concerns\Macroable;
use Voyager\Contracts\Vessel\TheServiceContainer;
use Voyager\NutsAndBolts\Concerns\ReflectsClosures;
use Voyager\Contracts\Broadcasting\ShouldBroadcast;
use Voyager\Contracts\Signals\ShouldDispatchAfterCommit;
use Voyager\Contracts\Signals\ShouldHandleSignalsAfterCommit;
use Voyager\Contracts\Broadcasting\Factory as BroadcastFactory;
use Voyager\Contracts\Signals\SignalDispatcher as DispatcherContract;

class SignalDispatcher implements DispatcherContract
{
    use Macroable, ReflectsClosures;

    /**
     * The IoC container instance.
     *
     * @var TheServiceContainer
     */
    protected TheServiceContainer $app;

    /**
     * The registered event listeners.
     *
     * @var array<string, callable|array|class-string|null>
     */
    protected array $listeners = [];

    /**
     * The wildcard listeners.
     *
     * @var array<string, callable|string>
     */
    protected array $wildcards = [];

    /**
     * The cached wildcard listeners.
     *
     * @var array<string, callable|string>
     */
    protected array $wildcards_cache = [];

    /**
     * The currently deferred events.
     *
     * @var array
     */
    protected array $deferred_events = [];

    /**
     * Indicates if events should be deferred.
     *
     * @var bool
     */
    protected bool $deferring_events = false;

    /**
     * The specific events to defer (null means defer all events).
     *
     * @var string[]|null
     */
    protected ?array $events_to_defer = null;

    /**
     * The database transaction manager resolver instance.
     *
     * `callable` is not a legal property type in PHP, so this is `mixed`.
     *
     * @var callable
     */
    protected mixed $transaction_manager_resolver = null;

    /**
     * The queue resolver instance.
     *
     * `callable` is not a legal property type in PHP, so this is `mixed`.
     *
     * @var callable(): Queue
     */
    protected mixed $queue_resolver = null;

    /**
     * Create a new event dispatcher instance.
     *
     * @param TheServiceContainer|null  $app
     */
    public function __construct(?TheServiceContainer $app = null)
    {
        $this->app = $app ?: new ControlPanel;
    }

    /**
     * Register an event listener with the dispatcher.
     *
     * @param QueuedClosure|callable|array|class-string|string $events
     * @param QueuedClosure|callable|array|class-string|null $listener
     * @return void
     * @throws ReflectionException
     */
    public function listen(QueuedClosure|callable|string|array $events, $listener = null): void
    {
        if ($events instanceof Closure) {
            new Collection($this->firstClosureParameterTypes($events))
                ->each(function ($event) use ($events) {
                    $this->listen($event, $events);
                });
            return;
        }
        elseif ($events instanceof QueuedClosure)
        {
            new Collection($this->firstClosureParameterTypes($events->closure))
                ->each(function ($event) use ($events) {
                    $this->listen($event, $events->resolve());
                });
            return;
        }
        elseif ($listener instanceof QueuedClosure)
        {
            $listener = $listener->resolve();
        }

        foreach ((array) $events as $event)
        {
            if (str_contains($event, '*'))
            {
                $this->setupWildcardListen($event, $listener);
            }
            else
            {
                $this->listeners[$event][] = $listener;
            }
        }
    }

    /**
     * Determine if a given event has listeners.
     *
     * @param string $event_name
     * @return bool
     */
    public function hasListeners(string $event_name): bool
    {
        return isset($this->listeners[$event_name]) ||
            isset($this->wildcards[$event_name]) ||
            $this->hasWildcardListeners($event_name);
    }

    /**
     * Register an event subscriber with the dispatcher.
     *
     * @param object|string $subscriber
     * @return void
     * @throws ReflectionException
     */
    public function subscribe(object|string $subscriber): void
    {
        $subscriber = $this->resolveSubscriber($subscriber);

        $events = $subscriber->subscribe($this);

        if (is_array($events))
        {
            foreach ($events as $event => $listeners)
            {
                foreach (Arr::wrap($listeners) as $listener)
                {
                    if (is_string($listener) && method_exists($subscriber, $listener))
                    {
                        $this->listen($event, [get_class($subscriber), $listener]);

                        continue;
                    }

                    $this->listen($event, $listener);
                }
            }
        }
    }

    /**
     * Fire an event until the first non-null response is returned.
     *
     * Returns whatever the halting listener returned, so this is `mixed` rather
     * than `array|null`: invokeListeners() returns the bare listener response.
     *
     * @param object|string $event
     * @param mixed|array $payload
     * @return mixed The first non-null answer a listener gave, or null when none did.
     */
    public function until(object|string $event, mixed $payload = []): mixed
    {
        return $this->dispatch($event, $payload, true);
    }

    /**
     * Fire an event and call the listeners.
     *
     * When $halt is true this returns the bare response of the first listener to
     * return a non-null value, which need not be an array. Hence `mixed`.
     *
     * @param object|string $event
     * @param mixed|array $payload
     * @param bool $halt
     * @return mixed
     * @throws ReflectionException
     */
    public function dispatch(object|string $event, mixed $payload = [], bool $halt = false): mixed
    {
        // When the given "event" is actually an object, we will assume it is an event
        // object, and use the class as the event name and this event itself as the
        // payload to the handler, which makes object-based events quite simple.
        [$is_event_object, $parsed_event, $parsed_payload] = [
            is_object($event),
            ...$this->parseEventAndPayload($event, $payload),
        ];

        // Kept apart from the payload: only a signal dispatched AS an object answers to its
        // own class. One handed in as the payload of a named dispatch is cargo, not the signal.
        $signal = $is_event_object ? $event : null;

        if ($this->shouldDeferEvent($parsed_event)) {
            $this->deferred_events[] = func_get_args();

            return null;
        }

        // If the event is not intended to be dispatched unless the current database
        // transaction is successful, we'll register a callback which will handle
        // dispatching this event on the next successful DB transaction commit.
        if ($is_event_object
            && $parsed_payload[0] instanceof ShouldDispatchAfterCommit
            && ! is_null($transactions = $this->resolveTransactionManager())
        ) {
            $transactions->addCallback(
                fn () => $this->invokeListeners($parsed_event, $parsed_payload, $halt, $signal)
            );

            return null;
        }

        return $this->invokeListeners($parsed_event, $parsed_payload, $halt, $signal);
    }

    /**
     * Register an event and payload to be fired later.
     *
     * @param string $event
     * @param object|array $payload
     * @return void
     * @throws ReflectionException
     */
    public function push(string $event, $payload = []): void
    {
        $this->listen($event.'_pushed', function () use ($event, $payload) {
            $this->dispatch($event, $payload);
        });
    }

    /**
     * Flush a set of pushed events.
     *
     * @param string $event
     * @return void
     * @throws ReflectionException
     */
    public function flush(string $event): void
    {
        $this->dispatch($event.'_pushed');
    }

    /**
     * Remove a set of listeners from the dispatcher.
     *
     * @param string $event
     * @return void
     */
    public function forget(string $event): void
    {
        if (str_contains($event, '*')) {
            unset($this->wildcards[$event]);
        } else {
            unset($this->listeners[$event]);
        }

        foreach ($this->wildcards_cache as $key => $listeners) {
            if (Str::is($event, $key)) {
                unset($this->wildcards_cache[$key]);
            }
        }
    }

    /**
     * Forget every pushed listener.
     *
     * @return void
     */
    public function forgetPushed(): void
    {
        foreach ($this->listeners as $key => $value) {
            if (str_ends_with($key, '_pushed')) {
                $this->forget($key);
            }
        }
    }

    /**
     * Determine if the given event has any wildcard listeners.
     *
     * @param  string  $event_name
     * @return bool
     */
    public function hasWildcardListeners(string $event_name): bool
    {
        return array_any($this->wildcards, fn($listeners, $key) => Str::is($key, $event_name));
    }

    /**
     * Get every listener for a given event name.
     *
     * A signal answers to three things at once: the name it was dispatched under, its own
     * class and interfaces when it is an object, and any wildcard matching either of those.
     * A listener registered under one of them hears the signal once, never twice.
     *
     * @param  string  $event_name
     * @param  object|null  $signal
     * @return array
     */
    /**
     * The listener store as registered, before any resolving or wildcard expansion.
     * For tooling that reports on wiring, not for dispatch.
     *
     * @return array<string, array<int, mixed>>
     */
    public function getRawListeners(): array
    {
        return $this->listeners;
    }

    public function getListeners(string $event_name, ?object $signal = null): array
    {
        $names = $this->listenerNamesFor($event_name, $signal);

        $listeners = [];

        foreach ($names as $name) {
            $listeners = array_merge($listeners, $this->prepareListeners($name));
        }

        return array_merge($listeners, $this->getWildcardListeners($names));
    }

    /**
     * Every name one signal answers to, in listening order and without repeats.
     *
     * A named signal whose name IS its class collapses to a single entry here, and that
     * is what keeps one listener from being called twice for the one signal.
     *
     * @param  string  $event_name
     * @param  object|null  $signal
     * @return list<string>
     */
    protected function listenerNamesFor(string $event_name, ?object $signal = null): array
    {
        $names = [$event_name];

        if (! is_null($signal)) {
            $names[] = get_class($signal);
            $names = array_merge($names, array_values(class_implements($signal)));
        } elseif (class_exists($event_name, false)) {
            // Dispatched by class name rather than by instance.
            $names = array_merge($names, array_values(class_implements($event_name)));
        }

        return array_values(array_unique($names));
    }

    /**
     * Set the queue resolver implementation.
     *
     * @param  callable(): Queue  $resolver
     * @return $this
     */
    public function setQueueResolver(callable $resolver): static
    {
        $this->queue_resolver = $resolver;

        return $this;
    }

    /**
     * Set the database transaction manager resolver implementation.
     *
     * @param  (callable(): (\Voyager\Database\DatabaseTransactionsManager|null))  $resolver
     * @return $this
     */
    public function setTransactionManagerResolver(callable $resolver): static
    {
        $this->transaction_manager_resolver = $resolver;

        return $this;
    }

    /**
     * Set up a wildcard listener callback.
     *
     * @param  string  $event
     * @param  callable|string|array|null  $listener
     * @return void
     */
    protected function setupWildcardListen(string $event, mixed $listener): void
    {
        $this->wildcards[$event][] = $listener;

        $this->wildcards_cache = [];
    }

    /**
     * Resolve the subscriber instance.
     *
     * @param object|class-string $subscriber
     * @return ($subscriber is object ? object : mixed)
     * @throws ReflectionException
     */
    protected function resolveSubscriber(object|string $subscriber): mixed
    {
        if (is_string($subscriber)) {
            return $this->app->make($subscriber);
        }

        return $subscriber;
    }

    /**
     * Parse the given event and payload and prepare them for dispatching.
     *
     * @param  mixed  $event
     * @param  mixed  $payload
     * @return array{string, array}
     */
    protected function parseEventAndPayload(mixed $event, mixed $payload): array
    {
        if (is_object($event)) {
            [$payload, $event] = [[$event], $this->signalName($event)];
        }

        return [$event, Arr::wrap($payload)];
    }

    /**
     * What a signal is called: its own name when it has one, otherwise its class.
     *
     * @param  object  $event
     * @return string
     */
    protected function signalName(object $event): string
    {
        return $event instanceof NamedSignal ? $event->name() : get_class($event);
    }

    /**
     * Determine if the given event should be deferred.
     *
     * @param  string  $event
     * @return bool
     */
    protected function shouldDeferEvent(string $event): bool
    {
        return $this->deferring_events && ($this->events_to_defer === null || in_array($event, $this->events_to_defer));
    }

    /**
     * Get the database transaction manager implementation from the resolver.
     *
     * Return stays `mixed`: \Voyager\Database\DatabaseTransactionsManager is not
     * ported yet (forward reference).
     *
     * @return \Voyager\Database\DatabaseTransactionsManager|null
     */
    protected function resolveTransactionManager(): mixed
    {
        return call_user_func($this->transaction_manager_resolver);
    }

    /**
     * Broadcast an event and call its listeners.
     *
     * @param string $event
     * @param array $payload
     * @param bool $halt
     * @param object|null $signal The signal itself, when one was dispatched as an object.
     * @return mixed
     * @throws ReflectionException
     */
    protected function invokeListeners(string $event, array $payload, bool $halt = false, ?object $signal = null): mixed
    {
        if ($this->shouldBroadcast($payload)) {
            $this->broadcastEvent($payload[0]);
        }

        $responses = [];

        foreach ($this->getListeners($event, $signal) as $listener) {
            $response = $listener($event, $payload);

            // If a response is returned from the listener and event halting is enabled
            // we will just return this response, and not call the rest of the event
            // listeners. Otherwise, we will add the response on the response list.
            if ($halt && ! is_null($response)) {
                return $response;
            }

            // If a boolean false is returned from a listener, we will stop propagating
            // the event to any further listeners down in the chain, else we keep on
            // looping through the listeners and firing every one in our sequence.
            if ($response === false) {
                break;
            }

            $responses[] = $response;
        }

        return $halt ? null : $responses;
    }

    /**
     * Determine if the payload has a broadcastable event.
     *
     * @param  array  $payload
     * @return bool
     */
    protected function shouldBroadcast(array $payload): bool
    {
        return isset($payload[0]) &&
            $payload[0] instanceof ShouldBroadcast &&
            $this->broadcastWhen($payload[0]);
    }

    /**
     * Check if the event should be broadcasted by the condition.
     *
     * @param  mixed  $event
     * @return bool
     */
    protected function broadcastWhen(mixed $event): bool
    {
        return method_exists($event, 'broadcastWhen')
            ? $event->broadcastWhen()
            : true;
    }

    /**
     * Queue a ShouldBroadcast signal onto the broadcast manager.
     *
     * @param ShouldBroadcast $event
     * @return void
     * @throws ReflectionException
     */
    protected function broadcastEvent(mixed $event): void
    {
        $this->app->make(BroadcastFactory::class)->queue($event);
    }

    /**
     * Prepare the listeners for a given event.
     *
     * @param  string  $event_name
     * @return callable[]
     */
    protected function prepareListeners(string $event_name): array
    {
        $listeners = [];

        foreach ($this->listeners[$event_name] ?? [] as $listener) {
            $listeners[] = $this->makeListener($listener);
        }

        return $listeners;
    }

    /**
     * Register an event listener with the dispatcher.
     *
     * $listener stays `mixed`: listen() stores a null listener when called with
     * only an event name, and that null reaches this method via prepareListeners().
     *
     * @param  callable|string|array{class-string, string}|null  $listener
     * @param  bool  $wildcard
     * @return callable
     */
    public function makeListener(mixed $listener, bool $wildcard = false): Closure
    {
        if (is_string($listener)) {
            return $this->createClassListener($listener, $wildcard);
        }

        if (is_array($listener) && isset($listener[0]) && is_string($listener[0])) {
            return $this->createClassListener($listener, $wildcard);
        }

        return function ($event, $payload) use ($listener, $wildcard) {
            if ($wildcard) {
                return $listener($event, $payload);
            }

            return $listener(...array_values($payload));
        };
    }

    /**
     * Create a class based listener using the IoC container.
     *
     * @param  array{class-string, string}|string  $listener
     * @param  bool  $wildcard
     * @return callable
     */
    public function createClassListener(array|string $listener, bool $wildcard = false): Closure
    {
        return function ($event, $payload) use ($listener, $wildcard) {
            if ($wildcard) {
                return call_user_func($this->createClassCallable($listener), $event, $payload);
            }

            $callable = $this->createClassCallable($listener);

            return $callable(...array_values($payload));
        };
    }

    /**
     * Create the class based event callable.
     *
     * @param array{class-string, string}|string $listener
     * @return callable|array
     * @throws ReflectionException
     */
    protected function createClassCallable(array|string $listener): callable|array
    {
        [$class, $method] = is_array($listener)
            ? $listener
            : $this->parseClassCallable($listener);

        if (! method_exists($class, $method)) {
            $method = '__invoke';
        }

        if ($this->handlerShouldBeQueued($class)) {
            return $this->createQueuedHandlerCallable($class, $method);
        }

        $listener = $this->app->make($class);

        return $this->handlerShouldBeDispatchedAfterDatabaseTransactions($listener)
        && ! in_array($method, ['creating', 'updating', 'saving', 'deleting', 'restoring', 'forceDeleting'])
            ? $this->createCallbackForListenerRunningAfterCommits($listener, $method)
            : [$listener, $method];
    }

    /**
     * Parse the class listener into class and method.
     *
     * @param  string  $listener
     * @return array{class-string, string}
     */
    protected function parseClassCallable(string $listener): array
    {
        return Str::parseCallback($listener, 'handle');
    }

    /**
     * Determine if the event handler class should be queued.
     *
     * @param  class-string  $class
     * @return bool
     *
     * @phpstan-assert-if-true class-string<ShouldQueue> $class
     */
    protected function handlerShouldBeQueued(string $class): bool
    {
        try {
            return new ReflectionClass($class)->implementsInterface(
                ShouldQueue::class
            );
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Create a callable for putting an event handler on the queue.
     *
     * @param  class-string  $class
     * @param  string  $method
     * @return callable(): void
     */
    protected function createQueuedHandlerCallable(string $class, string $method): Closure
    {
        return function () use ($class, $method) {
            $arguments = array_map(function ($a) {
                return is_object($a) ? clone $a : $a;
            }, func_get_args());

            if ($this->handlerWantsToBeQueued($class, $arguments)) {
                $this->queueHandler($class, $method, $arguments);
            }
        };
    }

    /**
     * Determine if the given event handler should be dispatched after all database transactions have committed.
     *
     * @param  mixed  $listener
     * @return bool
     */
    protected function handlerShouldBeDispatchedAfterDatabaseTransactions(mixed $listener): bool
    {
        return (($listener->afterCommit ?? null) ||
                $listener instanceof ShouldHandleSignalsAfterCommit) &&
            $this->resolveTransactionManager();
    }

    /**
     * Create a callable for dispatching a listener after database transactions.
     *
     * @param  mixed  $listener
     * @param  string  $method
     * @return callable
     */
    protected function createCallbackForListenerRunningAfterCommits(mixed $listener, string $method): Closure
    {
        return function () use ($method, $listener) {
            $payload = func_get_args();

            $this->resolveTransactionManager()->addCallback(
                function () use ($listener, $method, $payload) {
                    $listener->$method(...$payload);
                }
            );
        };
    }

    /**
     * Get the wildcard listeners matching any name this signal answers to.
     *
     * @param  list<string>  $names
     * @return array
     */
    protected function getWildcardListeners(array $names): array
    {
        $cache_key = implode('|', $names);

        if (isset($this->wildcards_cache[$cache_key])) {
            return $this->wildcards_cache[$cache_key];
        }

        $wildcards = [];

        foreach ($this->wildcards as $pattern => $listeners)
        {
            // One pattern fires once, even when it matches both the name and the class.
            if (array_any($names, fn (string $name) => Str::is($pattern, $name)))
            {
                foreach ($listeners as $listener)
                {
                    $wildcards[] = $this->makeListener($listener, true);
                }
            }
        }

        return $this->wildcards_cache[$cache_key] = $wildcards;
    }

}
