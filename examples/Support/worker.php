<?php

declare(strict_types=1);

use GogoSpace\BulkCache\Examples\ExampleEnvironment;
use GogoSpace\BulkCache\Examples\ExampleFavorite;
use GogoSpace\BulkCache\Examples\FavoriteApplication;
use GogoSpace\BulkCache\Exceptions\StoreException;

$configuration = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
require $configuration['autoload'];
require __DIR__.'/ExampleEnvironment.php';
require __DIR__.'/FavoriteApplication.php';
$application = ExampleEnvironment::boot($configuration);
$favorites = new FavoriteApplication($application);
$arguments = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
$result = ['process' => getmypid()];

switch ($argv[2]) {
    case 'write':
        $modelEvents = 0;
        ExampleFavorite::saved(function () use (&$modelEvents): void {
            $modelEvents++;
        });
        $intentIdentifier = $favorites->selectFavorite($arguments['user'], $arguments['product']);
        // At this point the DB commit succeeded, independently of delivery success.
        $result += ['saved' => true, 'intent' => $intentIdentifier, 'model_events' => $modelEvents];
        try {
            $favorites->deliver($intentIdentifier);
            $result['delivery'] = 'complete';
        } catch (StoreException $exception) {
            // This catch covers only cache invalidation. Source/validation errors propagate.
            $result['delivery'] = 'pending';
            $result['failure'] = $exception::class;
        }
        break;
    case 'deliver':
        $intentIdentifiers = $arguments['intents'] ?? $favorites->pending();
        foreach ($intentIdentifiers as $intentIdentifier) {
            $favorites->deliver($intentIdentifier);
        }
        $result += ['delivered' => $intentIdentifiers, 'pending' => $favorites->pending()];
        break;
    case 'rename-product':
        $application['db']->table('example_products')->where('id', $arguments['product'])->update(['name' => $arguments['name']]);
        $favorites->publicScope()->invalidateMany([$arguments['product']]);
        $result += ['renamed' => true];
        break;
    default:
        throw new InvalidArgumentException('Unknown example worker action.');
}

echo json_encode($result, JSON_THROW_ON_ERROR);
