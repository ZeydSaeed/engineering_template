<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Reads the colour layers in resources/css/design-system/ the way a browser
 * resolves them, for the colour tests (docs/standards/COLOR-DESIGN-SYSTEM.md).
 *
 * The maths matches scripts/design-system/palette.mjs: OKLCH to sRGB
 * (clipped), WCAG 2.x relative luminance, color-mix in OKLCH, translucent
 * colours composited over their surface in gamma-encoded sRGB.
 *
 * @phpstan-type Colour array{l: float, c: float, h: float, alpha: float}
 */
final class DesignTokens
{
    public const THEMES = ['light' => ':root', 'dark' => '.dark'];

    public static function css(string $file): string
    {
        $path = resource_path('css/'.$file);

        if (! is_file($path)) {
            throw new RuntimeException("missing {$path}");
        }

        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    /**
     * Raw declarations of one block, in order, duplicates kept.
     *
     * @return list<array{0: string, 1: string}> [name, value]
     */
    public static function declarations(string $css, string $selector): array
    {
        if (preg_match('/^'.preg_quote($selector, '/').' \{(.*?)^\}/ms', $css, $block) !== 1) {
            return [];
        }

        preg_match_all('/^\s*--([a-z0-9*-]+):\s*(.+?);\s*$/m', $block[1], $matches, PREG_SET_ORDER);

        return array_map(fn (array $m): array => [$m[1], trim($m[2])], $matches);
    }

    /**
     * @return array<string, string>
     */
    public static function block(string $file, string $selector): array
    {
        $tokens = [];

        foreach (self::declarations(self::css($file), $selector) as [$name, $value]) {
            $tokens[$name] = $value;
        }

        return $tokens;
    }

    /**
     * L1 + L2 (for the theme) + L3 declarations, as the root element sees them.
     *
     * @return array<string, string>
     */
    public static function raw(string $theme): array
    {
        $light = self::block('design-system/themes.css', ':root');
        $themed = $theme === 'light' ? $light : array_merge($light, self::block('design-system/themes.css', self::THEMES[$theme]));

        return array_merge(
            self::block('design-system/palette.css', ':root'),
            $themed,
            self::block('design-system/components.css', ':root'),
        );
    }

    /**
     * Every colour token of a theme, resolved.
     *
     * @return array<string, Colour>
     */
    public static function resolved(string $theme): array
    {
        $raw = self::raw($theme);
        $resolved = [];

        foreach ($raw as $name => $value) {
            // Gradients are images, checked stop by stop by the generator.
            if (str_starts_with($name, 'gradient-')) {
                continue;
            }

            $resolved[$name] = self::evaluate($value, $raw);
        }

        return $resolved;
    }

    /**
     * @param  array<string, string>  $raw
     * @return Colour
     */
    public static function evaluate(string $value, array $raw, int $depth = 0): array
    {
        if ($depth > 10) {
            throw new RuntimeException("reference loop at {$value}");
        }

        if (preg_match('/^oklch\(([\d.]+) ([\d.]+) ([\d.]+)\)$/', $value, $m) === 1) {
            return ['l' => (float) $m[1], 'c' => (float) $m[2], 'h' => (float) $m[3], 'alpha' => 1.0];
        }

        if (preg_match('/^var\(--([a-z0-9-]+)\)$/', $value, $m) === 1) {
            if (! isset($raw[$m[1]])) {
                throw new RuntimeException("--{$m[1]} is not declared");
            }

            return self::evaluate($raw[$m[1]], $raw, $depth + 1);
        }

        if (preg_match('/^color-mix\(in oklch, (var\(--[a-z0-9-]+\)) (\d+)%, (transparent|var\(--[a-z0-9-]+\))\)$/', $value, $m) === 1) {
            $left = self::evaluate($m[1], $raw, $depth + 1);

            return $m[3] === 'transparent'
                ? [...$left, 'alpha' => (int) $m[2] / 100]
                : self::mix($left, (int) $m[2], self::evaluate($m[3], $raw, $depth + 1));
        }

        throw new RuntimeException("cannot evaluate {$value}");
    }

    /**
     * color-mix(in oklch, A p%, B), CSS Color 4 hue interpolation (shorter).
     *
     * @param  Colour  $a
     * @param  Colour  $b
     * @return Colour
     */
    public static function mix(array $a, int $percent, array $b): array
    {
        $t = 1 - $percent / 100;
        $ha = $a['c'] < 0.0005 ? $b['h'] : $a['h'];
        $hb = $b['c'] < 0.0005 ? $ha : $b['h'];
        $delta = $hb - $ha;
        $delta += $delta > 180 ? -360 : ($delta < -180 ? 360 : 0);

        return [
            'l' => $a['l'] + ($b['l'] - $a['l']) * $t,
            'c' => $a['c'] + ($b['c'] - $a['c']) * $t,
            'h' => fmod($ha + $delta * $t + 360, 360),
            'alpha' => 1.0,
        ];
    }

    /**
     * Gamma-encoded sRGB, composited over $over when translucent.
     *
     * @param  Colour  $colour
     * @param  Colour|null  $over
     * @return array{0: float, 1: float, 2: float}
     */
    public static function srgb(array $colour, ?array $over = null): array
    {
        $hue = deg2rad($colour['h']);
        $a = $colour['c'] * cos($hue);
        $b = $colour['c'] * sin($hue);

        $l = ($colour['l'] + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($colour['l'] - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($colour['l'] - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        $linear = [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];

        $top = array_map(function (float $c): float {
            $c = min(1.0, max(0.0, $c));

            return $c <= 0.0031308 ? 12.92 * $c : 1.055 * $c ** (1 / 2.4) - 0.055;
        }, $linear);

        if ($colour['alpha'] >= 1 || $over === null) {
            return [$top[0], $top[1], $top[2]];
        }

        $bottom = self::srgb($over);
        $alpha = $colour['alpha'];

        return [
            $top[0] * $alpha + $bottom[0] * (1 - $alpha),
            $top[1] * $alpha + $bottom[1] * (1 - $alpha),
            $top[2] * $alpha + $bottom[2] * (1 - $alpha),
        ];
    }

    /**
     * @param  Colour  $colour
     * @param  Colour|null  $over
     */
    public static function luminance(array $colour, ?array $over = null): float
    {
        [$r, $g, $b] = array_map(
            fn (float $c): float => $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
            self::srgb($colour, $over),
        );

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * @param  Colour  $one
     * @param  Colour  $two
     * @param  Colour|null  $over
     */
    public static function contrast(array $one, array $two, ?array $over = null): float
    {
        $a = self::luminance($one, $over);
        $b = self::luminance($two, $over);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * @return Colour
     */
    public static function parse(string $oklch): array
    {
        return self::evaluate($oklch, []);
    }

    /**
     * The color tokens app.css exposes to Tailwind (--color-<name>: var(--<name>)).
     *
     * @return array<string, string> name => referenced token
     */
    public static function exposed(): array
    {
        $exposed = [];

        foreach (self::declarations(self::css('app.css'), '@theme') as [$name, $value]) {
            if (str_starts_with($name, 'color-') && $name !== 'color-*') {
                $exposed[substr($name, 6)] = preg_match('/^var\(--([a-z0-9-]+)\)$/', $value, $m) === 1 ? $m[1] : $value;
            }
        }

        return $exposed;
    }
}
