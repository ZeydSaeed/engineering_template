<?php

namespace App\Platform\Observability;

/**
 * Metrics a module adds to /metrics (ADR-0023, ADR-0033). The platform
 * knows no module: a module's service provider tags its source with
 * MetricsSource::TAG, and PlatformMetrics asks every tagged source.
 *
 * Every metric of a source is named module_<name>_..., so modules cannot
 * collide with each other or with platform_* metrics. A source that throws
 * does not break the scrape: its platform_metrics_source_up is 0.
 * Keep each query cheap (an indexed count): it runs on every scrape.
 */
interface MetricsSource
{
    public const TAG = 'platform.metrics.sources';

    /** Lower-case name of the module, the middle of its metric names. */
    public function name(): string;

    public function collect(MetricsWriter $metrics): void;
}
