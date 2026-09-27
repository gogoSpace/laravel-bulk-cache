<?php

namespace GogoSpace\BulkCache\Support;

use GogoSpace\BulkCache\Contracts\Loader;
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
        foreach (['require_guarded', 'events'] as $name) {
            if (array_key_exists($name, $values) && ! is_bool($values[$name])) {
                throw new ConfigurationException('Invalid bulk-cache option: '.$name.'.');
            }
        }
        if (($values['require_guarded'] ?? false) && $values['driver'] !== 'redis') {
            throw new ConfigurationException('This dataset requires guarded Redis publication; portable storage cannot satisfy require_guarded.');
        }
        $requirements = $values['dimensions'] ?? [];
        if (! is_array($requirements) || count($requirements) > 32) {
            throw new ConfigurationException('Required dimensions must be an array of at most 32 named types.');
        }
        foreach ($requirements as $dimension => $type) {
            if (! is_string($dimension) || $dimension === '' || strlen($dimension) > 200 || ! in_array($type, ['int', 'string', 'bool'], true)) {
                throw new ConfigurationException('Required dimensions must map names to int, string or bool.');
            }
        }
        if (array_key_exists('loader', $values) && (! is_string($values['loader']) || ! is_subclass_of($values['loader'], Loader::class) || ! (new \ReflectionClass($values['loader']))->isInstantiable())) {
            throw new ConfigurationException('The registered loader must be an instantiable class implementing Loader.');
        }
    }

    public function number(string $name): int
    {
        return $this->values[$name];
    }

    public function validateDimensions(array $dimensions): void
    {
        foreach ($this->values['dimensions'] ?? [] as $dimension => $type) {
            $valid = array_key_exists($dimension, $dimensions) && match ($type) {
                'int' => is_int($dimensions[$dimension]),
                'string' => is_string($dimensions[$dimension]),
                'bool' => is_bool($dimensions[$dimension]),
                default => false,
            };
            if (! $valid) {
                throw new ConfigurationException('A required scope dimension is missing or has the wrong type: '.$dimension.'.');
            }
        }
    }
}
