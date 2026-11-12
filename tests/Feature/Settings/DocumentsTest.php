<?php

use App\Models\User;
use App\Platform\Files\StoredFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Files;

/*
| A person's own documents (#14, #15; ADR-0027): uploads go through
| FileStore, a refusal is an error on the file field, and only the owner
| sees or downloads a document.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('files');
});

it('stores an upload for its owner, scanned, and lists it', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->post('/settings/documents', ['file' => Files::upload('Report.pdf', Files::pdf())])
        ->assertRedirect('/settings/documents');

    $file = StoredFile::query()->sole();
    expect($file->attachable_id)->toBe($user->id)
        ->and($file->uploaded_by)->toBe($user->id)
        ->and($file->status)->toBe(StoredFile::VERIFIED);

    $this->actingAs($user)
        ->get('/settings/documents')
        ->assertInertia(fn (Assert $page) => $page
            ->has('documents', 1)
            ->where('documents.0.name', 'Report.pdf')
            ->where('documents.0.status', 'verified'));
});

it('refuses a file whose bytes are not what its name says, on the file field', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->from('/settings/documents')
        ->post('/settings/documents', ['file' => Files::upload('invoice.pdf', "MZ\x90\x00 a program")])
        ->assertSessionHasErrors(['file' => 'This type of file is not accepted.']);

    expect(StoredFile::query()->count())->toBe(0);
});

it('refuses a request without a file or over the size limit', function () {
    $user = User::factory()->withTwoFactor()->create();
    config(['platform.files.max_kb' => 1]);

    $this->actingAs($user)->post('/settings/documents', [])->assertSessionHasErrors('file');
    $this->actingAs($user)
        ->post('/settings/documents', ['file' => Files::upload('big.pdf', Files::pdf().str_repeat(' ', 2048))])
        ->assertSessionHasErrors('file');
});

it('shows and serves documents to their owner only', function () {
    $owner = User::factory()->withTwoFactor()->create();
    $other = User::factory()->withTwoFactor()->create();

    $this->actingAs($owner)->post('/settings/documents', ['file' => Files::upload('Mine.pdf', Files::pdf())]);
    $file = StoredFile::query()->sole();

    $this->actingAs($owner)->get("/settings/documents/{$file->uuid}")->assertOk()->assertDownload('Mine.pdf');

    $this->actingAs($other)->get("/settings/documents/{$file->uuid}")->assertNotFound();
    $this->actingAs($other)
        ->get('/settings/documents')
        ->assertInertia(fn (Assert $page) => $page->has('documents', 0));
});

it('limits uploads to 20 a minute per person', function () {
    $user = User::factory()->withTwoFactor()->create();

    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($user)->post('/settings/documents', []);
    }

    $this->actingAs($user)->post('/settings/documents', [])->assertStatus(429);
});
