<?php

namespace GogoSpace\BulkCache\Tests\Unit;

use GogoSpace\BulkCache\Exceptions\ConfigurationException;
use GogoSpace\BulkCache\Freshness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FreshnessTest extends TestCase
{
    #[DataProvider('invalidDurations')]
    public function test_invalid_or_unbounded_durations_are_rejected(int $freshFor, int $staleFor): void
    {
        $this->expectException(ConfigurationException::class);
        Freshness::seconds($freshFor, $staleFor);
    }

    public static function invalidDurations(): array
    {
        return [[0, 1], [-1, 1], [1, -1], [31536000, 1], [PHP_INT_MAX, 0]];
    }

    public function test_stale_duration_is_additional_to_fresh_duration(): void
    {
        $freshness = Freshness::seconds(freshFor: 60, staleFor: 300);
        self::assertSame(60, $freshness->freshFor);
        self::assertSame(300, $freshness->staleFor);
    }
}
