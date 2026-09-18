<?php

namespace Voyager\Events;

use Closure;
use Voyager\NutsAndBolts\Collection;
use Laravel\SerializableClosure\SerializableClosure;

use function Voyager\NutsAndBolts\Helpers\enum_value;

class QueuedClosure
{
    /**
     * The underlying Closure.
     *
     * @var \Closure
     */
    public Closure $closure;

    /**
     * The name of the connection the job should be sent to.
     *
     * @var string|null
     */
    public ?string $connection = null;

    /**
     * The name of the queue the job should be sent to.
     *
     * @var string|null
     */
    public ?string $queue = null;

    /**
     * The job "group" the job should be sent to.
     *
     * @var string|null
     */
    public ?string $messageGroup = null;

    /**
     * The job deduplicator callback the job should use to generate the deduplication ID.
     *
     * Kept as `mixed`: withDeduplicator() only wraps a Closure in a
     * SerializableClosure and stores any other callable as-is.
     *
     * @var \Laravel\SerializableClosure\SerializableClosure|callable|null
     */
    public mixed $deduplicator = null;

    /**
     * The number of seconds before the job should be made available.
     *
     * @var \DateTimeInterface|\DateInterval|int|null
     */
    public \DateTimeInterface|\DateInterval|int|null $delay = null;

    /**
     * Every "catch" callback for the queued closure.
     *
     * @var array
     */
    public array $catchCallbacks = [];

    /**
     * Create a new queued closure event listener resolver.
     *
     * @param  \Closure  $closure
     */
    public function __construct(Closure $closure)
    {
        $this->closure = $closure;
    }

    /**
     * Set the desired connection for the job.
     *
     * @param  \UnitEnum|string|null  $connection
     * @return $this
     */
    public function onConnection(\UnitEnum|string|null $connection): static
    {
        $this->connection = enum_value($connection);

        return $this;
    }

    /**
     * Set the desired queue for the job.
     *
     * @param  \UnitEnum|string|null  $queue
     * @return $this
     */
    public function onQueue(\UnitEnum|string|null $queue): static
    {
        $this->queue = enum_value($queue);

        return $this;
    }

    /**
     * Set the desired job "group".
     *
     * This feature is only supported by some queues, such as Amazon SQS.
     *
     * @param  \UnitEnum|string|null  $group
     * @return $this
     */
    public function onGroup(\UnitEnum|string|null $group): static
    {
        $this->messageGroup = enum_value($group);

        return $this;
    }

    /**
     * Set the desired job deduplicator callback.
     *
     * This feature is only supported by some queues, such as Amazon SQS FIFO.
     *
     * $deduplicator stays `mixed`: any callable shape is accepted and stored raw
     * unless it is a Closure.
     *
     * @param  callable|null  $deduplicator
     * @return $this
     */
    public function withDeduplicator(mixed $deduplicator): static
    {
        $this->deduplicator = $deduplicator instanceof Closure
            ? new SerializableClosure($deduplicator)
            : $deduplicator;

        return $this;
    }

    /**
     * Set the desired delay in seconds for the job.
     *
     * @param  \DateTimeInterface|\DateInterval|int|null  $delay
     * @return $this
     */
    public function delay(\DateTimeInterface|\DateInterval|int|null $delay): static
    {
        $this->delay = $delay;

        return $this;
    }

    /**
     * Specify a callback that should be invoked if the queued listener job fails.
     *
     * @param  \Closure  $closure
     * @return $this
     */
    public function catch(Closure $closure): static
    {
        $this->catchCallbacks[] = $closure;

        return $this;
    }

    /**
     * Resolve the actual event listener callback.
     *
     * @return \Closure
     */
    public function resolve(): Closure
    {
        return function (...$arguments) {
            dispatch(new CallQueuedListener(InvokeQueuedClosure::class, 'handle', [
                'closure' => new SerializableClosure($this->closure),
                'arguments' => $arguments,
                'catch' => (new Collection($this->catchCallbacks))
                    ->map(fn ($callback) => new SerializableClosure($callback))
                    ->all(),
            ]))
                ->onConnection($this->connection)
                ->onQueue($this->queue)
                ->delay($this->delay)
                ->onGroup($this->messageGroup)
                ->withDeduplicator($this->deduplicator);
        };
    }
}
