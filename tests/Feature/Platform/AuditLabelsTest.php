<?php

use Symfony\Component\Finder\Finder;

/*
| Every audit action the application records reads as words in the activity
| timeline (improvement #9), not as its code: a new action needs its label in
| activity-timeline.tsx (found missing for user.invited in Phase 8).
*/

it('gives every recorded audit action a label in the timeline', function () {
    $timeline = (string) file_get_contents(resource_path('js/components/activity-timeline.tsx'));
    $actions = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        preg_match_all("/->record\\(\\s*'([a-z_]+\\.[a-z_]+)'/", $file->getContents(), $matches);
        $actions = [...$actions, ...$matches[1]];
    }

    expect($actions)->not->toBeEmpty();

    foreach (array_unique($actions) as $action) {
        expect(str_contains($timeline, "case '{$action}':"))->toBeTrue("{$action} has no label in activity-timeline.tsx");
    }
});
