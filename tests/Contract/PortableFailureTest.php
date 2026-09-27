<?php

namespace GogoSpace\BulkCache\Tests\Contract;

use GogoSpace\BulkCache\Exceptions\StoreException;
use GogoSpace\BulkCache\Stores\PortableStore;
use GogoSpace\BulkCache\Support\Claim;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class PortableFailureTest extends TestCase
{
    public function test_native_backend_read_failure_has_a_typed_phase_and_previous_cause(): void
    {
        $failure = new \RuntimeException('Backend connection unavailable');
        $repository = $this->createMock(Repository::class);
        $repository->method('get')->willReturn(str_repeat('a', 32));
        $repository->expects(self::once())->method('many')->willThrowException($failure);
        try {
            (new PortableStore($repository, 'application'))->readMany('scope', ['key']);
            self::fail('A backend exception must not become a miss.');
        } catch (\Throwable $caught) {
            self::assertInstanceOf(StoreException::class, $caught);
            self::assertSame('read', $caught->phase);
            self::assertSame('not_applicable', $caught->outcome);
            self::assertSame($failure, $caught->getPrevious());
        }
    }

    public function test_native_publication_failure_can_follow_an_applied_write(): void
    {
        $stored = null;
        $failure = new \RuntimeException('Lost write reply');
        $repository = $this->createMock(Repository::class);
        $repository->method('get')->willReturn(str_repeat('a', 32));
        $repository->expects(self::once())->method('put')->willReturnCallback(function ($key, $payload) use (&$stored, $failure): never {
            $stored = $payload;
            throw $failure;
        });
        try {
            (new PortableStore($repository, 'application'))->publish('scope', 'key', new Claim(str_repeat('a', 32), 'owner'), 'stored', 1000);
            self::fail('A missing acknowledgement must fail.');
        } catch (StoreException $caught) {
            self::assertSame('stored', $stored);
            self::assertSame('publish', $caught->phase);
            self::assertSame('unknown', $caught->outcome);
            self::assertSame($failure, $caught->getPrevious());
        }
    }

    public function test_partial_invalidation_preserves_confirmed_progress_and_unknown_failing_mutation(): void
    {
        $failure = new \RuntimeException('Lost second delete reply');
        $repository = $this->createMock(Repository::class);
        $repository->method('get')->willReturn(str_repeat('a', 32));
        $attempts = 0;
        $repository->expects(self::exactly(2))->method('forget')->willReturnCallback(function () use (&$attempts, $failure): bool {
            if (++$attempts === 2) {
                throw $failure;
            }

            return true;
        });
        try {
            (new PortableStore($repository, 'application'))->invalidateMany('scope', ['first', 'second', 'third']);
            self::fail('A partial invalidation must fail.');
        } catch (StoreException $caught) {
            self::assertSame('invalidate', $caught->phase);
            self::assertSame('partial', $caught->outcome);
            self::assertSame(1, $caught->completedOperations);
            self::assertSame($failure, $caught->getPrevious()->getPrevious());
        }
    }

    public function test_native_claim_and_scope_invalidation_errors_are_typed(): void
    {
        foreach (['claim', 'invalidate'] as $phase) {
            $failure = new \RuntimeException('Metadata backend failed');
            $repository = $this->createMock(Repository::class);
            $repository->expects(self::once())->method($phase === 'claim' ? 'get' : 'forever')->willThrowException($failure);
            $store = new PortableStore($repository, 'application');
            try {
                $phase === 'claim' ? $store->claim('scope', 'key', 1000) : $store->invalidateScope('scope');
                self::fail('Metadata errors must surface.');
            } catch (StoreException $caught) {
                self::assertSame($phase, $caught->phase);
                self::assertSame('unknown', $caught->outcome);
                self::assertSame($failure, $caught->getPrevious());
            }
        }
    }

    public function test_false_write_result_is_an_explicit_storage_error(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->method('get')->willReturn(str_repeat('a', 32));
        $repository->expects(self::once())->method('put')->willReturn(false);
        $store = new PortableStore($repository, 'application');

        $this->expectException(StoreException::class);
        $store->publish('scope', 'key', new Claim(str_repeat('a', 32), 'owner'), 'payload', 1000);
    }

    public function test_false_scope_invalidation_result_is_an_explicit_error(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->expects(self::once())->method('forever')->willReturn(false);
        $store = new PortableStore($repository, 'application');

        $this->expectException(StoreException::class);
        $store->invalidateScope('scope');
    }

    public function test_partial_laravel_many_result_is_not_silently_filled_with_null(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->method('get')->willReturn(str_repeat('a', 32));
        $repository->expects(self::once())->method('many')->willReturn([]);
        $store = new PortableStore($repository, 'application');

        $this->expectException(StoreException::class);
        $store->readMany('scope', ['key']);
    }

    public function test_failed_delete_of_an_existing_item_is_reported(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->method('get')->willReturn(str_repeat('a', 32), 'still present');
        $repository->expects(self::once())->method('forget')->willReturn(false);
        $store = new PortableStore($repository, 'application');

        $this->expectException(StoreException::class);
        $store->invalidateMany('scope', ['key']);
    }

    public function test_deleting_an_already_absent_item_is_idempotent(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->method('get')->willReturn(str_repeat('a', 32), null);
        $repository->expects(self::once())->method('forget')->willReturn(false);
        $store = new PortableStore($repository, 'application');

        $store->invalidateMany('scope', ['key']);
    }

    public function test_laravel_file_store_can_hide_an_item_read_failure_as_a_miss(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $fileStore = new FileStore($filesystem, '/unused-bulk-cache-contract');
        $generationKey = 'bulk:v1:'.hash('sha256', serialize(['application', 'scope', 'generation']));
        $generationPath = $fileStore->path($generationKey);
        $filesystem->expects(self::exactly(2))->method('get')->willReturnCallback(function (string $path) use ($generationPath): string {
            if ($path === $generationPath) {
                return (time() + 3600).serialize(str_repeat('a', 32));
            }

            throw new \RuntimeException('Injected filesystem read failure');
        });
        $store = new PortableStore(new Repository($fileStore), 'application');

        self::assertSame(['item' => null], $store->readMany('scope', ['item']));
    }
}
