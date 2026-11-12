<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation email: a link to set a password (the password-reset page,
 * valid for auth.passwords.users.expire minutes). ADR-0027.
 */
class UserInvited extends Notification
{
    public function __construct(
        private readonly string $token,
        private readonly string $invitedBy,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = (string) ($notifiable->email ?? '');

        return (new MailMessage)
            ->subject(__('You are invited to :app', ['app' => (string) config('app.name')]))
            ->line(__(':name invited you to :app.', ['name' => $this->invitedBy, 'app' => (string) config('app.name')]))
            ->action(__('Set your password'), route('password.reset', ['token' => $this->token, 'email' => $email]))
            ->line(__('The link works for :minutes minutes. After it expires, use "Forgot password" on the sign-in page.', [
                'minutes' => (int) config('auth.passwords.users.expire'),
            ]));
    }
}
