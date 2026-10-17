<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

/**
 * Paginator bookkeeping for the children of one box: the downward shifts owed to the children that follow a page
 * break. Instead of adding the shift to every following sibling at every break (quadratic for 20 000 headings), a
 * shift of "every child from position k on" is stored once as a tail and resolved when a child is synced.
 *
 * Positions are indexes into {@see $kids}: the children a sibling shift may move (in flow, not positioned through
 * the parent's line boxes). From {@see $mono} on, their tops are non-decreasing, so the children at or below a
 * threshold form a suffix that a binary search finds; every shift keeps that order.
 *
 * @internal
 */
final class SiblingShift
{
    /** @var list<LayoutBox> */
    public array $kids = [];
    /** @var array<int, int> spl_object_id(child) => 2 * (number of kids before it) + (1 when the child is a kid) */
    public array $slot = [];
    /** count($parent->children) when this state was built */
    public int $childCount = 0;
    /** First position of the non-decreasing run of kid tops that lasts to the end */
    public int $mono = 0;
    /** Kids from this position on owe {@see $tailDy} minus what they have already taken ({@see $seen}) */
    public int $tailFrom = PHP_INT_MAX;
    public float $tailDy = 0.0;
    /** @var array<int, float> position => tailDy already moved into the kid's pendingDy */
    public array $seen = [];
}
