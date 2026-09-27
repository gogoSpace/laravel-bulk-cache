<?php

namespace GogoSpace\BulkCache\Exceptions;

use RuntimeException;
use Throwable;

/** Cleanup must not disguise an application/source failure as a cache outage. */
final class CleanupException extends RuntimeException
{
    /** @param non-empty-list<Throwable> $cleanupFailures */
    public function __construct(public readonly ?Throwable $primaryFailure, public readonly array $cleanupFailures)
    {
        parent::__construct('Bulk cache cleanup failed. Inspect primaryFailure and cleanupFailures before choosing recovery.', previous: $primaryFailure ?? $cleanupFailures[0]);
    }
}
