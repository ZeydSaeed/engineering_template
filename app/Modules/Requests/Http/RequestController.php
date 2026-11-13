<?php

namespace App\Modules\Requests\Http;

use App\Access\Permission;
use App\Models\User;
use App\Modules\Requests\Application\Actions\CreateRequest;
use App\Modules\Requests\Application\Actions\DecideRequest;
use App\Modules\Requests\Application\Actions\SubmitRequest;
use App\Modules\Requests\Application\Actions\UpdateDraft;
use App\Modules\Requests\Domain\RequestStatus;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Tables\SavedView;
use App\Platform\Tables\TableColumns;
use App\Platform\Tables\TableState;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Requests in the browser (ADR-0033): each method authorises with
 * RequestPolicy, validates with a form request and calls one action.
 */
class RequestController
{
    public function index(Request $request, RequestsTable $table, #[CurrentUser] User $viewer): Response
    {
        $state = TableState::from($table, $request->query());
        $query = ApprovalRequest::query()->with('requester');

        if (! $viewer->hasPermission(Permission::RequestsViewAll)) {
            $query->where('requester_id', $viewer->id);
        }

        $requests = $state->paginate($table, $query);

        return Inertia::render('modules/requests/pages/index', [
            'requests' => [
                'rows' => $requests->getCollection()->map(fn (ApprovalRequest $row): array => RequestPresenter::row($row))->all(),
                'page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'total' => $requests->total(),
                'from' => $requests->firstItem(),
                'to' => $requests->lastItem(),
            ],
            'state' => $state->toArray(),
            'columns' => TableColumns::for($viewer->id, $table),
            'filterOptions' => $table->filters(),
            'views' => SavedView::query()
                ->where('user_id', $viewer->id)
                ->where('table', $table->name())
                ->orderBy('name')
                ->get(['id', 'name', 'state'])
                ->map(fn (SavedView $view): array => ['id' => $view->id, 'name' => $view->name, 'state' => $view->state])
                ->all(),
            'canDecide' => $viewer->hasPermission(Permission::RequestsDecide),
            'seesAll' => $viewer->hasPermission(Permission::RequestsViewAll),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('modules/requests/pages/form', ['request' => null]);
    }

    public function store(SaveRequestRequest $request, CreateRequest $create, #[CurrentUser] User $user): RedirectResponse
    {
        $created = $create->handle($user, $request->fields());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft saved. Add files if needed, then submit it.')]);

        return to_route('requests.show', $created);
    }

    public function show(ApprovalRequest $approvalRequest, #[CurrentUser] User $viewer): Response
    {
        Gate::authorize('view', $approvalRequest);
        $approvalRequest->load(['requester', 'decider', 'attachments']);

        return Inertia::render('modules/requests/pages/show', [
            'request' => RequestPresenter::full($approvalRequest, $viewer),
            'maxKb' => (int) config('platform.files.max_kb'),
            'extensions' => array_keys((array) config('platform.files.types')),
        ]);
    }

    public function edit(ApprovalRequest $approvalRequest, #[CurrentUser] User $viewer): Response
    {
        Gate::authorize('update', $approvalRequest);
        $approvalRequest->load(['requester', 'decider', 'attachments']);

        return Inertia::render('modules/requests/pages/form', ['request' => RequestPresenter::full($approvalRequest, $viewer)]);
    }

    public function update(SaveRequestRequest $request, ApprovalRequest $approvalRequest, UpdateDraft $update, #[CurrentUser] User $user): RedirectResponse
    {
        Gate::authorize('update', $approvalRequest);

        $update->handle($user, $approvalRequest, $request->fields(), $request->integer('lock_version'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Changes saved.')]);

        return to_route('requests.show', $approvalRequest);
    }

    /**
     * Submitting and deciding check only that the person may see the
     * request: the action re-checks everything under the row lock and says
     * precisely what is wrong (already decided, own request), and a repeated
     * click is a no-op rather than a 403.
     */
    public function submit(ApprovalRequest $approvalRequest, SubmitRequest $submit, #[CurrentUser] User $user): RedirectResponse
    {
        Gate::authorize('view', $approvalRequest);

        $submit->handle($user, $approvalRequest);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Submitted. The approvers have been told.')]);

        return to_route('requests.show', $approvalRequest);
    }

    public function decide(DecideRequestRequest $request, ApprovalRequest $approvalRequest, DecideRequest $decide, #[CurrentUser] User $user): RedirectResponse
    {
        Gate::authorize('view', $approvalRequest);

        $decided = $decide->handle($user, $approvalRequest, $request->decision(), $request->input('note'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $decided->status === RequestStatus::Approved ? __('Approved. The requester has been told.') : __('Rejected. The requester has been told why.'),
        ]);

        return to_route('requests.show', $approvalRequest);
    }
}
