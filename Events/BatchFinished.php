<?php

namespace Voyager\Bus\Events;

use Voyager\Bus\Batch;

class BatchFinished
{
    /**
     * Create a new event instance.
     *
     * @param  \Voyager\Bus\Batch  $batch  The batch instance.
     */
    public function __construct(
        public Batch $batch,
    ) {
    }
}
