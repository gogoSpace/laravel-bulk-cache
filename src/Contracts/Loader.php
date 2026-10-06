<?php

namespace GogoSpace\BulkCache\Contracts;

interface Loader
{
    /**
     * @param  list<string>  $keys
     * @param  array<string, bool|int|string|null>  $dimensions
     * @return array<int|string, mixed>
     */
    public function load(array $keys, array $dimensions): array;
}
