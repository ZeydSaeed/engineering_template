<?php

use Tests\Support\ModuleDependencies;

/*
| A module may use another module only through its Contracts namespace
| (interfaces, DTOs, events), and modules never depend on each other in a
| cycle (ADR-0004). One rule is generated per ordered pair of modules.
*/

$dependencies = ModuleDependencies::forProject();
$modules = $dependencies->phpModules();

arch('module directories are StudlyCase names')
    ->expect(array_filter($modules, fn (string $module): bool => preg_match('/^[A-Z][A-Za-z0-9]*$/', $module) !== 1))
    ->toBeEmpty();

foreach ($modules as $from) {
    foreach ($modules as $to) {
        if ($from !== $to) {
            arch("module {$from} uses only the contracts of module {$to}")
                ->expect("App\\Modules\\{$from}")
                ->not->toUse("App\\Modules\\{$to}")
                ->ignoring("App\\Modules\\{$to}\\Contracts");
        }
    }
}

test('modules have no dependency cycles', function () use ($dependencies) {
    expect(ModuleDependencies::cycles($dependencies->phpGraph()))->toBe([]);
});

test('frontend modules do not import from each other', function () use ($dependencies) {
    expect($dependencies->frontendViolations())->toBe([]);
});

test('the cycle detector finds cycles', function () {
    expect(ModuleDependencies::cycles(['A' => ['B'], 'B' => ['C'], 'C' => ['A'], 'D' => ['A']]))
        ->toBe([['A', 'B', 'C', 'A']])
        ->and(ModuleDependencies::cycles(['A' => ['B'], 'B' => [], 'C' => ['A']]))
        ->toBe([]);
});
