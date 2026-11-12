<?php

use Illuminate\Support\Facades\File;

/*
| WCAG AA contrast of the design tokens in resources/css/app.css, light and
| dark (ADR-0024): 4.5:1 for text, 3:1 for field borders and focus rings
| (WCAG 1.4.3 and 1.4.11). A token change that breaks a pair fails here.
*/

/**
 * @return array<string, string> token name => oklch(...) value
 */
function themeTokens(string $selector): array
{
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect(preg_match('/^'.preg_quote($selector, '/').' \{(.*?)^\}/ms', $css, $block))->toBe(1, "{$selector} block not found");

    preg_match_all('/--([a-z0-9-]+):\s*(oklch\([^)]*\));/', $block[1], $matches, PREG_SET_ORDER);

    return array_column($matches, 2, 1);
}

/**
 * WCAG relative luminance of an oklch() colour (clipped to sRGB).
 */
function luminance(string $oklch): float
{
    expect(preg_match('/oklch\(([\d.]+) ([\d.]+) ([\d.]+)\)/', $oklch, $m))->toBe(1, "unparsable {$oklch}");

    [$lightness, $chroma, $hue] = [(float) $m[1], (float) $m[2], deg2rad((float) $m[3])];
    $a = $chroma * cos($hue);
    $b = $chroma * sin($hue);

    $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
    $mm = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
    $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

    $red = 4.0767416621 * $l - 3.3077115913 * $mm + 0.2309699292 * $s;
    $green = -1.2684380046 * $l + 2.6097574011 * $mm - 0.3413193965 * $s;
    $blue = -0.0041960863 * $l - 0.7034186147 * $mm + 1.7076147010 * $s;

    [$red, $green, $blue] = array_map(fn (float $c): float => min(1.0, max(0.0, $c)), [$red, $green, $blue]);

    return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
}

function contrast(string $one, string $two): float
{
    $a = luminance($one);
    $b = luminance($two);

    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

dataset('themes', [':root', '.dark']);

it('gives every text pair at least 4.5:1', function (string $theme) {
    $t = themeTokens($theme);
    $white = 'oklch(1 0 0)';

    $pairs = [
        'foreground on background' => [$t['foreground'], $t['background']],
        'card' => [$t['card-foreground'], $t['card']],
        'popover' => [$t['popover-foreground'], $t['popover']],
        'primary button' => [$t['primary-foreground'], $t['primary']],
        'primary text (links)' => [$t['primary'], $t['background']],
        'secondary' => [$t['secondary-foreground'], $t['secondary']],
        'muted text on background' => [$t['muted-foreground'], $t['background']],
        'muted text on muted' => [$t['muted-foreground'], $t['muted']],
        'muted text on card' => [$t['muted-foreground'], $t['card']],
        'accent' => [$t['accent-foreground'], $t['accent']],
        'destructive button (white text)' => [$white, $t['destructive']],
        'destructive text' => [$t['destructive-foreground'], $t['background']],
        'success' => [$t['success-foreground'], $t['success']],
        'success text (status messages)' => [$t['success'], $t['background']],
        'warning' => [$t['warning-foreground'], $t['warning']],
        'info' => [$t['info-foreground'], $t['info']],
        'sidebar' => [$t['sidebar-foreground'], $t['sidebar']],
        'sidebar primary' => [$t['sidebar-primary-foreground'], $t['sidebar-primary']],
        'sidebar accent' => [$t['sidebar-accent-foreground'], $t['sidebar-accent']],
    ];

    foreach ($pairs as $name => [$text, $background]) {
        expect(contrast($text, $background))->toBeGreaterThanOrEqual(4.5, "{$theme} {$name}: ".round(contrast($text, $background), 2).':1');
    }
})->with('themes');

it('gives field borders and focus rings at least 3:1', function (string $theme) {
    $t = themeTokens($theme);

    foreach (['input', 'ring', 'sidebar-ring'] as $token) {
        foreach (['background', 'card'] as $surface) {
            expect(contrast($t[$token], $t[$surface]))->toBeGreaterThanOrEqual(3.0, "{$theme} {$token} on {$surface}: ".round(contrast($t[$token], $t[$surface]), 2).':1');
        }
    }
})->with('themes');

it('paints the page background before the stylesheet in the token colour', function () {
    $view = (string) file_get_contents(resource_path('views/app.blade.php'));

    expect($view)->toContain('background-color: '.themeTokens(':root')['background'].';')
        ->toContain('background-color: '.themeTokens('.dark')['background'].';');
});

it('colours the application with tokens, not raw palette colours', function () {
    $offenders = [];
    // A QR code needs a white background in both themes.
    $allowed = ['bg-white p-2 [&_svg]:size-full'];

    foreach (File::allFiles(resource_path('js')) as $file) {
        $path = str_replace('\\', '/', $file->getRelativePathname());

        // components/ui follows shadcn upstream; the split auth layout is a
        // deliberately dark panel.
        if ($file->getExtension() !== 'tsx' || str_starts_with($path, 'components/ui/') || $path === 'layouts/auth/auth-split-layout.tsx') {
            continue;
        }

        $source = str_replace($allowed, '', $file->getContents());

        if (preg_match_all('/\b(?:text|bg|border|fill|stroke|ring)-(?:black|white|(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3})\b/', $source, $matches) > 0) {
            $offenders[] = $path.': '.implode(', ', array_unique($matches[0]));
        }
    }

    expect($offenders)->toBe([]);
});

it('computes contrast like the WCAG reference', function () {
    expect(round(contrast('oklch(0 0 0)', 'oklch(1 0 0)'), 1))->toBe(21.0)
        ->and(round(contrast('oklch(1 0 0)', 'oklch(1 0 0)'), 1))->toBe(1.0);
});
