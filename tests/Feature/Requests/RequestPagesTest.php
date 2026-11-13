<?php

use App\Access\Role;
use App\Models\User;
use App\Modules\Requests\Domain\RequestStatus;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Access\RoleAssignment;
use App\Platform\Files\StoredFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The Requests module through HTTP (ADR-0033): who sees which requests,
| the form with its checks, the decision, and the files.
*/

uses(RefreshDatabase::class);

function personWith(?Role $role = null): User
{
    $user = User::factory()->withTwoFactor()->create();

    if ($role !== null) {
        RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => $role->value]);
    }

    return $user;
}

describe('the list', function () {
    it('shows people their own requests, and everyone\'s to those who may see all', function () {
        $alice = personWith();
        $mine = ApprovalRequest::factory()->create(['requester_id' => $alice->id, 'title' => 'Alice asks']);
        ApprovalRequest::factory()->submitted()->create(['title' => 'Someone else asks']);

        $this->actingAs($alice)->get('/requests')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('modules/requests/pages/index')
                ->has('requests.rows', 1)
                ->where('requests.rows.0.uuid', $mine->uuid)
                ->where('seesAll', false));

        $this->actingAs(personWith(Role::Approver))->get('/requests?filter[scope]=to_decide')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('requests.rows', 1)
                ->where('requests.rows.0.title', 'Someone else asks')
                ->where('canDecide', true));
    });

    it('never puts the internal id in the page', function () {
        $alice = personWith();
        ApprovalRequest::factory()->create(['requester_id' => $alice->id]);

        $this->actingAs($alice)->get('/requests')
            ->assertInertia(fn (Assert $page) => $page->missing('requests.rows.0.id'));
    });
});

describe('one request', function () {
    it('is seen by its requester and by those who may see all; others get 403', function () {
        $request = ApprovalRequest::factory()->create();

        $this->actingAs($request->requester()->firstOrFail())->get("/requests/{$request->uuid}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('modules/requests/pages/show')
                ->where('request.can.update', true)
                ->where('request.can.decide', false));
        $this->actingAs(personWith(Role::Administrator))->get("/requests/{$request->uuid}")->assertOk();
        $this->actingAs(personWith())->get("/requests/{$request->uuid}")->assertForbidden();
    });

    it('answers 404 to an address that is not a request\'s UUID', function () {
        $this->actingAs(personWith())->get('/requests/123')->assertNotFound();
        $this->actingAs(personWith())->get('/requests/'.fake()->uuid())->assertNotFound();
    });
});

describe('the form', function () {
    it('creates a draft and checks each field', function () {
        $alice = personWith();

        $this->actingAs($alice)->post('/requests', ['title' => 'x', 'details' => '', 'amount' => '12.345'])
            ->assertSessionHasErrors(['title', 'details', 'amount']);

        $this->actingAs($alice)->post('/requests', ['title' => 'Standing desk', 'details' => 'Back pain.', 'amount' => '480'])
            ->assertRedirect();

        $request = ApprovalRequest::query()->sole();
        expect($request->status)->toBe(RequestStatus::Draft)
            ->and($request->amount)->toBe('480.00')
            ->and($request->requester_id)->toBe($alice->id);
    });

    it('checks a field while typing (Precognition)', function () {
        $this->actingAs(personWith())
            ->withHeaders(['Precognition' => 'true', 'Precognition-Validate-Only' => 'title'])
            ->postJson('/requests', ['title' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title')
            ->assertJsonMissingValidationErrors('details');

        expect(ApprovalRequest::query()->count())->toBe(0);
    });

    it('refuses to save over a change made after the form was opened', function () {
        $request = ApprovalRequest::factory()->create(['lock_version' => 3]);
        $owner = $request->requester()->firstOrFail();

        $this->actingAs($owner)->put("/requests/{$request->uuid}", ['title' => 'From an old tab', 'details' => 'x', 'lock_version' => 2])
            ->assertSessionHasErrors('lock_version');

        expect($request->fresh()?->title)->not->toBe('From an old tab');
    });
});

describe('submitting and deciding', function () {
    it('submits a draft, and a second click changes nothing', function () {
        $request = ApprovalRequest::factory()->create();
        $owner = $request->requester()->firstOrFail();

        $this->actingAs($owner)->post("/requests/{$request->uuid}/submit")->assertRedirect("/requests/{$request->uuid}");
        $this->actingAs($owner)->post("/requests/{$request->uuid}/submit")->assertRedirect("/requests/{$request->uuid}");

        expect($request->fresh()?->status)->toBe(RequestStatus::Submitted);
        $this->actingAs(personWith())->post("/requests/{$request->uuid}/submit")->assertForbidden();
    });

    it('lets an approver decide, and tells the next one it is already decided', function () {
        $request = ApprovalRequest::factory()->submitted()->create();

        $this->actingAs(personWith(Role::Approver))->post("/requests/{$request->uuid}/decision", ['decision' => 'reject'])
            ->assertSessionHasErrors('note');
        $this->actingAs(personWith(Role::Approver))->post("/requests/{$request->uuid}/decision", ['decision' => 'approve'])
            ->assertSessionHasNoErrors();
        $this->actingAs(personWith(Role::Approver))->post("/requests/{$request->uuid}/decision", ['decision' => 'reject', 'note' => 'Late'])
            ->assertSessionHasErrors(['decision' => 'This request has already been decided.']);

        expect($request->fresh()?->status)->toBe(RequestStatus::Approved);
    });

    it('refuses a decision from someone who may not decide', function () {
        $request = ApprovalRequest::factory()->submitted()->create();

        $this->actingAs(personWith(Role::Administrator))->post("/requests/{$request->uuid}/decision", ['decision' => 'approve'])
            ->assertForbidden();
    });
});

describe('files', function () {
    beforeEach(function () {
        Storage::fake((string) config('platform.files.disk'));
    });

    it('takes files on a draft from its requester, and serves them to people who may see it once checked', function () {
        $request = ApprovalRequest::factory()->create();
        $owner = $request->requester()->firstOrFail();

        $this->actingAs($owner)
            ->post("/requests/{$request->uuid}/attachments", ['file' => UploadedFile::fake()->createWithContent('quote.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n")])
            ->assertRedirect();

        $file = StoredFile::query()->sole();
        expect($file->attachable_type)->toBe(ApprovalRequest::MORPH)
            ->and($file->attachable_id)->toBe($request->id);

        $file->forceFill(['status' => StoredFile::VERIFIED, 'scanned_at' => now()])->save();

        $this->actingAs(personWith(Role::Approver))->get("/requests/{$request->uuid}/attachments/{$file->uuid}")->assertOk();
        $this->actingAs(personWith())->get("/requests/{$request->uuid}/attachments/{$file->uuid}")->assertForbidden();

        // The same file through another request's address: not found.
        $other = ApprovalRequest::factory()->create(['requester_id' => $owner->id]);
        $this->actingAs($owner)->get("/requests/{$other->uuid}/attachments/{$file->uuid}")->assertNotFound();
    });

    it('takes no files once submitted, so what is decided on cannot change', function () {
        $request = ApprovalRequest::factory()->submitted()->create();

        $this->actingAs($request->requester()->firstOrFail())
            ->post("/requests/{$request->uuid}/attachments", ['file' => UploadedFile::fake()->create('late.pdf', 10)])
            ->assertForbidden();
    });
});

it('puts the requests widget on the dashboard through the module\'s contract', function () {
    $approver = personWith(Role::Approver);
    ApprovalRequest::factory()->create(['requester_id' => $approver->id]);
    ApprovalRequest::factory()->submitted()->count(2)->create();

    $this->actingAs($approver)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->where('widgets.requests.mine.draft', 1)
            ->where('widgets.requests.to_decide', 2));

    $this->actingAs(personWith())->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('widgets.requests.to_decide', null));
});
