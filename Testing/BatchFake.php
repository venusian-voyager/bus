<?php

namespace Voyager\Bus\Testing;

use Throwable;
use Carbon\CarbonImmutable;
use Voyager\Bus\Batch;
use Voyager\NutsAndBolts\Collection;
use Voyager\Bus\UpdatedBatchJobCounts;

/**
 * A batch with no queue or repository behind it, for a job under test: added jobs are kept
 * for inspection, and recording progress changes nothing.
 */
class BatchFake extends Batch
{
    /** @var list<mixed> */
    public array $added = [];

    public bool $deleted = false;

    public function __construct(
        string $id,
        string $name,
        int $totalJobs,
        int $pendingJobs,
        int $failedJobs,
        array $failedJobIds,
        array $options,
        CarbonImmutable $createdAt,
        ?CarbonImmutable $cancelledAt = null,
        ?CarbonImmutable $finishedAt = null,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->totalJobs = $totalJobs;
        $this->pendingJobs = $pendingJobs;
        $this->failedJobs = $failedJobs;
        $this->failedJobIds = $failedJobIds;
        $this->options = $options;
        $this->createdAt = $createdAt;
        $this->cancelledAt = $cancelledAt;
        $this->finishedAt = $finishedAt;
    }

    public function fresh(): ?self
    {
        return $this;
    }

    public function add(mixed $jobs): ?self
    {
        $jobs = Collection::wrap($jobs);

        foreach ($jobs as $job) {
            $this->added[] = $job;
        }

        $this->totalJobs += $jobs->count();

        return $this;
    }

    public function recordSuccessfulJob(string $jobId): void {}

    public function decrementPendingJobs(string $jobId): UpdatedBatchJobCounts
    {
        return new UpdatedBatchJobCounts;
    }

    public function recordFailedJob(string $jobId, ?Throwable $e): void {}

    public function incrementFailedJobs(string $jobId): UpdatedBatchJobCounts
    {
        return new UpdatedBatchJobCounts;
    }

    public function cancel(): void
    {
        $this->cancelledAt = CarbonImmutable::now();
    }

    public function delete(): void
    {
        $this->deleted = true;
    }

    public function deleted(): bool
    {
        return $this->deleted;
    }
}
