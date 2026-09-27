<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Tests\Concurrency;

use RuntimeException;

final class LostReplyPhpRedis extends \Redis
{
    public bool $loseNextReply = false;

    public int $mutationCalls = 0;

    public function rawcommand(string $command, mixed ...$arguments): mixed
    {
        $result = parent::rawcommand($command, ...$arguments);

        if ($command === 'EVAL' && str_contains($arguments[0], "redis.call('SET'")) {
            $this->mutationCalls++;

            if ($this->loseNextReply) {
                $this->loseNextReply = false;

                throw new RuntimeException('The server applied the operation, but its reply was lost.');
            }
        }

        return $result;
    }
}
