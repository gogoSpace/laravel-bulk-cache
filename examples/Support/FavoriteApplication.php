<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Examples;

use GogoSpace\BulkCache\BulkCacheManager;
use GogoSpace\BulkCache\Missing;
use GogoSpace\BulkCache\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use InvalidArgumentException;

final class ExampleProduct extends Model
{
    protected $table = 'example_products';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}

final class ExampleFavorite extends Model
{
    protected $table = 'example_favorites';

    public $timestamps = false;
}

/** Application-owned source access and delivery policy, not part of the cache API. */
final class FavoriteApplication
{
    public const FILTERS = ['all', 'stationery', 'supplies'];

    public array $aggregateLoads = [];

    public int $catalogLoads = 0;

    public function __construct(private readonly Application $application) {}

    public function install(): void
    {
        $schema = $this->application['db']->connection()->getSchemaBuilder();
        $schema->create('example_users', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('revision');
        });
        $schema->create('example_products', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name');
            $table->string('category');
            $table->dateTime('published_at')->nullable();
        });
        $schema->create('example_favorites', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('product_id');
            $table->boolean('selected');
            $table->primary(['user_id', 'product_id']);
        });
        $schema->create('example_invalidations', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('revision');
            $table->dateTime('delivered_at')->nullable();
            $table->index(['user_id', 'delivered_at']);
        });
        $connection = $this->application['db']->connection();
        $connection->table('example_users')->insert([['id' => 1, 'revision' => 1], ['id' => 2, 'revision' => 1], ['id' => 3, 'revision' => 1]]);
        $connection->table('example_products')->insert([
            ['id' => 101, 'name' => 'Notebook', 'category' => 'stationery', 'published_at' => '2026-01-01 00:00:00'],
            ['id' => 102, 'name' => 'Pencil', 'category' => 'supplies', 'published_at' => null],
        ]);
        $connection->table('example_favorites')->insert([
            ['user_id' => 1, 'product_id' => 101, 'selected' => true],
            ['user_id' => 1, 'product_id' => 102, 'selected' => false],
            ['user_id' => 2, 'product_id' => 102, 'selected' => true],
        ]);
    }

    /** Authorize the explicit user before calling this application method. */
    public function selectFavorite(int $userIdentifier, int $productIdentifier): int
    {
        $connection = $this->application['db']->connection();

        return $connection->transaction(function () use ($connection, $userIdentifier, $productIdentifier): int {
            // Query Builder bulk writes do not dispatch Eloquent model events.
            $connection->table('example_favorites')->where('user_id', $userIdentifier)->update(['selected' => false]);
            $connection->table('example_favorites')->updateOrInsert(['user_id' => $userIdentifier, 'product_id' => $productIdentifier], ['selected' => true]);
            $connection->table('example_users')->where('id', $userIdentifier)->increment('revision');
            $revision = $connection->table('example_users')->where('id', $userIdentifier)->value('revision');

            return $connection->table('example_invalidations')->insertGetId(['user_id' => $userIdentifier, 'revision' => $revision]);
        });
    }

    public function deliver(int $intentIdentifier): void
    {
        $connection = $this->application['db']->connection();
        $intent = $connection->table('example_invalidations')->where('id', $intentIdentifier)->first();
        if ($intent === null) {
            throw new InvalidArgumentException('Unknown durable invalidation intent.');
        }
        // Every filter is an item inside ONE exact user scope, so this covers all variants.
        // Repeating a delivered intent may evict newer cache data, but cannot restore old data.
        $this->scope((int) $intent->user_id)->invalidateScope();
        // A crash before this update leaves the intent pending and safe to deliver again.
        $connection->table('example_invalidations')->where('id', $intentIdentifier)->update(['delivered_at' => date('Y-m-d H:i:s')]);
    }

    public function pending(): array
    {
        return $this->application['db']->table('example_invalidations')->whereNull('delivered_at')->orderBy('id')->pluck('id')->map(static fn ($identifier): int => (int) $identifier)->all();
    }

    public function read(int $userIdentifier, string $mode = 'bulk'): array
    {
        if (! in_array($mode, ['bulk', 'legacy'], true)) {
            throw new InvalidArgumentException('Choose bulk or legacy explicitly.');
        }
        $before = $this->state($userIdentifier);
        if ($before['pending']) {
            return $this->source($userIdentifier)['variants'];
        }
        $loader = function (array $filters) use ($userIdentifier): array {
            $this->aggregateLoads[$userIdentifier] = ($this->aggregateLoads[$userIdentifier] ?? 0) + 1;
            $snapshot = $this->source($userIdentifier);
            $values = [];
            foreach ($filters as $filter) {
                $values[$filter] = ['revision' => $snapshot['revision'], 'items' => $snapshot['variants'][$filter]];
            }

            return $values;
        };
        if ($mode === 'bulk') {
            $values = $this->scope($userIdentifier)->rememberMany(self::FILTERS, 300, $loader);
        } else {
            // The source revision makes any late write to an old legacy key unreachable.
            $values = $this->application['cache']->store('file')->remember(
                'example:legacy-favorites:'.$userIdentifier.':'.$before['revision'],
                300,
                fn (): array => $loader(self::FILTERS),
            );
        }
        $after = $this->state($userIdentifier);
        foreach ($values as $value) {
            if ($after['pending'] || $value['revision'] !== $after['revision']) {
                // A NEW authoritative read. Never return a discarded loader or guard result.
                return $this->source($userIdentifier)['variants'];
            }
        }

        return array_map(static fn (array $value): array => $value['items'], $values);
    }

    public function catalog(): array
    {
        return $this->publicScope()->rememberMany([101, 102], 300, function (array $keys): array {
            $this->catalogLoads++;
            $models = ExampleProduct::query()->whereIn('id', $keys)->get()->keyBy('id');
            $values = [];
            foreach ($keys as $key) {
                $model = $models->get($key);
                // Choose public fields explicitly; no Eloquent model, collection or Carbon object in the payload.
                $values[$key] = $model === null ? Missing::Value : [
                    'id' => (int) $model->getKey(),
                    'name' => (string) $model->name,
                    'category' => (string) $model->category,
                    'published_on' => $model->published_at?->format('Y-m-d'),
                ];
            }

            return $values;
        });
    }

    public function view(int $userIdentifier): array
    {
        $favorites = $this->read($userIdentifier)['all'];

        return array_map(static fn (array $product): array => $product + ['favorite' => in_array($product['id'], $favorites, true)], $this->catalog());
    }

    public function scope(int $userIdentifier): Scope
    {
        return $this->application->make(BulkCacheManager::class)->scope('example-user-favorites-v1', ['tenant' => 'demo', 'user' => $userIdentifier]);
    }

    public function publicScope(): Scope
    {
        return $this->application->make(BulkCacheManager::class)->scope('example-public-catalog-v1', ['tenant' => 'demo']);
    }

    private function state(int $userIdentifier): array
    {
        $state = $this->application['db']->table('example_users')->where('id', $userIdentifier)
            ->select('revision')->selectRaw('EXISTS (SELECT 1 FROM example_invalidations WHERE user_id = example_users.id AND delivered_at IS NULL) AS pending')->first();
        if ($state === null) {
            throw new InvalidArgumentException('Unknown authorized user.');
        }

        return ['revision' => (int) $state->revision, 'pending' => (bool) $state->pending];
    }

    private function source(int $userIdentifier): array
    {
        return $this->application['db']->connection()->transaction(function () use ($userIdentifier): array {
            // SQLite supplies one source snapshot for revision and selected rows.
            $state = $this->state($userIdentifier);
            $favorites = $this->application['db']->table('example_favorites')->join('example_products', 'example_products.id', '=', 'product_id')
                ->where('user_id', $userIdentifier)->where('selected', true)->orderBy('product_id')->get(['product_id', 'category']);
            $variants = array_fill_keys(self::FILTERS, []);
            foreach ($favorites as $favorite) {
                $variants['all'][] = (int) $favorite->product_id;
                $variants[$favorite->category][] = (int) $favorite->product_id;
            }

            return ['revision' => $state['revision'], 'variants' => $variants];
        });
    }
}
