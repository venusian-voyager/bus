<?php

namespace Voyager\Bus;

use Closure;
use Voyager\Contracts\Bus\QueueingDispatcher;
use Voyager\Contracts\Vessel\Vessel;
use Voyager\Contracts\Queue\Queue;
use Voyager\Contracts\Queue\ShouldQueue;
use Voyager\System\Bus\PendingChain;
use Voyager\Pipeline\Pipeline;
use Voyager\Queue\InteractsWithQueue;
use Voyager\Queue\Jobs\SyncJob;
use Voyager\NutsAndBolts\Collection;
use RuntimeException;

class Dispatcher implements QueueingDispatcher
{
    /**
     * The container implementation.
     *
     * @var \Voyager\Contracts\Vessel\Vessel
     */
    protected Vessel $container;

    /**
     * The pipeline instance for the bus.
     *
     * @var \Voyager\Pipeline\Pipeline
     */
    protected Pipeline $pipeline;

    /**
     * The pipes to send commands through before dispatching.
     *
     * @var array
     */
    protected array $pipes = [];

    /**
     * The command to handler mapping for non-self-handling events.
     *
     * @var array
     */
    protected array $handlers = [];

    /**
     * The queue resolver callback.
     *
     * @var \Closure|null
     */
    protected ?Closure $queueResolver = null;

    /**
     * Indicates if dispatching after response is disabled.
     *
     * @var bool
     */
    protected bool $allowsDispatchingAfterResponses = true;

    /**
     * Create a new command dispatcher instance.
     */
    public function __construct(Vessel $container, ?Closure $queueResolver = null)
    {
        $this->container = $container;
        $this->queueResolver = $queueResolver;
        $this->pipeline = new Pipeline($container);
    }

    /**
     * Dispatch a command to its appropriate handler.
     *
     * @param  mixed  $command
     * @return mixed
     */
    public function dispatch(mixed $command): mixed
    {
        return $this->queueResolver && $this->commandShouldBeQueued($command)
            ? $this->dispatchToQueue($command)
            : $this->dispatchNow($command);
    }

    /**
     * Dispatch a command to its appropriate handler in the current process.
     *
     * Queueable jobs will be dispatched to the "sync" queue.
     *
     * @param  mixed  $command
     * @param  mixed  $handler
     * @return mixed
     */
    public function dispatchSync(mixed $command, mixed $handler = null): mixed
    {
        if ($this->queueResolver &&
            $this->commandShouldBeQueued($command) &&
            method_exists($command, 'onConnection')) {
            return $this->dispatchToQueue($command->onConnection('sync'));
        }

        return $this->dispatchNow($command, $handler);
    }

    /**
     * Dispatch a command to its appropriate handler in the current process without using the synchronous queue.
     *
     * @param  mixed  $command
     * @param  mixed  $handler
     * @return mixed
     */
    public function dispatchNow(mixed $command, mixed $handler = null): mixed
    {
        $uses = class_uses_recursive($command);

        if (isset($uses[InteractsWithQueue::class], $uses[Queueable::class]) && ! $command->job) {
            $command->setJob(new SyncJob($this->container, json_encode([]), 'sync', 'sync'));
        }

        if ($handler || $handler = $this->getCommandHandler($command)) {
            $callback = function ($command) use ($handler) {
                $method = method_exists($handler, 'handle') ? 'handle' : '__invoke';

                return $handler->{$method}($command);
            };
        } else {
            $callback = function ($command) {
                $method = method_exists($command, 'handle') ? 'handle' : '__invoke';

                return $this->container->call([$command, $method]);
            };
        }

        return $this->pipeline->send($command)->through($this->pipes)->then($callback);
    }

    /**
     * Attempt to find the batch with the given ID.
     *
     * @return \Voyager\Bus\Batch|null
     */
    public function findBatch(string $batchId): ?Batch
    {
        return $this->container->make(BatchRepository::class)->find($batchId);
    }

    /**
     * Create a new batch of queueable jobs.
     *
     * @param  \Voyager\NutsAndBolts\Collection|mixed  $jobs
     * @return \Voyager\Bus\PendingBatch
     */
    public function batch(mixed $jobs): PendingBatch
    {
        return new PendingBatch($this->container, Collection::wrap($jobs));
    }

    /**
     * Create a new chain of queueable jobs.
     *
     * @param  \Voyager\NutsAndBolts\Collection|array|null  $jobs
     * @return \Voyager\System\Bus\PendingChain
     */
    public function chain(mixed $jobs = null): PendingChain
    {
        $jobs = Collection::wrap($jobs);
        $jobs = ChainedBatch::prepareNestedBatches($jobs);

        return new PendingChain($jobs->shift(), $jobs->toArray());
    }

    /**
     * Determine if the given command has a handler.
     *
     * @param  mixed  $command
     * @return bool
     */
    public function hasCommandHandler(mixed $command): bool
    {
        return array_key_exists(get_class($command), $this->handlers);
    }

    /**
     * Retrieve the handler for a command.
     *
     * @param  mixed  $command
     * @return mixed
     */
    public function getCommandHandler(mixed $command): mixed
    {
        if ($this->hasCommandHandler($command)) {
            return $this->container->make($this->handlers[get_class($command)]);
        }

        return false;
    }

    /**
     * Determine if the given command should be queued.
     *
     * @param  mixed  $command
     * @return bool
     */
    protected function commandShouldBeQueued(mixed $command): bool
    {
        return $command instanceof ShouldQueue;
    }

    /**
     * Dispatch a command to its appropriate handler behind a queue.
     *
     * @param  mixed  $command
     * @return mixed
     *
     * @throws \RuntimeException
     */
    public function dispatchToQueue(mixed $command): mixed
    {
        $connection = $command->connection ?? null;

        $queue = ($this->queueResolver)($connection);

        if (! $queue instanceof Queue) {
            throw new RuntimeException('Queue resolver did not return a Queue implementation.');
        }

        if (method_exists($command, 'queue')) {
            return $command->queue($queue, $command);
        }

        return $this->pushCommandToQueue($queue, $command);
    }

    /**
     * Push the command onto the given queue instance.
     *
     * @param  \Voyager\Contracts\Queue\Queue  $queue
     * @param  mixed  $command
     * @return mixed
     */
    protected function pushCommandToQueue($queue, mixed $command): mixed
    {
        if (isset($command->delay)) {
            return $queue->later($command->delay, $command, queue: $command->queue ?? null);
        }

        return $queue->push($command, queue: $command->queue ?? null);
    }

    /**
     * Dispatch a command to its appropriate handler after the current process.
     *
     * @param  mixed  $command
     * @param  mixed  $handler
     * @return void
     */
    public function dispatchAfterResponse(mixed $command, mixed $handler = null): void
    {
        if (! $this->allowsDispatchingAfterResponses) {
            $this->dispatchSync($command);

            return;
        }

        $this->container->terminating(function () use ($command, $handler) {
            $this->dispatchSync($command, $handler);
        });
    }

    /**
     * Set the pipes through which commands should be piped before dispatching.
     *
     * @return $this
     */
    public function pipeThrough(array $pipes): static
    {
        $this->pipes = $pipes;

        return $this;
    }

    /**
     * Map a command to a handler.
     *
     * @return $this
     */
    public function map(array $map): static
    {
        $this->handlers = array_merge($this->handlers, $map);

        return $this;
    }

    /**
     * Allow dispatching after responses.
     *
     * @return $this
     */
    public function withDispatchingAfterResponses(): static
    {
        $this->allowsDispatchingAfterResponses = true;

        return $this;
    }

    /**
     * Disable dispatching after responses.
     *
     * @return $this
     */
    public function withoutDispatchingAfterResponses(): static
    {
        $this->allowsDispatchingAfterResponses = false;

        return $this;
    }
}
