<?php

namespace App\Platform\Tables;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The server-side tables by name, so the shared preference and saved-view
 * endpoints can check a table's columns and filters (ADR-0026). Each table
 * also names the ability needed to see it; preferences for a table the user
 * cannot see are refused.
 */
final class TableRegistry
{
    /** @var array<string, array{class: class-string<TableDefinition<covariant Model>>, ability: string}> */
    private array $tables = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<TableDefinition<covariant Model>>  $class
     */
    public function register(string $name, string $class, string $ability): void
    {
        $this->tables[$name] = ['class' => $class, 'ability' => $ability];
    }

    /**
     * @return array{definition: TableDefinition<covariant Model>, ability: string}|null
     */
    public function find(string $name): ?array
    {
        $entry = $this->tables[$name] ?? null;

        if ($entry === null) {
            return null;
        }

        $definition = $this->container->make($entry['class']);

        if (! $definition instanceof TableDefinition) {
            throw new LogicException("Table [{$name}] is not a table definition.");
        }

        return ['definition' => $definition, 'ability' => $entry['ability']];
    }
}
