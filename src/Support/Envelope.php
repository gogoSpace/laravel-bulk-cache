<?php

namespace GogoSpace\BulkCache\Support;

use GogoSpace\BulkCache\Exceptions\LoaderException;
use GogoSpace\BulkCache\Exceptions\StoreException;
use GogoSpace\BulkCache\Freshness;
use GogoSpace\BulkCache\Missing;

final readonly class Envelope
{
    public function __construct(
        public mixed $value,
        public float $observedAt,
        public int $freshFor,
        public int $staleFor,
        public bool $missing = false,
    ) {}

    public function state(float $now, Freshness $freshness, int $negativeSeconds): string
    {
        $age = max(0, $now - $this->observedAt);
        $freshLimit = min($this->freshFor, $freshness->freshFor, $this->missing ? $negativeSeconds : PHP_INT_MAX);
        $hardLimit = $this->missing ? $freshLimit : min($this->freshFor + $this->staleFor, $freshness->freshFor + $freshness->staleFor);

        return $age < $freshLimit ? 'fresh' : ($age < $hardLimit ? 'stale' : 'expired');
    }

    public function result(): mixed
    {
        return $this->missing ? Missing::Value : $this->value;
    }

    public function encode(int $maximumBytes): string
    {
        self::validateValue($this->value);
        $payload = serialize([1, $this->value, $this->observedAt, $this->freshFor, $this->staleFor, $this->missing]);
        if (strlen($payload) > $maximumBytes) {
            throw new LoaderException('A loader value exceeds max_payload_bytes.');
        }

        return $payload;
    }

    public static function decode(string $payload, int $maximumBytes): self
    {
        if (strlen($payload) > $maximumBytes) {
            throw new StoreException('Stored payload exceeds max_payload_bytes.');
        }
        $data = @unserialize($payload, ['allowed_classes' => false, 'max_depth' => 40]);
        if (! is_array($data) || array_keys($data) !== range(0, 5) || $data[0] !== 1 || ! is_float($data[2]) || ! is_finite($data[2]) || ! is_int($data[3]) || $data[3] < 1 || ! is_int($data[4]) || $data[4] < 0 || $data[3] + $data[4] > 31536000 || ! is_bool($data[5])) {
            throw new StoreException('Stored payload is not a supported cache envelope.');
        }
        try {
            self::validateValue($data[1]);
        } catch (LoaderException $exception) {
            throw new StoreException('Stored payload contains unsupported data.', previous: $exception);
        }

        return new self($data[1], $data[2], $data[3], $data[4], $data[5]);
    }

    private static function validateValue(mixed $value, int $depth = 0): void
    {
        if ($depth > 32 || is_object($value) || is_resource($value) || (is_float($value) && ! is_finite($value))) {
            throw new LoaderException('Values must contain only finite scalars, null and arrays up to 32 levels deep.');
        }
        if (is_array($value)) {
            foreach ($value as $nested) {
                self::validateValue($nested, $depth + 1);
            }
        }
    }
}
