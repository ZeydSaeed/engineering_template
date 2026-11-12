<?php

/*
| The component gallery exists only in development and the browser tests
| (ADR-0025); the test environment, like production, has no such route.
*/

it('is not served outside its environments', function () {
    $this->get('/ui')->assertNotFound();
});

it('is never enabled for production or staging', function () {
    expect((array) config('platform.ui.gallery_environments'))
        ->not->toContain('production')
        ->not->toContain('staging')
        ->not->toContain('testing');
});
