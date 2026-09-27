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
