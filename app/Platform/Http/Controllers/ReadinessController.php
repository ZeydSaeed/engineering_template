<?php

namespace App\Platform\Http\Controllers;

use App\Platform\Observability\HealthChecks;
use Illuminate\Http\JsonResponse;

/**
 * Readiness probe (ADR-0023): 200 when the database, cache, files disk and
 * queue all work, 503 otherwise. Pass or fail only; never which part. It
 * also names the release being served (RELEASE.json, ADR-0031), so a
 * deployment can check the new code is the code answering (ADR-0032).
 * Nginx serves it to the machine itself only.
 */
class ReadinessController
{
    public function __invoke(HealthChecks $checks): JsonResponse
    {
        $healthy = $checks->healthy();

        return response()->json(['status' => $healthy ? 'ok' : 'fail', 'release' => $this->release()], $healthy ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * The commit of the release, or null outside one (a checkout).
     */
    private function release(): ?string
    {
        $file = base_path('RELEASE.json');

        if (! is_file($file)) {
            return null;
        }

        $commit = json_decode((string) file_get_contents($file), true)['commit'] ?? null;

        return is_string($commit) && preg_match('/^[0-9a-f]{40}$/', $commit) === 1 ? $commit : null;
    }
}
