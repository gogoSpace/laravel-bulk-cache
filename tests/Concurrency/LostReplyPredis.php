<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Tests\Concurrency;

use Predis\Client;
use RuntimeException;

final class LostReplyPredis extends Client
{
    public bool $loseNextReply = false;

    public int $mutationCalls = 0;

    public function executeRaw(array $arguments, &$error = null)
    {
        $result = parent::executeRaw($arguments, $error);

        if ($arguments[0] === 'EVAL' && str_contains($arguments[1], "redis.call('SET'")) {
            $this->mutationCalls++;

            if ($this->loseNextReply) {
                $this->loseNextReply = false;

                throw new RuntimeException('The server applied the operation, but its reply was lost.');
            }
        }

        return $result;
    }
}
