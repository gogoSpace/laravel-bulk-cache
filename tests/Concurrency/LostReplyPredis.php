<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Tests\Concurrency;

use Predis\Client;
use RuntimeException;

final class LostReplyPredis extends Client
{
    public bool $loseNextReply = false;

    public int $mutationCalls = 0;

    public ?int $loseMutationAt = null;

    public bool $failConfirmation = false;

    private bool $confirmationPending = false;

    public function executeRaw(array $arguments, &$error = null)
    {
        if ($this->confirmationPending && $arguments[0] === 'EVAL') {
            $this->confirmationPending = false;
            throw new RuntimeException('The read-only confirmation also lost its connection.');
        }
        $result = parent::executeRaw($arguments, $error);

        if ($arguments[0] === 'EVAL' && (str_contains($arguments[1], "redis.call('SET'") || str_contains($arguments[1], "redis.call('DEL'"))) {
            $this->mutationCalls++;

            if ($this->loseNextReply || $this->mutationCalls === $this->loseMutationAt) {
                $this->loseNextReply = false;
                $this->confirmationPending = $this->failConfirmation;

                throw new RuntimeException('The server applied the operation, but its reply was lost.');
            }
        }

        return $result;
    }
}
