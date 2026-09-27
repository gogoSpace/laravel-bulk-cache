<?php

namespace GogoSpace\BulkCache\Exceptions;

use RuntimeException;
use Throwable;

class StoreException extends RuntimeException
{
    /**
     * outcome: not_applicable (read), not_applied, unknown, or partial.
     * partial means some operations were confirmed, not necessarily a prefix.
     * The count identifies no keys; the failing mutation may also have applied.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly string $phase = 'unknown',
        public readonly string $outcome = 'unknown',
        public readonly int $completedOperations = 0,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function afterCompleted(int $completedOperations): self
    {
        if ($completedOperations === 0) {
            return $this;
        }

        return new self($this->getMessage(), $this->getCode(), $this, $this->phase, 'partial', $completedOperations + $this->completedOperations);
    }
}
