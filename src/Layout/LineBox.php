<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

/**
 * Represents a horizontal line of inline TextRuns after line-wrapping and alignment.
 */
class LineBox
{
    public float $x = 0.0;
    public float $y = 0.0;
    public float $width = 0.0;
    public float $height = 0.0;
    public float $baselineOffset = 0.0;

    /** The paginator starts a page with this line (footnote-policy: line | block); ignored at the top of a page. */
    public bool $forceBreak = false;

    /** @var list<TextRun> */
    public array $runs = [];

    public function addRun(TextRun $run): void
    {
        $this->runs[] = $run;
    }

    /**
     * Shifts all runs in this line by ($dx, $dy).
     */
    public function translate(float $dx, float $dy): void
    {
        $this->x += $dx;
        $this->y += $dy;
        foreach ($this->runs as $run) {
            $run->x += $dx;
            $run->y += $dy;
            if ($run->inlineBox !== null) {
                $run->inlineBox->translate($dx, $dy);
            }
        }
    }
}
