<?php

namespace App\Modules\Requests\Http;

use App\Models\User;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Files\FileStore;
use App\Platform\Files\StoredFile;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A request's files (ADR-0020, ADR-0033): added by the requester while it
 * is a draft, so what the approver decides on cannot change afterwards;
 * downloaded by anyone who may see the request, once checked. A file of
 * another request, or not yet checked, is a 404.
 */
class AttachmentController
{
    public function store(Request $request, ApprovalRequest $approvalRequest, FileStore $files, #[CurrentUser] User $user): RedirectResponse
    {
        Gate::authorize('update', $approvalRequest);

        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) config('platform.files.max_kb')],
        ]);
        $upload = $request->file('file');
        abort_unless($upload !== null && ! is_array($upload), 422);

        // Type, size and readability are checked again from the bytes.
        $files->store($upload, attachTo: $approvalRequest, actor: $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Uploaded. It can be downloaded once it has been checked.')]);

        return to_route('requests.show', $approvalRequest);
    }

    public function show(ApprovalRequest $approvalRequest, string $file, FileStore $files): StreamedResponse
    {
        Gate::authorize('view', $approvalRequest);

        $stored = StoredFile::query()
            ->where('attachable_type', ApprovalRequest::MORPH)
            ->where('attachable_id', $approvalRequest->id)
            ->where('uuid', $file)
            ->first() ?? abort(404);

        return $files->download($stored);
    }
}
