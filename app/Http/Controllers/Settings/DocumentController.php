<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DocumentUploadRequest;
use App\Models\User;
use App\Platform\Files\FileStore;
use App\Platform\Files\StoredFile;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A person's own documents (improvements #14, #15). Everything goes through
 * FileStore (ADR-0020): type from the bytes, random stored name, scanned
 * before it can be downloaded. Only the owner sees or downloads a document;
 * anyone else gets 404.
 */
class DocumentController extends Controller
{
    public const LIST_LIMIT = 50;

    public function index(#[CurrentUser] User $user): Response
    {
        return Inertia::render('settings/documents', [
            'documents' => $this->documents($user)
                ->map(fn (StoredFile $file): array => [
                    'uuid' => $file->uuid,
                    'name' => $file->original_name,
                    'size' => $file->size_bytes,
                    'status' => $file->status,
                    'uploaded_at' => $file->created_at->toIso8601String(),
                ])
                ->all(),
            'maxKb' => (int) config('platform.files.max_kb'),
            'extensions' => array_keys((array) config('platform.files.types')),
        ]);
    }

    public function store(DocumentUploadRequest $request, FileStore $files, #[CurrentUser] User $user): RedirectResponse
    {
        $upload = $request->file('file');
        abort_unless($upload !== null && ! is_array($upload), 422);

        // Size, type and readability are checked again from the bytes; a
        // refusal comes back as an error on the file field.
        $files->store($upload, attachTo: $user, actor: $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Uploaded. It can be downloaded once it has been checked.')]);

        return to_route('documents.index');
    }

    public function show(string $uuid, FileStore $files, #[CurrentUser] User $user): StreamedResponse
    {
        $file = $this->ownedBy($user)->where('uuid', $uuid)->first() ?? abort(404);

        return $files->download($file);
    }

    /**
     * @return Collection<int, StoredFile>
     */
    private function documents(User $user): Collection
    {
        return $this->ownedBy($user)->latest('id')->limit(self::LIST_LIMIT)->get();
    }

    /**
     * @return Builder<StoredFile>
     */
    private function ownedBy(User $user): Builder
    {
        return StoredFile::query()
            ->where('attachable_type', $user->getMorphClass())
            ->where('attachable_id', $user->getKey());
    }
}
