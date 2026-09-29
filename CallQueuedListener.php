<?php

namespace Voyager\Signals;

use Throwable;
use ReflectionClass;
use Voyager\Contracts\Queue\ShouldBeUnique;
use Voyager\Contracts\Cache\Repository as Cache;
use Voyager\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Voyager\Bus\Queueable;
use Voyager\Vessel\ControlPanel;
use Voyager\Contracts\Queue\Job;
use Voyager\Queue\InteractsWithQueue;
use Voyager\Contracts\Queue\ShouldQueue;
use Voyager\Contracts\Vessel\TheServiceContainer;

/**
 * A queued listener as a job: the listener's class, the method to call, and the signal's
 * arguments. The worker builds the listener fresh and calls it.
 */
class CallQueuedListener implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public ?int $tries = null;

    public ?int $maxExceptions = null;

    public mixed $backoff = null;

    public mixed $retryUntil = null;

    public ?int $timeout = null;

    public bool $failOnTimeout = false;

    public bool $shouldBeEncrypted = false;

    /**
     * @param  class-string  $class
     * @param  array<int|string, mixed>|string  $data  the signal's arguments, or them serialized
     */
    public function __construct(
        public string $class,
        public string $method,
        public array|string $data,
    ) {}

    public function handle(?TheServiceContainer $container = null): mixed
    {
        $this->prepareData();

        $handler = $this->setJobInstanceIfNecessary($this->job, ($container ?? ControlPanel::getInstance())->make($this->class));

        return $handler->{$this->method}(...array_values($this->data));
    }

    protected function setJobInstanceIfNecessary(?Job $job, object $instance): object
    {
        if (! is_null($job) && in_array(InteractsWithQueue::class, class_uses_recursive($instance))) {
            $instance->setJob($job);
        }

        return $instance;
    }

    /** Hands the failure to the listener's failed() when it has one. */
    public function failed(Throwable $e): void
    {
        $this->prepareData();

        $handler = ControlPanel::getInstance()->make($this->class);

        if (method_exists($handler, 'failed')) {
            $handler->failed(...[...array_values($this->data), $e]);
        }
    }

    protected function prepareData(): void
    {
        if (is_string($this->data)) {
            $this->data = unserialize($this->data);
        }
    }

    /** Whether the listener is unique on the queue: one pushed copy at a time. */
    public function shouldBeUnique(): bool
    {
        return is_a($this->class, ShouldBeUnique::class, true);
    }

    /** Whether the listener's uniqueness ends when it starts running rather than when it finishes. */
    public function shouldBeUniqueUntilProcessing(): bool
    {
        return is_a($this->class, ShouldBeUniqueUntilProcessing::class, true);
    }

    /** The listener's unique id for these arguments: its uniqueId() or uniqueId property. */
    public function uniqueId(): string
    {
        $listener = $this->listener();

        return (string) (method_exists($listener, 'uniqueId')
            ? $listener->uniqueId(...array_values($this->arguments()))
            : ($listener->uniqueId ?? ''));
    }

    /** Seconds the unique lock lasts: the listener's uniqueFor() or uniqueFor property. */
    public function uniqueFor(): int
    {
        $listener = $this->listener();

        return (int) (method_exists($listener, 'uniqueFor')
            ? $listener->uniqueFor(...array_values($this->arguments()))
            : ($listener->uniqueFor ?? 0));
    }

    /** The cache the unique lock is taken in; null for the default store. */
    public function uniqueVia(): ?Cache
    {
        $listener = $this->listener();

        return method_exists($listener, 'uniqueVia') ? $listener->uniqueVia(...array_values($this->arguments())) : null;
    }

    /** The listener read for its options, without running its constructor. */
    protected function listener(): object
    {
        return new ReflectionClass($this->class)->newInstanceWithoutConstructor();
    }

    /** @return array<int|string, mixed> */
    protected function arguments(): array
    {
        return is_string($this->data) ? unserialize($this->data) : $this->data;
    }

    public function displayName(): string
    {
        return $this->class;
    }

    public function __clone()
    {
        $this->data = array_map(fn (mixed $data): mixed => is_object($data) ? clone $data : $data, (array) $this->data);
    }
}
