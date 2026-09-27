<?php

declare(strict_types=1);

use GogoSpace\BulkCache\BulkCacheServiceProvider;
use GogoSpace\BulkCache\Tools\Verification;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Support\Facades\Facade;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/Support/Verification.php';

$root = dirname(__DIR__);
$application = new Application($root);
$application->instance('config', new Repository([
    'app' => ['name' => 'Documentation', 'env' => 'testing', 'key' => 'documentation-only'],
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array', 'serialize' => true]]],
    'queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]],
    'bulk-cache' => ['prefix' => 'documentation-'.bin2hex(random_bytes(8))],
]));
Facade::setFacadeApplication($application);
$application->register(EventServiceProvider::class);
$application->register(CacheServiceProvider::class);
$application->register(QueueServiceProvider::class);
$application->register(BulkCacheServiceProvider::class);
$application->boot();

$documents = array_merge([$root.'/README.md'], glob($root.'/docs/*.md'));
$blocksByFile = [];
$linkCount = 0;
foreach ($documents as $document) {
    $markdown = (string) file_get_contents($document);
    preg_match_all('/\[[^\]]*\]\(([^)]+)\)/', $markdown, $links);
    foreach ($links[1] as $link) {
        if (str_starts_with($link, '#') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $link)) {
            continue;
        }
        $relative = explode('#', $link, 2)[0];
        Verification::require(is_file(dirname($document).'/'.$relative), 'Broken local documentation link: '.$document.' -> '.$link);
        $linkCount++;
    }
    preg_match_all('/```php\n(.*?)\n```/s', $markdown, $blocks);
    $blocks[1] = array_map(static fn (string $source): string => preg_replace('/^<\?php\s*/', '', $source), $blocks[1]);
    $blocksByFile[basename($document)] = $blocks[1];
    foreach ($blocks[1] as $block) {
        $source = str_starts_with(ltrim($block), "'datasets' =>") ? 'return ['.$block.'];' : $block;
        token_get_all('<?php '.$source, TOKEN_PARSE);
    }
}

$execute = static function (string $source): mixed {
    return eval($source);
};
$quickstart = $execute($blocksByFile['README.md'][0]);
Verification::require($quickstart === ['same_values' => true, 'missing' => true, 'updated_name' => 'Updated notebook', 'loader_calls' => 2], 'README quickstart returned an unexpected result.');
$expectedSource = trim(str_replace('<?php', '', (string) file_get_contents($root.'/examples/quickstart.php')));
Verification::require(trim($blocksByFile['README.md'][0]) === $expectedSource, 'README and bundled quickstart differ.');

foreach (['usage.md', 'scopes.md', 'invalidation.md'] as $document) {
    foreach ($blocksByFile[$document] as $source) {
        $execute($source);
    }
}
// Run the README freshness continuation with the variables provided by its quickstart.
$execute(str_replace('return [', '$quickstartResult = [', $blocksByFile['README.md'][0])."\n".$blocksByFile['README.md'][1]);

$lifecycle = $blocksByFile['lifecycle.md'];
$execute($lifecycle[0]);
$registration = $execute('return ['.$lifecycle[1].'];');
$application['config']->set('bulk-cache.datasets', $registration['datasets']);
$execute($lifecycle[2]);

$results = [];
foreach (glob($root.'/examples/*.php') as $example) {
    $result = (static function (string $path): mixed {
        return require $path;
    })($example);
    $results[basename($example)] = $result;
}
Verification::require($results['quickstart.php'] === $quickstart, 'Bundled quickstart failed.');
$catalog = $results['catalog.php'];
Verification::require($catalog['public_loader_calls'] === 1, 'Catalog must share its public source read.');
Verification::require($catalog['views']['alice'][101]['favorite'] === true && $catalog['views']['bob'][101]['favorite'] === false && $catalog['views']['guest'][101]['favorite'] === false, 'Catalog user isolation failed.');
Verification::require($catalog['views']['bob'][102]['favorite'] === true && $catalog['views']['guest'][102]['favorite'] === false, 'Catalog overlay mapping failed.');
$model = $results['read-model.php'];
Verification::require($model['same_values'] === true && $model['source_calls'] === 1 && $model['values'][10]['completed'] === 30 && $model['values'][20]['completed'] === 60, 'Read-model example failed.');

Verification::writeJson($root.'/research/execution/logs/docs.json', ['php' => PHP_VERSION, 'documents' => count($documents), 'relative_links' => $linkCount, 'executed_examples' => array_keys($results), 'readme_quickstart' => $quickstart, 'status' => 'passed']);
echo 'Documentation passed: '.count($documents).' documents, '.$linkCount." relative links, README snippets and all bundled examples executed.\n";
