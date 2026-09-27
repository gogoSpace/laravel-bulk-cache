<?php

namespace GogoSpace\BulkCache\Support;

class Clock
{
    public function now(): float
    {
        return microtime(true);
    }

    public function monotonic(): float
    {
        return hrtime(true) / 1000000;
    }

    public function sleep(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}
