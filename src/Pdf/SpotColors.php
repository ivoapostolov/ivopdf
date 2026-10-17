<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

/**
 * Registry of named spot (Separation) colors, mPDF style: AddSpotColor('PANTONE 185 C', c, m, y, k) with
 * components in percent (0..100). Used in CSS as `spot(PANTONE 185 C, 100%)`.
 */
final class SpotColors
{
    /** @var array<string, array{name: string, index: int, cmyk: array{0: float, 1: float, 2: float, 3: float}}> lowercase name => definition */
    private static array $spots = [];

    /**
     * @param float $c cyan 0..100
     * @param float $m magenta 0..100
     * @param float $y yellow 0..100
     * @param float $k black 0..100
     */
    public static function define(string $name, float $c, float $m, float $y, float $k): void
    {
        $name = trim($name);
        if ($name === '' || $name === 'All' || $name === 'None') {
            throw new \InvalidArgumentException('Invalid spot color name.');
        }
        $key = strtolower($name);
        $cmyk = [max(0.0, min(1.0, $c / 100.0)), max(0.0, min(1.0, $m / 100.0)), max(0.0, min(1.0, $y / 100.0)), max(0.0, min(1.0, $k / 100.0))];
        $index = self::$spots[$key]['index'] ?? (count(self::$spots) + 1);
        self::$spots[$key] = ['name' => $name, 'index' => $index, 'cmyk' => $cmyk];
    }

    /**
     * Replaces the registry with the definitions stored in the options (name => [c, m, y, k] in percent).
     *
     * @param array<string, array{0: float, 1: float, 2: float, 3: float}> $defs
     */
    public static function load(array $defs): void
    {
        if ($defs === [] && self::$spots === []) {
            return;
        }
        self::$spots = [];
        foreach ($defs as $name => $v) {
            self::define((string) $name, $v[0], $v[1], $v[2], $v[3]);
        }
    }

    /**
     * @return ?array{name: string, index: int, cmyk: array{0: float, 1: float, 2: float, 3: float}}
     */
    public static function get(string $name): ?array
    {
        return self::$spots[strtolower(trim($name))] ?? null;
    }

    /** @return array<string, array{name: string, index: int, cmyk: array{0: float, 1: float, 2: float, 3: float}}> */
    public static function all(): array
    {
        return self::$spots;
    }

    public static function reset(): void
    {
        self::$spots = [];
    }

    /** Page resource name of a spot color space, e.g. "Sp1". */
    public static function resourceName(int $index): string
    {
        return 'Sp' . $index;
    }

    /** PDF name object body with #xx escapes (without the leading slash). */
    public static function pdfName(string $name): string
    {
        return (string) preg_replace_callback('~[^A-Za-z0-9_.+-]~', static fn(array $m): string => sprintf('#%02X', ord($m[0])), $name);
    }

    /** Whether any spot color is defined (otherwise no content stream needs to be scanned for them). */
    public static function active(): bool
    {
        return self::$spots !== [];
    }

    /**
     * Resource dictionary body entries ("/Sp1 [/Separation ...]") for the spot colors referenced by the given content streams.
     *
     * @param list<string> $contents
     */
    public static function resourceEntries(array $contents): string
    {
        if (self::$spots === []) {
            return '';
        }
        $haystack = implode("\n", $contents);
        $out = [];
        foreach (self::$spots as $spot) {
            $res = self::resourceName($spot['index']);
            if (preg_match('~/' . $res . '\s+(?:cs|CS)\b~', $haystack) !== 1) {
                continue;
            }
            $out[] = sprintf(
                '/%s [/Separation /%s /DeviceCMYK << /FunctionType 2 /Domain [0 1] /C0 [0 0 0 0] /C1 [%.4F %.4F %.4F %.4F] /N 1 >>]',
                $res,
                self::pdfName($spot['name']),
                $spot['cmyk'][0],
                $spot['cmyk'][1],
                $spot['cmyk'][2],
                $spot['cmyk'][3]
            );
        }
        return implode(' ', $out);
    }
}
