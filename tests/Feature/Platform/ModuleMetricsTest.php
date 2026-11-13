<?php

use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Observability\MetricsSource;
use App\Platform\Observability\MetricsWriter;
use App\Platform\Observability\PlatformMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Modules add metrics through MetricsSource (ADR-0023, ADR-0033): under
| their own prefix, and without breaking the scrape when they fail.
*/

uses(RefreshDatabase::class);

it('exports the Requests module\'s metrics through its source', function () {
    ApprovalRequest::factory()->count(2)->create();
    ApprovalRequest::factory()->submitted()->create(['submitted_at' => now()->subHours(5)]);
    ApprovalRequest::factory()->submitted()->create(['submitted_at' => now()->subHour()]);

    $text = app(PlatformMetrics::class)->render();

    expect($text)->toContain('platform_metrics_source_up{source="requests"} 1')
        ->and($text)->toContain('module_requests_by_status{status="draft"} 2')
        ->and($text)->toContain('module_requests_by_status{status="submitted"} 2')
        ->and($text)->toContain('module_requests_by_status{status="approved"} 0');

    preg_match('/^module_requests_oldest_waiting_age_seconds (\d+)$/m', $text, $age);
    expect((int) ($age[1] ?? -1))->toBeGreaterThanOrEqual(5 * 3600)->toBeLessThan(5 * 3600 + 60);
});

it('reports zero waiting time when nothing waits, so the series never disappears', function () {
    expect(app(PlatformMetrics::class)->render())->toContain("module_requests_oldest_waiting_age_seconds 0\n");
});

it('keeps the scrape working when a source fails, and marks that source down', function () {
    app()->bind('test.failing-source', fn () => new class implements MetricsSource
    {
        public function name(): string
        {
            return 'broken';
        }

        public function collect(MetricsWriter $metrics): void
        {
            $metrics->gauge('module_broken_half_written', 'Written before the failure.', 1);

            throw new RuntimeException('database refused');
        }
    });
    app()->tag(['test.failing-source'], MetricsSource::TAG);

    $text = app(PlatformMetrics::class)->render();

    expect($text)->toContain('platform_metrics_source_up{source="broken"} 0')
        ->and($text)->toContain('platform_metrics_source_up{source="requests"} 1')
        ->and($text)->toContain('platform_up 1')
        // Nothing of a failed source is half exported.
        ->and($text)->not->toContain('module_broken_half_written');
});

it('accepts only names under the module\'s own prefix', function (string $name) {
    (new MetricsWriter('module_requests_'))->gauge($name, 'x', 1);
})->with([
    'a platform name' => ['platform_up'],
    'another module' => ['module_files_count'],
    'not snake case' => ['module_requests_Waiting'],
])->throws(InvalidArgumentException::class);
