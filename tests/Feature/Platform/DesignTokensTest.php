<?php

use Tests\Support\DesignTokens;

/*
| WCAG 2.2 AA contrast of every declared colour pair, in every theme
| (docs/standards/COLOR-DESIGN-SYSTEM.md §10, ADR-0024): 4.5:1 for text,
| 3:1 for control boundaries, focus rings and chart marks (WCAG 1.4.3 and
| 1.4.11). The pairs are resources/design-system/pairs.json; a component
| that introduces a new pairing adds it there. A token change that breaks a
| pair fails here.
*/

/**
 * @return list<array{fg: string, bg: string, min: float, over?: string}>
 */
function declaredPairs(): array
{
    return json_decode((string) file_get_contents(resource_path('design-system/pairs.json')), true, flags: JSON_THROW_ON_ERROR);
}

dataset('themes', array_keys(DesignTokens::THEMES));

it('meets the contrast of every declared pair', function (string $theme) {
    $tokens = DesignTokens::resolved($theme);
    $failures = [];

    foreach (declaredPairs() as $pair) {
        foreach (['fg', 'bg', 'over'] as $side) {
            if (isset($pair[$side])) {
                expect($tokens)->toHaveKey($pair[$side], message: "{$theme}: pair token --{$pair[$side]} is not declared");
            }
        }

        $ratio = DesignTokens::contrast(
            $tokens[$pair['fg']],
            $tokens[$pair['bg']],
            $tokens[$pair['over'] ?? 'background'],
        );

        if ($ratio < $pair['min']) {
            $failures[] = sprintf('%s on %s: %.2f:1, %s:1 needed', $pair['fg'], $pair['bg'], $ratio, $pair['min']);
        }
    }

    expect($failures)->toBe([], "{$theme}: ".implode('; ', $failures));
})->with('themes');

it('declares a pair for every text colour and every surface', function () {
    $pairs = collect(declaredPairs());
    $roles = array_keys(DesignTokens::block('design-system/themes.css', ':root'));

    foreach ($roles as $role) {
        if (str_ends_with($role, '-foreground')) {
            $surface = substr($role, 0, -strlen('-foreground'));

            expect($pairs->contains(fn (array $p): bool => $p['fg'] === $role && $p['bg'] === $surface))
                ->toBeTrue("no declared pair for {$role} on {$surface}");
        }

        if (str_ends_with($role, '-text')) {
            expect($pairs->contains(fn (array $p): bool => $p['fg'] === $role && $p['bg'] === 'background'))
                ->toBeTrue("no declared pair for {$role} on background");
        }
    }
});

it('paints the page background before the stylesheet in the token colour', function () {
    expect((string) file_get_contents(resource_path('views/app.blade.php')))->toContain("@include('design-system.prepaint')");

    $view = (string) file_get_contents(resource_path('views/design-system/prepaint.blade.php'));

    foreach (DesignTokens::THEMES as $theme => $selector) {
        $background = DesignTokens::resolved($theme)['background'];
        $css = sprintf('oklch(%s %s %s)', $background['l'], $background['c'], $background['h']);

        expect($view)->toContain("background-color: {$css};");
    }
});

it('computes contrast like the WCAG reference', function () {
    $black = DesignTokens::parse('oklch(0 0 0)');
    $white = DesignTokens::parse('oklch(1 0 0)');

    expect(round(DesignTokens::contrast($black, $white), 1))->toBe(21.0)
        ->and(round(DesignTokens::contrast($white, $white), 1))->toBe(1.0)
        // 50% black over white is sRGB 0.5 grey: 3.98:1 against white.
        ->and(round(DesignTokens::contrast([...$black, 'alpha' => 0.5], $white, $white), 2))->toBe(3.98);
});
