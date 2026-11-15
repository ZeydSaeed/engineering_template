<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\DesignTokens;

/*
| Governance of the colour design system (docs/standards/COLOR-DESIGN-
| SYSTEM.md §12): no raw colours in the UI, the four layers reference only
| the layer below, no duplicate, missing or unused tokens, complete themes,
| and generated files in step with resources/design-system/palette.json.
| Contrast is DesignTokensTest.
*/

const TAILWIND_PALETTE = '(?:black|white|(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3})';

/**
 * @return list<array{path: string, pattern: string, reason: string, approved_by: string, date: string}>
 */
function colourExceptions(): array
{
    return json_decode((string) file_get_contents(resource_path('design-system/exceptions.json')), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Every .tsx file and Blade view, components/ui included, keyed by its path
 * from the project root.
 *
 * @return array<string, string>
 */
function interfaceSources(): array
{
    $sources = [];

    foreach ([...File::allFiles(resource_path('js')), ...File::allFiles(resource_path('views'))] as $file) {
        $path = str_replace('\\', '/', 'resources/'.str_replace(resource_path().DIRECTORY_SEPARATOR, '', $file->getPathname()));

        if (str_ends_with($path, '.tsx') || str_ends_with($path, '.blade.php')) {
            $sources[$path] = $file->getContents();
        }
    }

    return $sources;
}

/**
 * The forbidden colour forms of §11 found in one source.
 *
 * @return list<string>
 */
function rawColours(string $source): array
{
    $patterns = [
        // Tailwind palette utilities, with any variant prefix.
        '/(?<![\w-])(?:bg|text|border(?:-[xytrblse])?|ring|ring-offset|outline|fill|stroke|decoration|from|via|to|divide|shadow|caret|accent|placeholder)-'.TAILWIND_PALETTE.'(?:\/\d+)?(?![\w-])/',
        // Arbitrary values with a colour literal or a palette reference.
        '/\[[^\]\s]*(?:#[0-9a-fA-F]{3,8}|rgba?\(|hsla?\(|oklch\(|oklab\(|lch\(|lab\(|color-mix\()[^\]\s]*\]/',
        // Arbitrary named colours (bg-[red], text-[white]).
        '/(?<![\w-])(?:bg|text|border|ring|outline|fill|stroke|decoration|from|via|to|divide|shadow|caret|accent|placeholder)-\[[a-zA-Z]+\]/',
        '/(?:var\()?--palette-[a-z0-9-]+/',
        // Inline style colours.
        '/style=\{\{[^}]*\b(?:color|background(?:Color)?|border(?:Color)?|fill|stroke|outlineColor)\s*:/',
        // Colour strings in code.
        '/[\'"`]#[0-9a-fA-F]{3,8}[\'"`]/',
    ];
    $found = [];

    foreach ($patterns as $pattern) {
        preg_match_all($pattern, $source, $matches);
        array_push($found, ...$matches[0]);
    }

    return array_values(array_unique($found));
}

it('uses no raw colours in any component, page or view (§11)', function () {
    $exceptions = collect(colourExceptions())->groupBy('path');
    $offenders = [];

    foreach (interfaceSources() as $path => $source) {
        foreach ($exceptions->get($path, []) as $exception) {
            $source = str_replace($exception['pattern'], '', $source);
        }

        // Generated with the palette, like the CSS layers (check 9).
        if ($path === 'resources/views/design-system/prepaint.blade.php') {
            continue;
        }

        if (($found = rawColours($source)) !== []) {
            $offenders[] = "{$path}: ".implode(', ', $found);
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps every exception approved, explained and in use (§13)', function () {
    $sources = interfaceSources();

    foreach (colourExceptions() as $exception) {
        expect($exception['reason'])->not->toBe('')
            ->and($exception['approved_by'])->not->toBe('')
            ->and($exception['date'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
            ->and($sources[$exception['path']] ?? '')->toContain($exception['pattern']);
    }
});

it('keeps colour literals in the design-system files only', function () {
    $css = (string) preg_replace('#/\*.*?\*/#s', '', DesignTokens::css('app.css'));

    expect(preg_match_all('/#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?|oklch|oklab|lch|lab)\(/', $css, $m))->toBe(0, implode(', ', $m[0]))
        ->and(DesignTokens::css('design-system/components.css'))->not->toMatch('/#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?|oklch|oklab)\(/');
});

it('lets each layer reference only the layer below (§2)', function () {
    foreach (DesignTokens::declarations(DesignTokens::css('design-system/palette.css'), ':root') as [$name, $value]) {
        expect($name)->toStartWith('palette-')
            ->and($value)->toMatch('/^oklch\([\d.]+ [\d.]+ [\d.]+\)$/');
    }

    $palette = '(?:var\(--palette-[a-z0-9-]+\))';

    foreach (DesignTokens::THEMES as $selector) {
        foreach (DesignTokens::declarations(DesignTokens::css('design-system/themes.css'), $selector) as [$name, $value]) {
            expect($value)->toMatch("/^(?:{$palette}|color-mix\(in oklch, {$palette} \d+%, (?:{$palette}|transparent)\)|linear-gradient\(\d+deg(?:, {$palette})+\)|none)$/", "--{$name}");
        }
    }

    $roles = array_keys(DesignTokens::block('design-system/themes.css', ':root'));
    $role = '(?:var\(--(?:'.implode('|', array_map('preg_quote', $roles)).')\))';

    foreach (DesignTokens::declarations(DesignTokens::css('design-system/components.css'), ':root') as [$name, $value]) {
        expect($value)->toMatch("/^(?:{$role}|color-mix\(in oklch, {$role} \d+%, (?:{$role}|transparent)\))$/", "--{$name} must reference L2 roles only");
    }
});

it('declares no token twice', function () {
    $blocks = [
        ['design-system/palette.css', ':root'],
        ['design-system/themes.css', ':root'],
        ['design-system/themes.css', '.dark'],
        ['design-system/components.css', ':root'],
        ['app.css', '@theme'],
    ];
    $owner = [];

    foreach ($blocks as [$file, $selector]) {
        $names = array_column(DesignTokens::declarations(DesignTokens::css($file), $selector), 0);

        expect(array_diff_assoc($names, array_unique($names)))->toBe([], "{$file} {$selector}");

        // L1, L2 and L3 never share a name.
        if ($selector !== '.dark' && $file !== 'app.css') {
            foreach ($names as $name) {
                expect($owner)->not->toHaveKey($name, message: "--{$name} is declared in two layers");
                $owner[$name] = $file;
            }
        }
    }
});

it('defines every role in every theme, and changes it on purpose (§6.3)', function () {
    $light = DesignTokens::block('design-system/themes.css', ':root');
    $dark = DesignTokens::block('design-system/themes.css', '.dark');
    $palette = json_decode((string) file_get_contents(resource_path('design-system/palette.json')), true, flags: JSON_THROW_ON_ERROR);
    // The scrim behind dialogs is the same in every theme by design.
    $invariant = ['overlay'];

    expect(array_keys($dark))->toEqualCanonicalizing(array_keys($light));

    foreach ($light as $role => $value) {
        // Text is derived from its surface: the same text is fine on a
        // surface that differs between themes or was set explicitly.
        $surface = str_ends_with($role, '-foreground') ? substr($role, 0, -strlen('-foreground')) : null;
        $decided = array_intersect([$role, $surface], array_keys($palette['roles']['dark'] ?? []));

        if ($surface !== null && isset($light[$surface]) && $light[$surface] !== $dark[$surface]) {
            continue;
        }

        // Gradients are declared per theme in palette.json "gradients".
        if ($dark[$role] === $value && ! in_array($role, $invariant, true) && ! str_starts_with($role, 'gradient-')) {
            expect($decided)->not->toBe([], "dark --{$role} copies light without an explicit palette.json role");
        }
    }
});

it('exposes exactly the roles and component tokens to Tailwind, each used (§12 checks 3, 4)', function () {
    $roles = array_keys(DesignTokens::block('design-system/themes.css', ':root'));
    $components = array_keys(DesignTokens::block('design-system/components.css', ':root'));
    $exposed = DesignTokens::exposed();

    foreach ($exposed as $utility => $token) {
        expect($utility)->toBe($token, "--color-{$utility} must expose --{$utility}")
            ->and([...$roles, ...$components])->toContain($token);
    }

    $gradients = array_filter($roles, fn (string $r): bool => str_starts_with($r, 'gradient-'));

    expect(array_keys($exposed))->toEqualCanonicalizing(array_values(array_diff([...$roles, ...$components], $gradients)));

    // Component tokens earn their place: each is used by a component or by
    // CSS outside its own definition. Roles are the contract and may idle.
    $usage = implode("\n", interfaceSources()).(string) preg_replace('/^\s*--color-[a-z0-9-]+: var\(--[a-z0-9-]+\);$/m', '', DesignTokens::css('app.css'));

    foreach ($components as $token) {
        expect(preg_match('/(?<![\w-])(?:[a-z-]+-)?'.preg_quote($token, '/').'(?:\/\d+)?(?![\w-])|var\(--'.preg_quote($token, '/').'\)/', $usage))
            ->toBe(1, "component token --{$token} is not used");
    }
});

it('resolves every colour utility the interface uses', function () {
    $exposed = array_keys(DesignTokens::exposed());
    $keywords = ['transparent', 'current', 'inherit', 'none'];
    $notColour = '/^(?:fixed|local|scroll|clip-.+|origin-.+|repeat.*|no-repeat|cover|contain|auto|center|top|bottom|left|right|left-.+|right-.+|linear-.+|radial.*|conic.*|gradient-.+|blend-.+|size-.+|position-.+)$/';
    $missing = [];

    foreach (interfaceSources() as $path => $source) {
        preg_match_all('/(?<![\w\[-])(?:bg|fill|stroke)-([a-z][a-z0-9-]*)(?:\/\d+)?(?![\w-])/', $source, $matches);

        foreach (array_unique($matches[1]) as $name) {
            if (! in_array($name, [...$exposed, ...$keywords], true) && preg_match($notColour, $name) !== 1 && ! preg_match('/^\d/', $name)) {
                $missing[] = "{$path}: {$name}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('keeps the generated files in step with palette.json (§12 check 9)', function () {
    $result = Process::path(base_path())->run(['node', 'scripts/design-system/palette.mjs', '--check']);

    expect($result->exitCode())->toBe(0, $result->errorOutput());
});
