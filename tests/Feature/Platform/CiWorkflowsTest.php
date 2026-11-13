<?php

use Symfony\Component\Yaml\Yaml;

/*
| The CI workflows follow the pipeline rules (ADR-0030, docs/standards/ci.md):
| third-party code pinned to a commit, least privilege, a time limit on every
| job, and no trigger that runs a fork's code with this repository's rights.
*/

/**
 * @return array<string, array<string, mixed>>
 */
function workflows(): array
{
    $workflows = [];

    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $file) {
        $workflows[basename($file)] = (array) Yaml::parseFile($file);
    }

    return $workflows;
}

it('finds the workflows', function () {
    expect(array_keys(workflows()))->toContain('tests.yml', 'security.yml');
});

it('pins every action to a full commit SHA', function () {
    foreach (workflows() as $name => $workflow) {
        foreach ((array) $workflow['jobs'] as $job => $definition) {
            foreach ((array) ($definition['steps'] ?? []) as $step) {
                if (! isset($step['uses']) || str_starts_with($step['uses'], './')) {
                    continue;
                }

                expect($step['uses'])->toMatch('/@[0-9a-f]{40}$/', "{$name} {$job}: {$step['uses']}");
            }
        }
    }
});

it('pins every container image to a digest', function () {
    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $file) {
        preg_match_all('/docker run(?:[^\n]*\\\\\n)*[^\n]*/', (string) file_get_contents($file), $runs);

        foreach ($runs[0] as $run) {
            expect($run)->toMatch('/@sha256:[0-9a-f]{64}/', basename($file));
        }
    }
});

it('reads only by default and limits every job in time', function () {
    foreach (workflows() as $name => $workflow) {
        expect($workflow['permissions'] ?? null)->toBe(['contents' => 'read'], $name);

        foreach ((array) $workflow['jobs'] as $job => $definition) {
            expect(array_key_exists('timeout-minutes', $definition))->toBeTrue("{$name} {$job} has no timeout-minutes");
        }
    }
});

it('never runs code from a fork with this repository\'s rights', function () {
    foreach (workflows() as $name => $workflow) {
        // Symfony Yaml reads the key `on` as the boolean true (YAML 1.1).
        $triggers = (array) ($workflow['on'] ?? $workflow[true] ?? []);

        expect(array_key_exists('pull_request_target', $triggers))->toBeFalse("{$name}: pull_request_target")
            ->and(array_key_exists('workflow_run', $triggers))->toBeFalse("{$name}: workflow_run");
    }
});

it('keeps checkout credentials out of the workspace', function () {
    foreach (workflows() as $name => $workflow) {
        foreach ((array) $workflow['jobs'] as $job => $definition) {
            foreach ((array) ($definition['steps'] ?? []) as $step) {
                if (str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@')) {
                    expect($step['with']['persist-credentials'] ?? null)->toBeFalse("{$name} {$job}");
                }
            }
        }
    }
});

it('gives every ignored secret finding a reason', function () {
    $lines = file(base_path('.gitleaksignore'), FILE_IGNORE_NEW_LINES) ?: [];

    foreach ($lines as $index => $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        expect($line)->toMatch('/^[0-9a-f]{40}:[^:]+:[a-z0-9-]+:\d+$/')
            ->and($lines[$index - 1] ?? '')->toStartWith('#', "no reason above {$line}");
    }
});

it('gives every relaxed ZAP rule a reason', function () {
    $lines = file(base_path('.zap/rules.tsv'), FILE_IGNORE_NEW_LINES) ?: [];
    $rules = 0;

    foreach ($lines as $index => $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $rules++;
        expect($line)->toMatch('/^\d+\t(IGNORE|INFO|WARN|FAIL)\t\(.+\)$/')
            ->and($lines[$index - 1] ?? '')->toStartWith('#', "no reason above {$line}");
    }

    expect($rules)->toBeGreaterThan(0);
});
