<?php

namespace GogoSpace\BulkCache;

use GogoSpace\BulkCache\Exceptions\ConfigurationException;

final readonly class Freshness
{
    private function __construct(public int $freshFor, public int $staleFor)
    {
        if ($freshFor < 1 || $staleFor < 0 || $freshFor + $staleFor > 31536000) {
            throw new ConfigurationException('Freshness requires freshFor >= 1, staleFor >= 0 and a total of at most one year.');
        }
    }

    public static function seconds(int $freshFor, int $staleFor = 0): self
    {
        return new self($freshFor, $staleFor);
    }
}
