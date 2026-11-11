<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * An in-app alert for staff (shown under the bell in the admin header). Stored in the notifications table.
 * It never carries card data, and no email is sent: transactional email needs the owner's SMTP details first.
 */
class StaffAlert extends Notification
{
    public function __construct(
        private readonly string $kind,        // new_order | payment_reported
        private readonly string $title,
        private readonly string $body,
        private readonly string $route,       // admin route name
        private readonly array $routeParams,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'url' => route($this->route, $this->routeParams, false)];
    }
}
