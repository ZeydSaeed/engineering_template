<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A person's notifications (improvement #8, ADR-0029): newest first, read
 * one or all. Another person's notification is 404.
 */
class NotificationController extends Controller
{
    public const LIST_LIMIT = 50;

    public function index(#[CurrentUser] User $user): Response
    {
        return Inertia::render('notifications', [
            'items' => $user->notifications()
                ->latest()
                ->limit(self::LIST_LIMIT)
                ->get()
                ->map(fn (DatabaseNotification $notification): array => [
                    'id' => $notification->id,
                    'data' => $notification->data,
                    'read' => $notification->read_at !== null,
                    'created_at' => $notification->created_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    public function read(string $id, #[CurrentUser] User $user): RedirectResponse
    {
        $user->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return back();
    }

    public function readAll(#[CurrentUser] User $user): RedirectResponse
    {
        $user->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
