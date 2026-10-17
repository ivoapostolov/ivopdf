<?php

declare(strict_types=1);

namespace IvoPdf;

use IvoPdf\Exception\LimitExceededException;

/**
 * Time and memory budget of the rendering in progress (Options::$maxRenderTime / Options::$maxMemory).
 *
 * The layout, pagination and painting code calls tick() at safe points (per box, per paragraph, per page); only every
 * 64th call looks at the clock and at memory_get_usage(), so the guard costs next to nothing. When a limit is
 * exceeded a LimitExceededException is thrown, which unwinds the render and is catchable by the caller.
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
            $used = memory_get_usage();
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
