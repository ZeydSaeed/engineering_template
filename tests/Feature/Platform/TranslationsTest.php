<?php

use App\Platform\Localization\Translations;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;

/*
| Interface languages (ADR-0024): every string has an Arabic translation,
| no English text is hard-coded in the interface, and the chosen language
| sets <html lang dir>.
*/

/**
 * @return array<string, string> key => file it was found in
 */
function usedTranslationKeys(): array
{
    $string = "'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\"";
    $patterns = [
        // t('...') calls, and the English keys held as data and translated
        // where they are shown (titles, layout props, menu items).
        '/\bt\(\s*(?:'.$string.')/',
        // t(label ?? 'Default text')
        '/\bt\(\s*[\w.]+\s*\?\?\s*(?:'.$string.')/',
        '/\b(?:title|description|buttonText|toggleText|label)\s*:\s*(?:'.$string.')/',
    ];
    $keys = [];

    foreach (File::allFiles(resource_path('js')) as $file) {
        if (preg_match('#[\\\\/](actions|routes|wayfinder)[\\\\/]#', $file->getPathname()) === 1 || ! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $file->getContents(), $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $key = stripslashes($match[1] !== '' ? $match[1] : ($match[2] ?? ''));
                $keys[$key] = $file->getRelativePathname();
            }
        }
    }

    foreach (File::allFiles(app_path()) as $file) {
        preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'/", $file->getContents(), $matches);

        foreach ($matches[1] as $key) {
            $keys[stripslashes($key)] = $file->getRelativePathname();
        }
    }

    unset($keys['']);

    return $keys;
}

/**
 * @return list<string> placeholders such as ":name"
 */
function placeholders(string $text): array
{
    preg_match_all('/:[a-z_]+/', $text, $matches);
    $found = $matches[0];
    sort($found);

    return $found;
}

/**
 * @param  array<mixed>  $array
 * @return list<string>
 */
function keyPaths(array $array, string $prefix = ''): array
{
    $paths = [];

    foreach ($array as $key => $value) {
        $paths[] = $prefix.$key;

        if (is_array($value) && $value !== []) {
            $paths = [...$paths, ...keyPaths($value, $prefix.$key.'.')];
        }
    }

    return $paths;
}

it('has an Arabic translation for every interface string, with the same placeholders', function () {
    $arabic = Translations::for('ar');
    $used = usedTranslationKeys();

    expect(count($used))->toBeGreaterThan(100);

    foreach ($used as $key => $file) {
        expect(array_key_exists($key, $arabic))->toBeTrue("Missing in lang/ar.json: \"{$key}\" ({$file})");
        expect(placeholders($arabic[$key]))->toBe(placeholders($key), "Placeholders differ for \"{$key}\"");
    }

    $unused = array_diff(array_keys($arabic), array_keys($used));
    expect($unused)->toBe([], 'Unused keys in lang/ar.json: '.implode(' | ', $unused));
});

it('keeps the Arabic framework files in step with the English ones', function (string $name) {
    $english = require base_path("vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$name}.php");
    $arabic = require lang_path("ar/{$name}.php");

    $missing = array_diff(keyPaths($english), keyPaths($arabic), ['custom.attribute-name', 'custom.attribute-name.rule-name']);

    expect($missing)->toBe([], "lang/ar/{$name}.php lacks: ".implode(', ', $missing));
})->with(['auth', 'pagination', 'passwords', 'validation']);

it('leaves no English text hard-coded in the interface', function () {
    $offenders = [];

    foreach (File::allFiles(resource_path('js')) as $file) {
        if ($file->getExtension() !== 'tsx' || preg_match('#[\\\\/](actions|routes|wayfinder)[\\\\/]#', $file->getPathname()) === 1) {
            continue;
        }

        $source = $file->getContents();

        // Text between a tag and the next tag or expression: <p>Text</p>.
        // Not after "=>", which ends an arrow function's parameters.
        preg_match_all('/(?<=[^=]>)[^<>{}]*[A-Za-z]{2}[^<>{}]*(?=<|\{)/', $source, $text);
        // Visible attributes with literal English.
        preg_match_all('/\s(?:title|placeholder|aria-label|alt|label)="[^"]*[A-Za-z]{2}[^"]*"/', $source, $attributes);
        // English defaults and alternatives outside t(): {label ?? 'Text'},
        // {busy ? 'Saving...' : 'Save'}.
        $outsideT = (string) preg_replace('/\bt\([^()]*\)/', 't()', $source);
        preg_match_all("/\\?\\?\\s*'[A-Z][a-z][^']*'|\\?\\s*'[A-Z][a-z][^']*'\\s*:\\s*'[^']*'/", $outsideT, $expressions);

        foreach ($expressions[0] as $found) {
            $offenders[] = $file->getRelativePathname().': '.$found;
        }

        foreach ([...$text[0], ...$attributes[0]] as $found) {
            // TypeScript generics, arrow functions and class names such as
            // [&>svg] also contain ">" and "<".
            if (preg_match('/[;=()`|&\[\]]|React\./', $found) === 1 || in_array(trim($found), ['Promise', 'void', 'string'], true)) {
                continue;
            }

            $offenders[] = $file->getRelativePathname().': '.trim(preg_replace('/\s+/', ' ', $found) ?? '');
        }
    }

    expect($offenders)->toBe([]);
});

it('renders the chosen language and its direction', function (string $locale, string $direction) {
    $this->withUnencryptedCookie('locale', $locale)
        ->get('/')
        ->assertOk()
        ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'"', false);
})->with([['ar', 'rtl'], ['en', 'ltr']]);

it('ignores a language that is not offered', function () {
    config(['app.locale' => 'en']);

    $this->withUnencryptedCookie('locale', 'xx')
        ->get('/')
        ->assertSee('<html lang="en" dir="ltr"', false);
});

it('passes only known themes to the page script', function () {
    $this->withUnencryptedCookie('appearance', "dark';alert(1)//")
        ->get('/')
        ->assertDontSee('alert(1)', false)
        ->assertSee('const appearance = "system";', false);
});

it('lets the theme script run under the Content-Security-Policy', function () {
    $response = $this->get('/');
    $nonce = Vite::cspNonce();

    expect($nonce)->not->toBe('')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("'nonce-{$nonce}'");

    $response->assertSee('<script nonce="'.$nonce.'">', false);
});
