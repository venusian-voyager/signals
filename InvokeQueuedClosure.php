<?php

namespace Voyager\Signals;

use Throwable;
use Laravel\SerializableClosure\SerializableClosure;

/** The listener a QueuedClosure becomes on the queue: it calls the closure, or its catch callbacks. */
class InvokeQueuedClosure
{
    /**
     * @param  array<int, mixed>  $arguments
     */
    public function handle(SerializableClosure $closure, array $arguments): void
    {
        call_user_func($closure->getClosure(), ...$arguments);
    }

    /**
     * @param  array<int, mixed>  $arguments
     * @param  list<SerializableClosure>  $catch
     */
    public function failed(SerializableClosure $closure, array $arguments, array $catch, Throwable $exception): void
    {
        foreach ($catch as $callback) {
            $callback->__invoke(...[...$arguments, $exception]);
        }
    }
}
