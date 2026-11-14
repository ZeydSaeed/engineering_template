<?php

use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Audit\AuditEvent;
use App\Platform\Files\StoredFile;
use App\Platform\Outbox\OutboxMessage;
use Database\Seeders\DrillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/*
| The restore drill's data (operations.yml) is made through the module's
| own actions, so the drill restores what real use leaves behind.
*/

uses(RefreshDatabase::class);

it('makes requests in every state, with files, audit records and outbox messages', function () {
    Storage::fake((string) config('platform.files.disk'));
    config(['platform.performance.drill_requests' => 12]);

    $this->seed(DrillSeeder::class);

    $statuses = ApprovalRequest::query()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();

    expect(ApprovalRequest::query()->count())->toBe(12)
        ->and(array_keys($statuses))->toEqualCanonicalizing(['draft', 'submitted', 'approved', 'rejected'])
        ->and(StoredFile::query()->count())->toBe(4)
        ->and(AuditEvent::query()->where('action', 'like', 'request.%')->count())->toBeGreaterThan(12)
        ->and(OutboxMessage::query()->count())->toBeGreaterThan(0);
});

it('never runs in production or staging', function (string $environment) {
    app()->detectEnvironment(fn (): string => $environment);

    // Called directly: db:seed itself would first ask for confirmation.
    app()->call([new DrillSeeder, 'run']);
})->with(['production', 'staging'])->throws(RuntimeException::class, 'never runs in production or staging');
