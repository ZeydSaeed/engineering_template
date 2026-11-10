<?php

namespace Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Which business modules refer to which, read from the source text of
 * app/Modules (PHP) and resources/js/modules (TypeScript). Used by the
 * architecture tests for rules that need every pair of modules (ADR-0004).
 */
final class ModuleDependencies
{
    public function __construct(
        private readonly string $phpRoot,
        private readonly string $jsRoot,
    ) {}

    public static function forProject(): self
    {
        $base = dirname(__DIR__, 2);

        return new self($base.'/app/Modules', $base.'/resources/js/modules');
    }

    /**
     * @return list<string>
     */
    public function phpModules(): array
    {
        return self::directories($this->phpRoot);
    }

    /**
     * Module => modules it refers to (any namespace below App\Modules\<Other>).
     *
     * @return array<string, list<string>>
     */
    public function phpGraph(): array
    {
        $graph = [];

        foreach ($this->phpModules() as $module) {
            $targets = [];

            foreach (self::files($this->phpRoot.'/'.$module, ['php']) as $file) {
                preg_match_all('/App\\\\Modules\\\\([A-Za-z0-9_]+)\\\\/', (string) file_get_contents($file), $matches);

                foreach ($matches[1] as $target) {
                    if ($target !== $module) {
                        $targets[$target] = true;
                    }
                }
            }

            $graph[$module] = array_keys($targets);
        }

        return $graph;
    }

    /**
     * Cycles in the module graph, each as the list of modules it passes.
     *
     * @param  array<string, list<string>>  $graph
     * @return list<list<string>>
     */
    public static function cycles(array $graph): array
    {
        $cycles = [];
        $state = [];
        $stack = [];

        $visit = function (string $node) use (&$visit, &$cycles, &$state, &$stack, $graph): void {
            $state[$node] = 'open';
            $stack[] = $node;

            foreach ($graph[$node] ?? [] as $next) {
                if (($state[$next] ?? null) === 'open') {
                    $cycles[] = [...array_slice($stack, (int) array_search($next, $stack, true)), $next];
                } elseif (! isset($state[$next])) {
                    $visit($next);
                }
            }

            array_pop($stack);
            $state[$node] = 'done';
        };

        foreach (array_keys($graph) as $node) {
            if (! isset($state[$node])) {
                $visit($node);
            }
        }

        return $cycles;
    }

    /**
     * Imports in one frontend module that reach into another one, as
     * "file: specifier" lines.
     *
     * @return list<string>
     */
    public function frontendViolations(): array
    {
        $violations = [];
        $modules = self::directories($this->jsRoot);
        $root = str_replace('\\', '/', (string) realpath($this->jsRoot));

        foreach ($modules as $module) {
            foreach (self::files($this->jsRoot.'/'.$module, ['ts', 'tsx']) as $file) {
                preg_match_all('/(?:from\s+|import\s*\(\s*|import\s+)[\'"]([^\'"]+)[\'"]/', (string) file_get_contents($file), $matches);

                foreach ($matches[1] as $specifier) {
                    $target = self::targetModule($specifier, dirname($file), $root);

                    if ($target !== null && $target !== $module) {
                        $violations[] = str_replace('\\', '/', $file).': '.$specifier;
                    }
                }
            }
        }

        return $violations;
    }

    private static function targetModule(string $specifier, string $directory, string $root): ?string
    {
        if (preg_match('#^@/modules/([^/]+)#', $specifier, $match) === 1) {
            return $match[1];
        }

        if (! str_starts_with($specifier, '.')) {
            return null;
        }

        $path = self::normalizePath(str_replace('\\', '/', $directory).'/'.$specifier);

        return preg_match('#^'.preg_quote($root, '#').'/([^/]+)#', $path, $match) === 1 ? $match[1] : null;
    }

    private static function normalizePath(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return (str_starts_with($path, '/') ? '/' : '').implode('/', $parts);
    }

    /**
     * @return list<string>
     */
    private static function directories(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $names = array_map('basename', glob($root.'/*', GLOB_ONLYDIR) ?: []);
        sort($names);

        return $names;
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private static function files(string $directory, array $extensions): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
