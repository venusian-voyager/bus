<?php

namespace Voyager\Bus;

use InvalidArgumentException;
use Voyager\Vessel\ControlPanel;
use Voyager\Contracts\Bus\Dispatcher as DispatcherContract;
use Voyager\Contracts\Bus\QueueingDispatcher as QueueingDispatcherContract;
use Voyager\Contracts\Queue\Factory as QueueFactoryContract;
use Voyager\Contracts\NutsAndBolts\DeferrableProvider;
use Voyager\NutsAndBolts\ServiceProvider;

class BusServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->registerSingleton(Dispatcher::class, function ($app) {
            return new Dispatcher($app, function ($connection = null) {
                return ControlPanel::getInstance()->make(QueueFactoryContract::class)->connection($connection);
            });
        });

        $this->registerBatchServices();

        $this->app->alias(
            Dispatcher::class, DispatcherContract::class
        );

        $this->app->alias(
            Dispatcher::class, QueueingDispatcherContract::class
        );
    }

    /**
     * Register the batch handling services.
     *
     * @return void
     */
    protected function registerBatchServices(): void
    {
        $this->app->registerSingleton(BatchRepository::class, function ($app) {
            $driver = $app['config']->get('queue.batching.driver', 'database');

            if ($driver !== 'database') {
                throw new InvalidArgumentException("Batch driver [{$driver}] is not supported: batches are kept in a database table (queue.batching.driver 'database').");
            }

            return $app->make(DatabaseBatchRepository::class);
        });

        $this->app->registerSingleton(DatabaseBatchRepository::class, function ($app) {
            return new DatabaseBatchRepository(
                $app->make(BatchFactory::class),
                $app->make('db')->connection($app['config']->get('queue.batching.database')),
                $app['config']->get('queue.batching.table', 'job_batches')
            );
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides(): array
    {
        return [
            Dispatcher::class,
            DispatcherContract::class,
            QueueingDispatcherContract::class,
            BatchRepository::class,
            DatabaseBatchRepository::class,
        ];
    }
}
