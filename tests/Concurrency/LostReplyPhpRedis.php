<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Tests\Concurrency;

use RuntimeException;

final class LostReplyPhpRedis extends \Redis
{
    public bool $loseNextReply = false;

    public int $mutationCalls = 0;

    public ?int $loseMutationAt = null;

    public bool $failConfirmation = false;

    private bool $confirmationPending = false;

    public function rawcommand(string $command, mixed ...$arguments): mixed
    {
        if ($this->confirmationPending && $command === 'EVAL') {
            $this->confirmationPending = false;
            throw new RuntimeException('The read-only confirmation also lost its connection.');
        }
        $result = parent::rawcommand($command, ...$arguments);

        if ($command === 'EVAL' && (str_contains($arguments[0], "redis.call('SET'") || str_contains($arguments[0], "redis.call('DEL'"))) {
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
