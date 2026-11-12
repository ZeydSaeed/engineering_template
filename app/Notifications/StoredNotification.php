<?php

namespace App\Notifications;

use Illuminate\Notifications\DatabaseNotification;

/**
 * Laravel's database notification, with its times stored to the
 * microsecond (ADR-0029): notifications sent in the same second keep their
 * order, since the ids are random UUIDs.
 */
class StoredNotification extends DatabaseNotification
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';
}
