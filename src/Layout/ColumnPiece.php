<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

/**
 * One unbreakable unit of a multi-column flow: a single line box or an atomic block subtree.
 */
final class ColumnPiece
{
    /** Column shift (x) currently applied to this piece, relative to its single-column layout position */
    public float $dx = 0.0;
    public bool $forceBefore = false;
    public bool $forceAfter = false;
    public bool $keepNext = false;

    /**
     * @param float $ot Top offset inside the segment's single-column flow
     * @param float $h  Height of the piece
     */
    public function __construct(
        public readonly ?LineBox $line,
        public readonly ?LayoutBox $box,
        public readonly float $ot,
        public readonly float $h
    ) {
    }

    public function top(): float
    {
        return $this->line !== null ? $this->line->y : ($this->box?->y ?? 0.0);
    }

    public function bottom(): float
    {
        return $this->top() + $this->h;
    }

    /**
     * Moves the piece so its top is at $absTop and its column shift becomes $dx.
     */
    public function moveTo(float $absTop, float $dx): void
    {
        $dy = $absTop - $this->top();
        $ddx = $dx - $this->dx;
        if ($this->line !== null) {
            $this->line->translate($ddx, $dy);
        } else {
            $this->box?->translate($ddx, $dy);
        }
        $this->dx = $dx;
    }
}
