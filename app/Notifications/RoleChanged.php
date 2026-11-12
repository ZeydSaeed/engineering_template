<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Tells a person a role was granted or removed (ADR-0029). Stored in the
 * database channel; the interface writes the sentence in the reader's
 * language from these fields, so nothing is translated at send time.
 */
class RoleChanged extends Notification
{
    public function __construct(
        private readonly string $change,
        private readonly string $role,
        private readonly string $by,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{kind: string, role: string, by: string}
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->change, 'role' => $this->role, 'by' => $this->by];
    }
}
