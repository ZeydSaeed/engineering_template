<?php

namespace App\Modules\Requests\Application\Notifications;

use Illuminate\Notifications\Notification;

/**
 * A request event for one person (ADR-0033): submitted (to approvers),
 * approved or rejected (to the requester). Database channel; the fields are
 * facts, and the interface writes the sentence in the reader's language
 * (ADR-0029).
 */
class RequestNotification extends Notification
{
    public const SUBMITTED = 'request_submitted';

    public const APPROVED = 'request_approved';

    public const REJECTED = 'request_rejected';

    public function __construct(
        private readonly string $kind,
        private readonly string $request,
        private readonly string $title,
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
     * @return array{kind: string, request: string, title: string, by: string}
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'request' => $this->request, 'title' => $this->title, 'by' => $this->by];
    }
}
