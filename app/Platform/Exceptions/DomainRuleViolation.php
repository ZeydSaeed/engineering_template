<?php

namespace App\Platform\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An expected business rule was broken (a period is closed, a record changed
 * meanwhile). It is not a fault, so it is never reported; the user sees the
 * message on the field it concerns (ADR-0008).
 *
 * Modules create their own named constructors, for example
 * `ApprovalRules::alreadyDecided()`, rather than building these inline.
 */
class DomainRuleViolation extends RuntimeException implements ShouldntReport
{
    /**
     * @param  array<string, mixed>  $context  extra, non-sensitive data for API clients
     */
    public function __construct(
        string $message,
        public readonly string $field = 'general',
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => $this->getMessage(),
                'errors' => [$this->field => [$this->getMessage()]],
                'context' => (object) $this->context,
            ], $this->status);
        }

        return back()->withErrors([$this->field => $this->getMessage()]);
    }
}
