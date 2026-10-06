<?php

namespace GogoSpace\BulkCache\Support;

use GogoSpace\BulkCache\Exceptions\InvalidKeyException;

final class Identity
{
    /**
     * @param  iterable<mixed>  $keys
     * @return list<string>
     */
    public static function keys(iterable $keys, int $limit): array
    {
        $unique = [];
        $count = 0;
        foreach ($keys as $key) {
            if (++$count > $limit) {
                throw new InvalidKeyException('The input exceeds max_keys, including duplicates.');
            }
            if ((! is_string($key) && ! is_int($key)) || strlen((string) $key) > 1024) {
                throw new InvalidKeyException('Keys must be integers or strings of at most 1024 bytes.');
            }
            $unique[(string) $key] = (string) $key;
        }

        return array_values($unique);
    }

    /** @param array<string, bool|int|string|null> $dimensions */
    public static function scope(string $name, array $dimensions): string
    {
        if ($name === '' || strlen($name) > 200 || count($dimensions) > 32) {
            throw new InvalidKeyException('A dataset name (1–200 bytes) and at most 32 dimensions are required.');
        }
        foreach ($dimensions as $dimension => $value) {
            if (! is_string($dimension) || (! is_null($value) && ! is_bool($value) && ! is_int($value) && ! is_string($value))) {
                throw new InvalidKeyException('Dimensions must be named scalar strings, integers, booleans or null.');
            }
            if (strlen($dimension) > 200 || (is_string($value) && strlen($value) > 1024)) {
                throw new InvalidKeyException('A dimension exceeds its length limit.');
            }
        }
        ksort($dimensions, SORT_STRING);

        return hash('sha256', serialize([$name, $dimensions]));
    }
}
