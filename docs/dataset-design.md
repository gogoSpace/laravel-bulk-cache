# Design datasets around their dependencies

[Run the dataset scenario](../examples/dataset-design.php) for a complete user aggregate, bounded filter variants, a public catalog with personal flags, Eloquent conversion and a legacy/bulk switch. It shares the small [application example](../examples/Support/FavoriteApplication.php) and source consistency policy described in [recovery](recovery.md).

```sh
php -r 'require "vendor/autoload.php"; echo json_encode(require "examples/dataset-design.php", JSON_PRETTY_PRINT), PHP_EOL;'
```

The scenario owns a disposable SQLite database and Redis process. Requirements and client selection are the same as the recovery example. The assertion report must return `status: passed`; failures throw instead of silently skipping a backend.

## A whole user aggregate and its filters

The favorite dataset uses dimensions `tenant=demo` and an explicit integer `user`. Its item keys are `all`, `stationery` and `supplies`. `all` is the user's entire favorite identifier list, loaded in one source snapshot. An empty aggregate is the valid value `[]`; it is not `Missing::Value`. The scenario reads an empty user twice and checks that the aggregate loader ran once.

All three variants depend on the same user's favorite writes. Keeping the filters as **item keys inside the same exact scope** makes one `invalidateScope()` cover them all, including a filter which becomes empty after the write. A batch loader derives the requested variants from one source aggregate. The example has a fixed, bounded filter list; reject unrecognized filters rather than accidentally sharing their identity.

`invalidateScope()` still affects exactly one dataset and one complete dimension combination. Omitting a dimension never means a wildcard. If you put the filter in dimensions instead, keep a complete bounded list of affected scopes and invalidate each one, or design an application source revision which all those scopes validate. Do not claim that invalidating `['user' => 1]` also invalidates `['user' => 1, 'filter' => 'stationery']`.

The external worker changes favorites with Query Builder bulk statements. These bypass Eloquent model events, so the writer explicitly increments the revision and inserts an intent in the same transaction. The scenario verifies zero model events, changed values in every affected variant, and that another user's aggregate remains both correct and warm. Inventory every writer before adopting this contract.

## Shared catalog plus personal overlay

Public product display fields use a separate dataset with only the public tenant dimension. Favorites remain in the user scope. The application combines the shared product arrays with boolean favorite flags after authorization. Two users reuse one public catalog load and receive different flags.

A product name change invalidates that product's public item. It does not change favorite identifiers or category membership, so the personal aggregate stays warm. A **category** change would affect filtered favorites too: the writing transaction must identify the affected users and record their invalidation intents. Dependencies follow the fields actually used, not merely the table name. Private fields never belong in the shared public payload, and a personalized HTTP response is not publicly cacheable merely because its catalog portion is shared.

`catalog()` demonstrates a real Eloquent batch query and an explicit payload boundary: it selects public scalar fields into arrays, converts the model key to an integer, and formats a cast date as `YYYY-MM-DD` or `null`. It returns neither models, collections nor Carbon objects. A missing product uses `Missing::Value`; a valid empty favorite list remains `[]`. Do not blindly serialize a model with loaded relationships or hidden private fields.

## Switching between legacy and bulk

`read($userIdentifier, 'legacy')` and `read($userIdentifier, 'bulk')` share the same source revision check and dirty-read policy. The legacy file-cache key includes the source revision. A late legacy producer can write an obsolete key, but later reads no longer address it. The bulk scope uses a fixed identity; each variant's payload carries the revision and is validated against the primary database.

The scenario warms both implementations before an external write, switches back to legacy afterwards, then reads bulk again. It proves that the old legacy entry still physically contains the earlier favorite while both active implementations return the new one. A feature flag alone is insufficient: deploy the revision-aware legacy reader and transactional writer before warming the new implementation, and retain that reader during rollback. Rolling back to the original unmodified legacy code would remove this protection.

Old revision keys expire through their existing TTL; they are not physically erased by the switch. Apply the backend's retention procedure to unreachable files and keys. The example preserves `invalidateScope()` semantics and does not add a general cross-scope invalidation API or dependency graph.
