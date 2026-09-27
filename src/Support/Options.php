<?php

namespace GogoSpace\BulkCache\Support;

use GogoSpace\BulkCache\Exceptions\ConfigurationException;

final readonly class Options
{
    public function __construct(public array $values)
    {
        foreach (['batch_size' => 1000, 'max_keys' => 100000, 'lease_milliseconds' => 3600000, 'wait_milliseconds' => 600000, 'operation_milliseconds' => 3600000, 'negative_seconds' => 31536000, 'max_payload_bytes' => 16777216] as $name => $maximum) {
            if (! isset($values[$name]) || ! is_int($values[$name]) || $values[$name] < 1 || $values[$name] > $maximum) {
                throw new ConfigurationException('Invalid bulk-cache option: '.$name.'.');
            }
        }
        if (! in_array($values['driver'] ?? null, ['portable', 'redis'], true)) {
            throw new ConfigurationException('The bulk cache driver must be portable or redis.');
        }
        if (! is_string($values['prefix'] ?? null) || $values['prefix'] === '' || strlen($values['prefix']) > 200) {
            throw new ConfigurationException('A nonempty application-specific bulk-cache prefix is required (at most 200 bytes).');
        }
    }

    public function number(string $name): int
    {
        return $this->values[$name];
    }
}
