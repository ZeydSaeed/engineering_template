<?php

use App\Platform\Outbox\Outbox;
use Tests\Support\Outbox\CountingOutboxJob;

/*
| Without RefreshDatabase, which wraps every test in a transaction and would
| hide the missing one.
*/

it('refuses a message outside a transaction', function () {
    config(['platform.outbox.handlers' => ['test.counted' => CountingOutboxJob::class]]);

    app(Outbox::class)->record('test.counted', ['n' => 1]);
})->throws(LogicException::class, 'inside the transaction');
