<?php

/*
| Behind PgBouncer in transaction pooling, PDO must prepare statements
| itself (ADR-0017); everywhere else PostgreSQL prepares them.
*/

it('lets PDO prepare statements only when DB_EMULATE_PREPARES is set', function (?string $value, array $options) {
    $value === null ? putenv('DB_EMULATE_PREPARES') : putenv("DB_EMULATE_PREPARES={$value}");

    try {
        $config = require config_path('database.php');
    } finally {
        putenv('DB_EMULATE_PREPARES');
    }

    expect($config['connections']['pgsql']['options'])->toBe($options);
})->with([
    'unset' => [null, []],
    'false' => ['false', []],
    'true' => ['true', [PDO::ATTR_EMULATE_PREPARES => true]],
]);
