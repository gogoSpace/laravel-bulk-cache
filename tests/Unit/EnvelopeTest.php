<?php

namespace GogoSpace\BulkCache\Tests\Unit;

use GogoSpace\BulkCache\Exceptions\LoaderException;
use GogoSpace\BulkCache\Exceptions\StoreException;
use GogoSpace\BulkCache\Freshness;
use GogoSpace\BulkCache\Missing;
use GogoSpace\BulkCache\Support\Envelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnvelopeTest extends TestCase
{
    public function test_fresh_and_stale_boundaries_are_exclusive(): void
    {
        $envelope = new Envelope('value', 100.0, 10, 20);
        $freshness = Freshness::seconds(10, 20);

        self::assertSame('fresh', $envelope->state(109.999, $freshness, 15));
        self::assertSame('stale', $envelope->state(110.0, $freshness, 15));
        self::assertSame('stale', $envelope->state(129.999, $freshness, 15));
        self::assertSame('expired', $envelope->state(130.0, $freshness, 15));
    }

    public function test_reader_policy_cannot_extend_writer_retention_or_its_own_maximum_age(): void
    {
        $envelope = new Envelope('value', 100.0, 3600, 3600);
        self::assertSame('expired', $envelope->state(111.0, Freshness::seconds(10), 15));
        self::assertSame('stale', $envelope->state(110.0, Freshness::seconds(10, 5), 15));
        self::assertSame('expired', $envelope->state(115.0, Freshness::seconds(10, 5), 15));

        $shortWriter = new Envelope('value', 100.0, 10, 5);
        self::assertSame('expired', $shortWriter->state(115.0, Freshness::seconds(3600, 3600), 15));
    }

    public function test_negative_entries_never_have_a_stale_window(): void
    {
        $envelope = new Envelope(null, 100.0, 60, 3600, true);
        self::assertSame(Missing::Value, $envelope->result());
        self::assertSame('fresh', $envelope->state(114.999, Freshness::seconds(60, 3600), 15));
        self::assertSame('expired', $envelope->state(115.0, Freshness::seconds(60, 3600), 15));
        self::assertSame('expired', $envelope->state(110.0, Freshness::seconds(10, 3600), 15));
    }

    public function test_binary_data_and_nested_scalar_values_round_trip(): void
    {
        $value = ["\x00\xff" => [null, false, 0, '', [], 1.25, "\xff"]];
        $envelope = new Envelope($value, 100.0, 60, 30);
        $decoded = Envelope::decode($envelope->encode(4096), 4096);

        self::assertSame($value, $decoded->result());
        self::assertSame('stale', $decoded->state(160.0, Freshness::seconds(60, 30), 15));
    }

    #[DataProvider('unsupportedValues')]
    public function test_unsupported_values_are_rejected(mixed $value): void
    {
        $this->expectException(LoaderException::class);
        (new Envelope($value, 100.0, 60, 0))->encode(4096);
    }

    public static function unsupportedValues(): array
    {
        $cycle = [];
        $cycle['self'] = &$cycle;

        return [[new \stdClass], [new \RuntimeException('Private payload')], [static fn (): int => 1], [INF], [NAN], [$cycle]];
    }

    public function test_oversized_values_are_rejected(): void
    {
        $this->expectException(LoaderException::class);
        (new Envelope(str_repeat('value', 100), 100.0, 60, 0))->encode(100);
    }

    #[DataProvider('corruptEnvelopes')]
    public function test_corrupt_payload_is_an_error_and_never_a_value(string $payload): void
    {
        $this->expectException(StoreException::class);
        Envelope::decode($payload, 4096);
    }

    public static function corruptEnvelopes(): array
    {
        return [
            ['not serialized'],
            [serialize([1, new \stdClass, 100.0, 60, 0, false])],
            [serialize([2, 'unsupported format', 100.0, 60, 0, false])],
            [serialize([1, 'invalid clock', INF, 60, 0, false])],
            [serialize([1, 'invalid duration', 100.0, -1, 0, false])],
            [serialize([1, 'missing fields'])],
        ];
    }
}
