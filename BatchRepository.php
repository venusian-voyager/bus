<?php

namespace Voyager\Bus;

use Closure;

interface BatchRepository
{
    /**
     * Retrieve a list of batches.
     *
     * @param  int  $limit
     * @param  mixed  $before
     * @return \Voyager\Bus\Batch[]
     */
    public function get(int $limit, mixed $before): array;

    /**
     * Retrieve information about an existing batch.
     *
     * @param  string  $batchId
     * @return \Voyager\Bus\Batch|null
     */
    public function find(string $batchId);

    /**
     * Store a new pending batch.
     *
     * @param  \Voyager\Bus\PendingBatch  $batch
     * @return \Voyager\Bus\Batch
     */
    // Untyped return: upstream tests mock this interface with a stdClass double.
    public function store(PendingBatch $batch);

    /**
     * Increment the total number of jobs within the batch.
     *
     * @param  string  $batchId
     * @param  int  $amount
     * @return void
     */
    public function incrementTotalJobs(string $batchId, int $amount): void;

    /**
     * Decrement the total number of pending jobs for the batch.
     *
     * @param  string  $batchId
     * @param  string  $jobId
     * @return \Voyager\Bus\UpdatedBatchJobCounts
     */
    public function decrementPendingJobs(string $batchId, string $jobId): UpdatedBatchJobCounts;

    /**
     * Increment the total number of failed jobs for the batch.
     *
     * @param  string  $batchId
     * @param  string  $jobId
     * @return \Voyager\Bus\UpdatedBatchJobCounts
     */
    public function incrementFailedJobs(string $batchId, string $jobId): UpdatedBatchJobCounts;

    /**
     * Mark the batch that has the given ID as finished.
     *
     * @param  string  $batchId
     * @return void
     */
    public function markAsFinished(string $batchId): void;

    /**
     * Cancel the batch that has the given ID.
     *
     * @param  string  $batchId
     * @return void
     */
    public function cancel(string $batchId): void;

    /**
     * Delete the batch that has the given ID.
     *
     * @param  string  $batchId
     * @return void
     */
    public function delete(string $batchId): void;

    /**
     * Execute the given Closure within a storage specific transaction.
     *
     * @param  \Closure  $callback
     * @return mixed
     */
    public function transaction(Closure $callback): mixed;

    /**
     * Rollback the last database transaction for the connection.
     *
     * @return void
     */
    public function rollBack(): void;
}
