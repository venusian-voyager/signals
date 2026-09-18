<?php

namespace Voyager\Events;

use Laravel\SerializableClosure\SerializableClosure;
use Voyager\NutsAndBolts\Collection;

class InvokeQueuedClosure
{
    /**
     * Handle the event.
     *
     * @param  \Laravel\SerializableClosure\SerializableClosure  $closure
     * @param  array  $arguments
     * @return void
     */
    public function handle(SerializableClosure $closure, array $arguments): void
    {
        call_user_func($closure->getClosure(), ...$arguments);
    }

    /**
     * Handle a job failure.
     *
     * @param  \Laravel\SerializableClosure\SerializableClosure  $closure
     * @param  array  $arguments
     * @param  array  $catchCallbacks
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(SerializableClosure $closure, array $arguments, array $catchCallbacks, \Throwable $exception): void
    {
        $arguments[] = $exception;

        (new Collection($catchCallbacks))->each->__invoke(...$arguments);
    }
}
