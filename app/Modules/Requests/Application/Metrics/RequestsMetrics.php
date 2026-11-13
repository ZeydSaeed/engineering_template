<?php

namespace App\Modules\Requests\Application\Metrics;

use App\Modules\Requests\Domain\RequestStatus;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Observability\MetricsSource;
use App\Platform\Observability\MetricsWriter;

/**
 * The Requests module's part of /metrics (ADR-0033): requests per status,
 * and how long the oldest submitted request has waited for a decision.
 * Both read the (status, submitted_at) index; the alert on the waiting
 * time is in deploy/monitoring/alerts.yml.
 */
final class RequestsMetrics implements MetricsSource
{
    public function name(): string
    {
        return 'requests';
    }

    public function collect(MetricsWriter $metrics): void
    {
        $counts = ApprovalRequest::query()->toBase()
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $metrics->type('module_requests_by_status', 'Requests per status.');

        foreach (RequestStatus::cases() as $status) {
            $metrics->sample('module_requests_by_status', ['status' => $status->value], (int) ($counts[$status->value] ?? 0));
        }

        $oldest = ApprovalRequest::query()->where('status', RequestStatus::Submitted)->min('submitted_at');

        // Always exported (0 when nothing waits): an alert on a missing
        // series never fires.
        $metrics->gauge(
            'module_requests_oldest_waiting_age_seconds',
            'How long the oldest submitted request has waited for a decision.',
            $oldest === null ? 0 : max(0, now()->getTimestamp() - (int) strtotime((string) $oldest)),
        );
    }
}
