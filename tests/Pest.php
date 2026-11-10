<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case
|--------------------------------------------------------------------------
|
| Closure-based feature tests run inside the Laravel application. The
| class-based tests that came with the starter kit extend TestCase
| themselves. Architecture tests need no application.
|
*/

pest()->extend(TestCase::class)->in('Feature');
