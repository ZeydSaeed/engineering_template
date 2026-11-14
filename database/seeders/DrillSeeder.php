<?php

namespace Database\Seeders;

use App\Access\Role;
use App\Models\User;
use App\Modules\Requests\Application\Actions\CreateRequest;
use App\Modules\Requests\Application\Actions\DecideRequest;
use App\Modules\Requests\Application\Actions\SubmitRequest;
use App\Modules\Requests\Domain\Decision;
use App\Platform\Access\RoleAssignment;
use App\Platform\Files\FileStore;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Data for a restore drill on a disposable machine (operations.yml): people,
 * requests made through the module's own actions (so audit records, outbox
 * messages and notifications exist, as in real use) and attached files.
 * Refuses to run in production or staging.
 *
 *   DRILL_REQUESTS=300 php artisan db:seed --class=DrillSeeder
 *   (platform.performance.drill_requests)
 */
class DrillSeeder extends Seeder
{
    public function run(CreateRequest $create, SubmitRequest $submit, DecideRequest $decide, FileStore $files): void
    {
        if (app()->environment('production', 'staging')) {
            throw new RuntimeException('DrillSeeder never runs in production or staging.');
        }

        $count = (int) config('platform.performance.drill_requests');
        $requesters = User::factory()->withTwoFactor()->count(40)->create()->values()->all();
        $approvers = User::factory()->withTwoFactor()->count(5)->create()->values()->all();

        foreach ($approvers as $approver) {
            RoleAssignment::query()->forceCreate(['user_id' => $approver->id, 'role' => Role::Approver->value]);
        }

        $pdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

        for ($i = 1; $i <= $count; $i++) {
            $requester = $requesters[$i % count($requesters)];
            $request = $create->handle($requester, [
                'title' => "Drill request {$i}",
                'details' => "Written by the restore drill seeder.\nLine two.",
                'amount' => $i % 4 === 0 ? null : (string) ($i * 2.5),
            ]);

            if ($i % 3 === 0) {
                $files->store(UploadedFile::fake()->createWithContent("quote-{$i}.pdf", $pdf), null, $request, $requester);
            }

            if ($i % 4 === 0) {
                continue; // stays a draft
            }

            $request = $submit->handle($requester, $request);

            if ($i % 2 === 0) {
                $approve = $i % 6 !== 0;
                $decide->handle(
                    $approvers[$i % count($approvers)],
                    $request,
                    $approve ? Decision::Approve : Decision::Reject,
                    $approve ? null : 'Not in this quarter.',
                );
            }
        }
    }
}
