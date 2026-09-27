<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Examples\Maintenance;

use GogoSpace\BulkCache\BulkCacheManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** All participants hold the same external lock and reread the active epoch. */
final class DedicatedPortableStore
{
    public function __construct(private readonly Application $application, private readonly string $directory, private readonly string $driver)
    {
        if (! in_array($driver, ['file', 'database'], true)) {
            throw new InvalidArgumentException('This scenario supports dedicated file and database stores.');
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot create the dedicated cache directory.');
        }
        $lock = $this->lock(LOCK_EX);
        try {
            if (! is_file($directory.'/epoch.json')) {
                $this->prepare(1);
                $this->writeState(['active' => 1, 'retired' => null]);
            }
        } finally {
            fclose($lock);
        }
    }

    public function run(callable $operation): mixed
    {
        $lock = $this->lock(LOCK_SH);
        try {
            $epoch = $this->state()['active'];
            $storeName = 'maintenance-'.$this->driver.'-'.$epoch;
            $this->application['config']->set('cache.stores.'.$storeName, $this->storeConfiguration($epoch));
            $this->application['config']->set('bulk-cache.driver', 'portable');
            $this->application['config']->set('bulk-cache.store', $storeName);
            $this->application['config']->set('bulk-cache.prefix', 'maintenance-'.$this->driver.'-'.$epoch);

            // The lock covers claims, source reads, publication AND release.
            return $operation($this->application->make(BulkCacheManager::class));
        } finally {
            fclose($lock);
        }
    }

    public function beginRetirement(): bool
    {
        $lock = $this->lock(LOCK_EX | LOCK_NB);
        if ($lock === false) {
            return false;
        }
        try {
            $state = $this->state();
            if ($state['retired'] !== null) {
                throw new RuntimeException('Finish the current retired store before another cutover.');
            }
            $this->prepare($state['active'] + 1);
            $this->writeState(['active' => $state['active'] + 1, 'retired' => $state['active']]);

            return true;
        } finally {
            fclose($lock);
        }
    }

    public function sampleRetired(): array
    {
        $epoch = $this->state()['retired'];
        if ($epoch === null) {
            return ['entries' => 0, 'bytes' => 0];
        }
        if ($this->driver === 'database') {
            $table = $this->table($epoch);
            if (! $this->application['db']->connection()->getSchemaBuilder()->hasTable($table)) {
                return ['entries' => 0, 'bytes' => 0];
            }
            $result = $this->application['db']->table($table)->selectRaw('COUNT(*) AS entries, COALESCE(SUM(LENGTH(key) + LENGTH(value)), 0) AS bytes')->first();

            return ['entries' => (int) $result->entries, 'bytes' => (int) $result->bytes];
        }
        $count = 0;
        $bytes = 0;
        foreach ($this->files($epoch) as $entry) {
            if ($entry->isFile()) {
                $count++;
                $bytes += $entry->getSize();
            }
        }

        return ['entries' => $count, 'bytes' => $bytes];
    }

    /** The mutation budget includes rows/files/directories and a final table or root removal. */
    public function purgeBatch(int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Use a batch limit from 1 to 100.');
        }
        $lock = $this->lock(LOCK_EX);
        try {
            $state = $this->state();
            $epoch = $state['retired'];
            if ($epoch === null) {
                return ['removed' => 0, 'complete' => true];
            }
            $exists = $this->driver === 'file'
                ? is_dir($this->path($epoch))
                : $this->application['db']->connection()->getSchemaBuilder()->hasTable($this->table($epoch));
            if (! $exists) {
                // The previous process may have removed the store before persisting completion.
                $this->writeState(['active' => $state['active'], 'retired' => null]);

                return ['removed' => 0, 'complete' => true];
            }
            $removed = 0;
            if ($this->driver === 'database') {
                $table = $this->table($epoch);
                $connection = $this->application['db']->connection();
                $keys = $connection->table($table)->orderBy('key')->limit($limit)->pluck('key')->all();
                if ($keys !== []) {
                    $removed = $connection->table($table)->whereIn('key', $keys)->delete();
                }
                $complete = $removed < $limit && ! $connection->table($table)->exists();
                if ($complete) {
                    $connection->getSchemaBuilder()->drop($table);
                    $removed++;
                }
            } else {
                foreach ($this->files($epoch) as $entry) {
                    $deleted = $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
                    if (! $deleted) {
                        throw new RuntimeException('Cannot delete an owned retired cache entry.');
                    }
                    $removed++;
                    if ($removed === $limit) {
                        break;
                    }
                }
                $complete = $removed < $limit;
                if ($complete) {
                    if (! rmdir($this->path($epoch))) {
                        throw new RuntimeException('Cannot remove the empty retired cache root.');
                    }
                    $removed++;
                }
            }
            if ($complete) {
                $this->writeState(['active' => $state['active'], 'retired' => null]);
            }

            return ['removed' => $removed, 'complete' => $complete];
        } finally {
            fclose($lock);
        }
    }

    public function activeEpoch(): int
    {
        return $this->state()['active'];
    }

    private function prepare(int $epoch): void
    {
        if ($this->driver === 'file') {
            if (is_dir($this->path($epoch))) {
                if (iterator_count(new \FilesystemIterator($this->path($epoch), \FilesystemIterator::SKIP_DOTS)) !== 0) {
                    throw new RuntimeException('A prepared next-epoch directory must be empty before reuse.');
                }

                return;
            }
            if (! mkdir($this->path($epoch), 0700, true)) {
                throw new RuntimeException('Cannot prepare the next dedicated file cache.');
            }

            return;
        }
        $connection = $this->application['db']->connection();
        if ($connection->getSchemaBuilder()->hasTable($this->table($epoch))) {
            if (! $connection->getSchemaBuilder()->hasColumns($this->table($epoch), ['key', 'value', 'expiration']) || $connection->table($this->table($epoch))->exists()) {
                throw new RuntimeException('A prepared next-epoch table must have the cache schema and be empty before reuse.');
            }

            return;
        }
        $connection->getSchemaBuilder()->create($this->table($epoch), function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
    }

    private function storeConfiguration(int $epoch): array
    {
        return $this->driver === 'file'
            ? ['driver' => 'file', 'path' => $this->path($epoch)]
            : ['driver' => 'database', 'connection' => 'example', 'table' => $this->table($epoch)];
    }

    private function path(int $epoch): string
    {
        return $this->directory.'/cache-'.$epoch;
    }

    private function table(int $epoch): string
    {
        return 'example_retired_cache_'.$epoch;
    }

    private function files(int $epoch): iterable
    {
        if (! is_dir($this->path($epoch))) {
            return [];
        }

        return new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->path($epoch), \FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    }

    private function state(): array
    {
        return json_decode(file_get_contents($this->directory.'/epoch.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function writeState(array $state): void
    {
        $temporary = $this->directory.'/epoch.next';
        if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR)) === false || ! rename($temporary, $this->directory.'/epoch.json')) {
            throw new RuntimeException('Cannot persist the cache epoch cutover.');
        }
    }

    /** @return resource|false */
    private function lock(int $operation)
    {
        $lock = fopen($this->directory.'/retirement.lock', 'c+');
        if ($lock === false) {
            throw new RuntimeException('Cannot open the external cache retirement lock.');
        }
        if (! flock($lock, $operation)) {
            fclose($lock);
            if ($operation & LOCK_NB) {
                return false;
            }
            throw new RuntimeException('Cannot acquire the external cache retirement lock.');
        }

        return $lock;
    }
}
