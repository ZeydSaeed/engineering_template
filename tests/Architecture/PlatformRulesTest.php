<?php

/*
| Rules that hold for the whole codebase (ADR-0004). Layer direction inside
| modules is checked by deptrac (deptrac.yaml); rules between pairs of
| modules are in ModuleBoundariesTest.
*/

arch('no debugging or unsafe PHP functions')
    ->preset()->php();

arch('no insecure functions')
    ->preset()->security();

arch('env() is read only in config files')
    ->expect('env')
    ->not->toBeUsed();

arch('the platform core never depends on business modules')
    ->expect('App\Platform')
    ->not->toUse('App\Modules');

arch('models do not resolve services from the container')
    ->expect(['app', 'resolve'])
    ->not->toBeUsedIn(['App\Models', 'App\Modules']);

arch('controllers do not use the DB facade or raw queries')
    ->expect('Illuminate\Support\Facades\DB')
    ->not->toBeUsedIn('App\Http\Controllers');
