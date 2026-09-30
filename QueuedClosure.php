<?php

namespace Voyager\Signals;

use Closure;
use DateInterval;
use DateTimeInterface;
use UnitEnum;
use Voyager\NutsAndBolts\Collection;
use Laravel\SerializableClosure\SerializableClosure;

use function Voyager\NutsAndBolts\enum_value;

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
    public ?string $message_group = null;

    /**
     * The job deduplicator callback the job should use to generate the deduplication ID.
     *
     * @var \Laravel\SerializableClosure\SerializableClosure|null
     */
    public ?SerializableClosure $deduplicator = null;

    /**
     * The number of seconds before the job should be made available.
     *
     * @var \DateTimeInterface|\DateInterval|int|null
     */
    public int|null|\DateTimeInterface|\DateInterval $delay = null;

    /**
     * All of the "catch" callbacks for the queued closure.
     *
     * @var array
     */
    public array $catch_callbacks = [];

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
     * @param \UnitEnum|string|null $connection
     * @return $this
     */
    public function onConnection(UnitEnum|string|null $connection): static
    {
        $this->connection = enum_value($connection);

        return $this;
    }

    /**
     * Set the desired queue for the job.
     *
     * @param \UnitEnum|string|null $queue
     * @return $this
     */
    public function onQueue(UnitEnum|string|null $queue): static
    {
        $this->queue = enum_value($queue);

        return $this;
    }

    /**
     * Set the desired job "group".
     *
     * This feature is only supported by some queues, such as Amazon SQS.
     *
     * @param \UnitEnum|string $group
     * @return $this
     */
    public function onGroup(UnitEnum|string $group): static
    {
        $this->message_group = enum_value($group);

        return $this;
    }

    /**
     * Set the desired job deduplicator callback.
     *
     * This feature is only supported by some queues, such as Amazon SQS FIFO.
     *
     * @param callable|null $deduplicator
     * @return $this
     */
    public function withDeduplicator(?callable $deduplicator): static
    {
        $this->deduplicator = is_null($deduplicator)
            ? null
            : new SerializableClosure($deduplicator instanceof Closure ? $deduplicator : Closure::fromCallable($deduplicator));

        return $this;
    }

    /**
     * Set the desired delay in seconds for the job.
     *
     * @param \DateInterval|\DateTimeInterface|int|null $delay
     * @return $this
     */
    public function delay(DateInterval|DateTimeInterface|int|null $delay): static
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
        $this->catch_callbacks[] = $closure;

        return $this;
    }

    /**
     * Resolve the actual event listener callback.
     *
     * @return \Closure
     */
    public function resolve()
    {
        return function (...$arguments) {
            $pending = dispatch(new CallQueuedListener(InvokeQueuedClosure::class, 'handle', [
                'closure' => new SerializableClosure($this->closure),
                'arguments' => $arguments,
                'catch' => (new Collection($this->catch_callbacks))
                    ->map(fn ($callback) => new SerializableClosure($callback))
                    ->all(),
            ]))
                ->onConnection($this->connection)
                ->onQueue($this->queue)
                ->delay($this->delay);

            if (! is_null($this->message_group)) {
                $pending->onGroup($this->message_group);
            }

            if (! is_null($this->deduplicator)) {
                $pending->withDeduplicator($this->deduplicator);
            }
        };
    }
}
