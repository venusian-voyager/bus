<?php

namespace Voyager\Bus;

use Closure;
use Voyager\Bus\Events\BatchDispatched;
use Voyager\Contracts\Vessel\Vessel;
use Voyager\Contracts\Events\Dispatcher as EventDispatcher;
use Voyager\NutsAndBolts\DataObjects\Arr;
use Voyager\NutsAndBolts\Collection;
use Voyager\NutsAndBolts\Concerns\Conditionable;
use Laravel\SerializableClosure\SerializableClosure;
use RuntimeException;
use Throwable;
use UnitEnum;

use function Voyager\NutsAndBolts\Helpers\enum_value;

class PendingBatch
{
    use Conditionable;

    /**
     * The IoC container instance.
     *
     * @var \Voyager\Contracts\Vessel\Vessel
     */
    protected Vessel $container;

    /**
     * The batch name.
     *
     * @var string
     */
    public string $name = '';

    /**
     * The jobs that belong to the batch.
     *
     * @var \Voyager\NutsAndBolts\Collection
     */
    public Collection $jobs;

    /**
     * The batch options.
     *
     * @var array
     */
    public array $options = [];

    /**
     * Jobs that have been verified to contain the Batchable trait.
     *
     * @var array<class-string, bool>
     */
    protected static array $batchableClasses = [];

    /**
     * Create a new pending batch instance.
     *
     * @param  \Voyager\Contracts\Vessel\Vessel  $container
     * @param  \Voyager\NutsAndBolts\Collection  $jobs
     */
    public function __construct(Vessel $container, Collection $jobs)
    {
        $this->container = $container;

        $this->jobs = $jobs->filter()->values()->each(function (object|array $job) {
            $this->ensureJobIsBatchable($job);
        });
    }

    /**
     * Add jobs to the batch.
     *
     * @param  iterable|object|array  $jobs
     * @return $this
     */
    public function add(mixed $jobs): static
    {
        $jobs = is_iterable($jobs) ? $jobs : Arr::wrap($jobs);

        foreach ($jobs as $job) {
            $this->ensureJobIsBatchable($job);

            $this->jobs->push($job);
        }

        return $this;
    }

    /**
     * Ensure the given job is batchable.
     *
     * @param  object|array  $job
     * @return void
     */
    protected function ensureJobIsBatchable(object|array $job): void
    {
        foreach (Arr::wrap($job) as $job) {
            if ($job instanceof PendingBatch || $job instanceof Closure) {
                return;
            }

            if (! (static::$batchableClasses[$job::class] ?? false) && ! in_array(Batchable::class, class_uses_recursive($job))) {
                static::$batchableClasses[$job::class] = false;

                throw new RuntimeException(sprintf('Attempted to batch job [%s], but it does not use the Batchable trait.', $job::class));
            }

            static::$batchableClasses[$job::class] = true;
        }
    }

    /**
     * Add a callback to be executed when the batch is stored.
     *
     * @param  callable  $callback
     * @return $this
     */
    public function before(Closure|callable $callback): static
    {
        $this->registerCallback('before', $callback);

        return $this;
    }

    /**
     * Get the "before" callbacks that have been registered with the pending batch.
     *
     * @return array
     */
    public function beforeCallbacks(): array
    {
        return $this->options['before'] ?? [];
    }

    /**
     * Add a callback to be executed after a job in the batch have executed successfully.
     *
     * @param  callable  $callback
     * @return $this
     */
    public function progress(Closure|callable $callback): static
    {
        $this->registerCallback('progress', $callback);

        return $this;
    }

    /**
     * Get the "progress" callbacks that have been registered with the pending batch.
     *
     * @return array
     */
    public function progressCallbacks(): array
    {
        return $this->options['progress'] ?? [];
    }

    /**
     * Add a callback to be executed after all jobs in the batch have executed successfully.
     *
     * @param  callable  $callback
     * @return $this
     */
    public function then(Closure|callable $callback): static
    {
        $this->registerCallback('then', $callback);

        return $this;
    }

    /**
     * Get the "then" callbacks that have been registered with the pending batch.
     *
     * @return array
     */
    public function thenCallbacks(): array
    {
        return $this->options['then'] ?? [];
    }

    /**
     * Add a callback to be executed after the first failing job in the batch.
     *
     * @param  callable  $callback
     * @return $this
     */
    public function catch(Closure|callable $callback): static
    {
        $this->registerCallback('catch', $callback);

        return $this;
    }

    /**
     * Get the "catch" callbacks that have been registered with the pending batch.
     *
     * @return array
     */
    public function catchCallbacks(): array
    {
        return $this->options['catch'] ?? [];
    }

    /**
     * Add a callback to be executed after the batch has finished executing.
     *
     * @param  callable  $callback
     * @return $this
     */
    public function finally(Closure|callable $callback): static
    {
        $this->registerCallback('finally', $callback);

        return $this;
    }

    /**
     * Get the "finally" callbacks that have been registered with the pending batch.
     *
     * @return array
     */
    public function finallyCallbacks(): array
    {
        return $this->options['finally'] ?? [];
    }

    /**
     * Indicate that the batch should not be canceled when a job within the batch fails.
     *
     * Optionally, add callbacks to be executed upon each job failure.
     *
     * @phpstan-type TParam (Closure(\Voyager\Bus\Batch, \Throwable|null): mixed)|(callable(\Voyager\Bus\Batch, \Throwable|null): mixed)
     *
     * @param  bool|TParam|array<array-key, TParam>  $param
     * @return $this
     */
    public function allowFailures(mixed $param = true): static
    {
        if (! is_bool($param)) {
            $param = Arr::wrap($param);

            foreach ($param as $callback) {
                if (is_callable($callback)) {
                    $this->registerCallback('failure', $callback);
                }
            }
        }

        $this->options['allowFailures'] = ! ($param === false);

        return $this;
    }

    /**
     * Determine if the pending batch allows jobs to fail without cancelling the batch.
     *
     * @return bool
     */
    public function allowsFailures(): bool
    {
        return Arr::get($this->options, 'allowFailures', false) === true;
    }

    /**
     * Get the "failure" callbacks that have been registered with the pending batch.
     *
     * @return array<array-key, Closure|callable>
     */
    public function failureCallbacks(): array
    {
        return $this->options['failure'] ?? [];
    }

    /**
     * Register a callback with proper serialization.
     */
    private function registerCallback(string $type, Closure|callable $callback): void
    {
        $this->options[$type][] = $callback instanceof Closure
            ? new SerializableClosure($callback)
            : $callback;
    }

    /**
     * Set the name for the batch.
     *
     * @param  string  $name
     * @return $this
     */
    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Specify the queue connection that the batched jobs should run on.
     *
     * @param  \UnitEnum|string  $connection
     * @return $this
     */
    public function onConnection(UnitEnum|string $connection): static
    {
        $this->options['connection'] = enum_value($connection);

        return $this;
    }

    /**
     * Get the connection used by the pending batch.
     *
     * @return string|null
     */
    public function connection(): mixed
    {
        return $this->options['connection'] ?? null;
    }

    /**
     * Specify the queue that the batched jobs should run on.
     *
     * @param  \UnitEnum|string|null  $queue
     * @return $this
     */
    public function onQueue(mixed $queue): static
    {
        $this->options['queue'] = enum_value($queue);

        return $this;
    }

    /**
     * Get the queue used by the pending batch.
     *
     * @return string|null
     */
    public function queue(): mixed
    {
        return $this->options['queue'] ?? null;
    }

    /**
     * Add additional data into the batch's options array.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return $this
     */
    public function withOption(string $key, mixed $value): static
    {
        $this->options[$key] = $value;

        return $this;
    }

    /**
     * Dispatch the batch.
     *
     * @return \Voyager\Bus\Batch|null
     *
     * @throws \Throwable
     */
    public function dispatch(): ?Batch
    {
        $repository = $this->container->make(BatchRepository::class);

        try {
            $batch = $this->store($repository);

            $batch = $batch->add($this->jobs);
        } catch (Throwable $e) {
            if (isset($batch)) {
                $repository->delete($batch->id);
            }

            throw $e;
        }

        $this->container->make(EventDispatcher::class)->dispatch(
            new BatchDispatched($batch)
        );

        return $batch;
    }

    /**
     * Dispatch the batch after the response is sent to the browser.
     *
     * @return \Voyager\Bus\Batch|null
     */
    public function dispatchAfterResponse(): ?Batch
    {
        $repository = $this->container->make(BatchRepository::class);

        $batch = $this->store($repository);

        if ($batch) {
            $this->container->terminating(function () use ($batch) {
                $this->dispatchExistingBatch($batch);
            });
        }

        return $batch;
    }

    /**
     * Dispatch an existing batch.
     *
     * @param  \Voyager\Bus\Batch  $batch
     * @return void
     *
     * @throws \Throwable
     */
    protected function dispatchExistingBatch(Batch $batch): void
    {
        try {
            $batch = $batch->add($this->jobs);
        } catch (Throwable $e) {
            $batch->delete();

            throw $e;
        }

        $this->container->make(EventDispatcher::class)->dispatch(
            new BatchDispatched($batch)
        );
    }

    /**
     * Dispatch the batch if the given truth test passes.
     *
     * @param  bool|\Closure  $boolean
     * @return \Voyager\Bus\Batch|null
     */
    public function dispatchIf(mixed $boolean): ?Batch
    {
        return value($boolean) ? $this->dispatch() : null;
    }

    /**
     * Dispatch the batch unless the given truth test passes.
     *
     * @param  bool|\Closure  $boolean
     * @return \Voyager\Bus\Batch|null
     */
    public function dispatchUnless(mixed $boolean): ?Batch
    {
        return ! value($boolean) ? $this->dispatch() : null;
    }

    /**
     * Store the batch using the given repository.
     *
     * @param  \Voyager\Bus\BatchRepository  $repository
     * @return \Voyager\Bus\Batch
     */
    // Untyped: BatchRepository::store() is mocked in upstream tests.
    protected function store(BatchRepository $repository)
    {
        $batch = $repository->store($this);

        (new Collection($this->beforeCallbacks()))->each(function ($handler) use ($batch) {
            try {
                return $handler($batch);
            } catch (Throwable $e) {
                if (function_exists('report')) {
                    report($e);
                }
            }
        });

        return $batch;
    }
}
