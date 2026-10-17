<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

use IvoPdf\Css\ComputedStyle;
use WeakMap;

/**
 * mPDF-style "shrink tables to fit": a table whose minimum content width exceeds the available width gets the
 * font sizes, paddings and absolute lengths of its content scaled down (at most by 1 / factor) so it fits the page
 * instead of overflowing it.
 *
 * Factor sources, in order: `autosize="N"` attribute (0 = never), CSS `overflow: shrink`, Options::$shrinkTablesToFit
 * (mPDF `shrink_tables_to_fit`, default 1.4; 0 disables).
 */
final class TableShrink
{
    /** Style properties holding absolute lengths that scale together with the font size. */
    private const SCALED_PROPS = [
        'font-size', 'line-height', 'letter-spacing', 'word-spacing', 'text-indent',
        'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height',
    ];

    /** @var WeakMap<LayoutBox, float>|null cumulative scale already applied per table (layout passes may repeat) */
    private static ?WeakMap $applied = null;

    /**
     * Maximum shrink factor (>= 1) for this table; 0 = shrinking disabled.
     */
    public static function factorFor(LayoutBox $table, float $optionFactor): float
    {
        $factor = $optionFactor;
        $dom = $table->domElement;
        if ($dom !== null && $dom->hasAttribute('autosize')) {
            $raw = trim($dom->getAttribute('autosize'));
            if (is_numeric($raw)) {
                $v = (float) $raw;
                $factor = $v <= 0.0 ? 0.0 : ($v <= 1.0 ? ($optionFactor > 1.0 ? $optionFactor : 1.4) : $v);
            }
        } elseif (strtolower(trim($table->style->get('overflow', 'visible'))) === 'shrink') {
            $factor = $optionFactor > 1.0 ? $optionFactor : 1.4;
        }
        return $factor > 1.0 ? min($factor, 10.0) : 0.0;
    }

    /**
     * A table with an explicit width larger than its block container is clamped to the container
     * (mPDF shrinks such tables too); the content is then shrunk by the normal mechanism if it does not fit.
     */
    public static function clampToContainer(LayoutBox $table, float $factor): void
    {
        $p = $table->parent;
        if ($factor <= 1.0 || $p === null || $p->width <= 0.0 || !in_array($p->getDisplay(), ['block', 'table-cell', 'list-item'], true)) {
            return;
        }
        $w = strtolower(trim($table->style->get('width', 'auto')));
        if ($w === 'auto' || $w === '' || str_ends_with($w, '%')) {
            return; // auto / percentage widths already follow the container
        }
        $avail = $p->getContentWidth() - $table->marginLeft - $table->marginRight;
        if ($avail >= 20.0 && $table->width > $avail + 0.5) {
            $table->width = $avail;
        }
    }

    /**
     * Scales the table content so that `$required` fits `$usable`, honouring the cumulative limit 1 / $factor.
     * Returns true when the styles were changed (widths must then be measured again).
     */
    public static function shrink(LayoutBox $table, float $required, float $usable, float $factor): bool
    {
        if ($factor <= 1.0 || $required <= $usable + 0.5 || $usable < 20.0) {
            return false;
        }
        self::$applied ??= new WeakMap();
        $done = self::$applied[$table] ?? 1.0;
        $limit = 1.0 / $factor;
        if ($done <= $limit + 0.001) {
            return false;
        }
        // Borders and unscaled lengths do not shrink, so aim slightly under the exact ratio
        $step = max($limit / $done, ($usable / $required) * 0.99);
        if ($step >= 0.999) {
            return false;
        }
        self::$applied[$table] = $done * $step;

        $seen = [];
        foreach ($table->children as $child) {
            self::scaleBox($child, $step, $seen);
        }
        return true;
    }

    /** Scale already applied to a table (1.0 = none); used by tests and diagnostics. */
    public static function appliedScale(LayoutBox $table): float
    {
        return self::$applied[$table] ?? 1.0;
    }

    /**
     * @param array<int, ComputedStyle> $seen scaled style by the id of the style it replaces
     */
    private static function scaleBox(LayoutBox $box, float $s, array &$seen): void
    {
        $stack = [$box];
        while ($stack !== []) {
            $b = array_pop($stack);
            $id = spl_object_id($b->style);
            if (!isset($seen[$id])) {
                // A shared style (the cascade hands identical table cells one object) also serves boxes outside this
                // table, which must keep their size: scale a private copy and give it to the boxes of this table
                $seen[$id] = $b->style->isShared() ? clone $b->style : $b->style;
                self::scaleStyle($seen[$id], $s);
            }
            $b->style = $seen[$id];
            foreach ($b->children as $c) {
                $stack[] = $c;
            }
        }
    }

    private static function scaleStyle(ComputedStyle $style, float $s): void
    {
        foreach (self::SCALED_PROPS as $prop) {
            $v = $style->get($prop, '');
            if ($v === '' || !preg_match('/\d(?:px|pt|mm|cm|in|pc)\b/i', $v)) {
                continue;
            }
            $scaled = (string) preg_replace_callback(
                '/(-?\d*\.?\d+)(px|pt|mm|cm|in|pc)\b/i',
                static fn(array $m): string => round((float) $m[1] * $s, 4) . $m[2],
                $v
            );
            if ($scaled !== $v) {
                $style->set($prop, $scaled);
            }
        }
    }
}
