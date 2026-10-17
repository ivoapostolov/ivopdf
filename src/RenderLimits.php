<?php

declare(strict_types=1);

namespace IvoPdf;

use IvoPdf\Exception\LimitExceededException;

/**
 * Time and memory budget of the rendering in progress (Options::$maxRenderTime / Options::$maxMemory).
 *
 * The layout, pagination and painting code calls tick() at safe points (per box, per paragraph, per page); only every
 * 64th call looks at the clock and at the memory in use, so the guard costs next to nothing. When a limit is
 * exceeded a LimitExceededException is thrown, which unwinds the render and is catchable by the caller.
 *
 * Memory is measured as PHP's allocator sees it against memory_limit (memory_get_usage(true): the chunks really
 * taken, not the bytes in live variables), and the steps that allocate a lot in one call (HTML parsing, image
 * decoding) ask first: fits() / ensure() / available() estimate whether an allocation still fits the budget, so the
 * exception (or a skipped image) comes before PHP's uncatchable "Allowed memory size exhausted" fatal error.
 *
 * One budget covers a whole IvoPdf::render() / output() call: the extra layout passes of footnotes, mPDF indexes and
 * NEXT-ODD breaks run inside it (nested begin() calls share the outermost budget).
 */
final class RenderLimits
{
    private static int $depth = 0;
    private static bool $active = false;
    private static int $ticks = 0;
    private static float $started = 0.0;
    private static float $timeLimit = 0.0;
    private static int $memoryCap = 0;
    private static int $memoryLimit = 0;

    /** Starts the budget of a rendering (nested renderings share the outermost budget). */
    public static function begin(Options $options): void
    {
        if (self::$depth++ > 0) {
            return;
        }
        self::$ticks = 0;
        self::$started = microtime(true);
        self::$timeLimit = $options->maxRenderTime > 0 ? (float) $options->maxRenderTime : 0.0;
        self::$memoryLimit = $options->getMaxMemoryBytes();
        self::$memoryCap = self::$memoryLimit;
        self::$active = self::$timeLimit > 0.0 || self::$memoryCap > 0;
    }

    public static function end(): void
    {
        if (self::$depth > 0 && --self::$depth === 0) {
            self::$active = false;
        }
    }

    /**
     * Bytes that may still be allocated before the memory budget is exhausted: the budget of the rendering in progress,
     * otherwise 90 % of PHP's memory_limit. PHP_INT_MAX when memory is not limited at all.
     */
    public static function available(): int
    {
        // Without a budget of its own (outside a rendering, or Options::$maxMemory = 0) PHP's memory_limit still applies
        $cap = self::$depth > 0 && self::$memoryLimit > 0 ? self::$memoryLimit : self::defaultCap();
        if ($cap <= 0) {
            return PHP_INT_MAX;
        }
        return max(0, $cap - memory_get_usage(true));
    }

    /** True when $bytes more can be allocated within the memory budget (see available()). */
    public static function fits(int|float $bytes): bool
    {
        return $bytes <= self::available();
    }

    /**
     * Throws LimitExceededException ('memory') when an allocation of about $bytes (an estimate made before a step that
     * allocates in one call, e.g. parsing the HTML) does not fit the memory budget.
     */
    public static function ensure(int|float $bytes, string $what): void
    {
        $left = self::available();
        if ($bytes <= $left) {
            return;
        }
        self::$active = false; // one exception; the caller may render something else afterwards
        throw new LimitExceededException(
            'memory',
            sprintf(
                'Rendering aborted: %s needs about %s MB of memory, only %s MB of the budget are left (Options::$maxMemory / memory_limit).',
                $what,
                number_format($bytes / 1048576, 1, '.', ''),
                number_format($left / 1048576, 1, '.', '')
            )
        );
    }

    /** 90 % of PHP's memory_limit (the 'auto' budget), 0 when unlimited. */
    private static function defaultCap(): int
    {
        $limit = self::phpMemoryLimit();
        return $limit > 0 ? (int) ($limit * 0.9) : 0;
    }

    /** Cheap safe-point hook. */
    public static function tick(): void
    {
        if (self::$active && (++self::$ticks & 63) === 0) {
            self::check();
        }
    }

    /** Checks both limits right now. */
    public static function check(): void
    {
        if (!self::$active) {
            return;
        }
        if (self::$timeLimit > 0.0) {
            $elapsed = microtime(true) - self::$started;
            if ($elapsed > self::$timeLimit) {
                self::$active = false; // one exception; the caller may render something else afterwards
                throw new LimitExceededException(
                    'time',
                    sprintf('Rendering aborted: more than %s s of processing time (Options::$maxRenderTime).', rtrim(rtrim(number_format(self::$timeLimit, 2, '.', ''), '0'), '.'))
                );
            }
        }
        if (self::$memoryCap > 0) {
            // memory_limit is enforced on the allocator's real size (whole chunks), which exceeds the bytes in use
            $used = memory_get_usage(true);
            if ($used > self::$memoryCap) {
                self::$active = false;
                throw new LimitExceededException(
                    'memory',
                    sprintf('Rendering aborted: %s MB of memory in use exceeds the limit of %s MB (Options::$maxMemory).', number_format($used / 1048576, 1, '.', ''), number_format(self::$memoryCap / 1048576, 1, '.', ''))
                );
            }
        }
    }

    /**
     * Parses a byte amount: an int, a numeric string, or a php.ini style shorthand ('256M', '1G', '512K').
     * Returns 0 for empty / invalid input and -1 values (unlimited).
     */
    public static function parseBytes(int|string|float $value): int
    {
        if (is_int($value) || is_float($value)) {
            return $value > 0 ? (int) $value : 0;
        }
        $v = strtolower(trim($value));
        if ($v === '' || !preg_match('/^(\d+(?:\.\d+)?)\s*([kmg]?)b?$/', $v, $m)) {
            return 0;
        }
        $n = (float) $m[1];
        $n *= match ($m[2]) {
            'k' => 1024,
            'm' => 1048576,
            'g' => 1073741824,
            default => 1,
        };
        return (int) $n;
    }

    /**
     * PHP's memory_limit in bytes, 0 when unlimited.
     */
    public static function phpMemoryLimit(): int
    {
        $raw = ini_get('memory_limit');
        if ($raw === false || trim($raw) === '' || trim($raw) === '-1') {
            return 0;
        }
        return self::parseBytes($raw);
    }
}
