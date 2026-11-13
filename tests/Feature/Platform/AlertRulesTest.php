<?php

use App\Platform\Observability\PlatformMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;

/*
| Every alert must be actionable (ADR-0023): a severity, a summary and a
| runbook section with what to check, do and verify; and every application
| metric an alert uses must exist.
*/

uses(RefreshDatabase::class);

/**
 * @return list<array<string, mixed>>
 */
function alertRules(): array
{
    $config = Yaml::parseFile(base_path('deploy/monitoring/alerts.yml'));
    $rules = [];

    foreach ($config['groups'] as $group) {
        foreach ($group['rules'] as $rule) {
            $rules[] = $rule;
        }
    }

    return $rules;
}

/**
 * Heading => text of each "## " section of a runbook.
 *
 * @return array<string, string>
 */
function runbookSections(string $path): array
{
    $sections = [];

    foreach (preg_split('/^## /m', (string) @file_get_contents($path)) ?: [] as $part) {
        [$heading, $body] = array_pad(explode("\n", $part, 2), 2, '');
        $sections[trim($heading)] = $body;
    }

    return $sections;
}

it('gives every alert a severity, a summary and an existing runbook section', function () {
    $rules = alertRules();

    expect($rules)->not->toBeEmpty();

    foreach ($rules as $rule) {
        $name = $rule['alert'];

        expect($rule['labels']['severity'] ?? null)->toBeIn(['critical', 'warning'], $name)
            ->and($rule['annotations']['summary'] ?? '')->not->toBe('', $name)
            ->and($rule['annotations']['runbook'] ?? '')->toBe(sprintf('%s#%s', explode('#', (string) $rule['annotations']['runbook'])[0], strtolower($name)), $name);

        [$file] = explode('#', (string) $rule['annotations']['runbook']);
        $sections = runbookSections(base_path($file));

        // toHaveKey's second argument is the expected value, not a message.
        expect(array_key_exists($name, $sections))->toBeTrue("{$name}: no section in {$file}")
            ->and($sections[$name])->toContain('**Meaning:**', '**Check:**', '**Act:**', '**Verify:**');
    }
});

it('alerts only on application metrics that exist', function () {
    $exported = [];
    // Platform metrics and every module's (MetricsSource).
    preg_match_all('/^((?:platform|module)_[a-z_]+)[ {]/m', app(PlatformMetrics::class)->render(), $matches);
    $exported = array_unique($matches[1]);

    foreach (alertRules() as $rule) {
        preg_match_all('/(?:platform|module)_[a-z_]+/', (string) $rule['expr'], $used);

        foreach ($used[0] as $metric) {
            // toContain takes needles only; the message goes with toBeTrue.
            expect(in_array($metric, $exported, true))->toBeTrue("{$rule['alert']} uses {$metric}, which /metrics does not export");
        }
    }
});
