<?php

namespace GogoSpace\BulkCache\Support;

final readonly class Claim
{
    public function __construct(public string $generation, public string $owner) {}
}
