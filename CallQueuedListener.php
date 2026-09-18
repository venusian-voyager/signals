<?php

namespace Voyager\Events;

use Voyager\Bus\Queueable;
use Voyager\Vessel\Vessel;
use Voyager\Contracts\Cache\Repository as Cache;
use Voyager\Contracts\Queue\Job;
use Voyager\Contracts\Queue\ShouldQueue;
use Voyager\Queue\InteractsWithQueue;

class CallQueuedListener implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /**
     * The listener class name.
     *
     * @var class-string
     */
    public string $class;

    /**
     * The listener method.
     *
     * @var string
     */
    public string $method;

    /**
     * The data to be passed to the listener.
     *
     * Kept as `mixed`: prepareData() explicitly handles $data arriving as a
     * serialized string, so this is not always an array.
     *
     * @var array|string
     */
    public mixed $data = null;

    /**
     * The number of times the job may be attempted.
     *
     * @var int|null
     */
    public ?int $tries = null;

    /**
     * The maximum number of exceptions allowed, regardless of attempts.
     *
     * @var int|null
     */
    public ?int $maxExceptions = null;

    /**
     * The number of seconds to wait before retrying a job that encountered an uncaught exception.
     *
     * Kept as `mixed`: a listener's backoff() may return an array of per-attempt
     * delays as well as a single integer.
     *
     * @var int|array|null
     */
    public mixed $backoff = null;

    /**
     * The timestamp indicating when the job should timeout.
     *
     * Kept as `mixed`: a listener's retryUntil() commonly returns a
     * \DateTimeInterface rather than an integer timestamp.
     *
     * @var \DateTimeInterface|int|null
     */
    public mixed $retryUntil = null;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int|null
     */
    public ?int $timeout = null;

    /**
     * Indicates if the job should fail if the timeout is exceeded.
     *
     * @var bool
     */
    public bool $failOnTimeout = false;

    /**
     * Indicates if the job should be encrypted.
     *
     * @var bool
     */
    public bool $shouldBeEncrypted = false;

    /**
     * Indicates if the listener should be unique.
     */
    public bool $shouldBeUnique = false;

    /**
     * Indicates if the listener should be unique until processing begins.
     */
    public bool $shouldBeUniqueUntilProcessing = false;

    /**
     * The unique ID of the listener.
     */
    public mixed $uniqueId = null;

    /**
     * The number of seconds the unique lock should be maintained.
     */
    public ?int $uniqueFor = null;

    /**
     * Create a new job instance.
     *
     * @param  class-string  $class
     * @param  string  $method
     * @param  array|string  $data
     */
    public function __construct(string $class, string $method, mixed $data)
    {
        $this->data = $data;
        $this->class = $class;
        $this->method = $method;
    }

    /**
     * Handle the queued job.
     *
     * @param  \Voyager\Vessel\Vessel  $vessel
     * @return void
     */
    public function handle(Vessel $vessel): void
    {
        $this->prepareData();

        $handler = $this->setJobInstanceIfNecessary(
            $this->job, $vessel->make($this->class)
        );

        $handler->{$this->method}(...array_values($this->data));
    }

    /**
     * Determine if the listener should be unique.
     */
    public function shouldBeUnique(): bool
    {
        return $this->shouldBeUnique;
    }

    /**
     * Determine if the listener should be unique until processing begins.
     */
    public function shouldBeUniqueUntilProcessing(): bool
    {
        return $this->shouldBeUniqueUntilProcessing;
    }

    /**
     * Get the unique ID for the listener.
     */
    public function uniqueId(): mixed
    {
        return $this->uniqueId;
    }

    /**
     * Get the number of seconds the unique lock should be maintained.
     */
    public function uniqueFor(): ?int
    {
        return $this->uniqueFor;
    }

    /**
     * Get the cache store used to manage unique locks.
     */
    public function uniqueVia(): ?Cache
    {
        $listener = Vessel::getInstance()->make($this->class);

        if (! method_exists($listener, 'uniqueVia')) {
            return null;
        }

        $this->prepareData();

        return $listener->uniqueVia(...array_values($this->data));
    }

    /**
     * Set the job instance of the given class if necessary.
     *
     * @param  \Voyager\Contracts\Queue\Job  $job
     * @param  object  $instance
     * @return object
     */
    protected function setJobInstanceIfNecessary(Job $job, object $instance): object
    {
        if (in_array(InteractsWithQueue::class, class_uses_recursive($instance))) {
            $instance->setJob($job);
        }

        return $instance;
    }

    /**
     * Call the failed method on the job instance.
     *
     * The event instance and the exception will be passed.
     *
     * @param  \Throwable  $e
     * @return void
     */
    public function failed(\Throwable $e): void
    {
        $this->prepareData();

        $handler = Vessel::getInstance()->make($this->class);

        $parameters = array_merge(array_values($this->data), [$e]);

        if (method_exists($handler, 'failed')) {
            $handler->failed(...$parameters);
        }
    }

    /**
     * Unserialize the data if needed.
     *
     * @return void
     */
    protected function prepareData(): void
    {
        if (is_string($this->data)) {
            $this->data = unserialize($this->data);
        }
    }

    /**
     * Get the display name for the queued job.
     *
     * @return string
     */
    public function displayName(): string
    {
        return $this->class;
    }

    /**
     * Prepare the instance for cloning.
     *
     * @return void
     */
    public function __clone(): void
    {
        $this->data = array_map(function ($data) {
            return is_object($data) ? clone $data : $data;
        }, $this->data);
    }
}
