<?php

declare(strict_types=1);

namespace IvoPdf\Css;

/**
 * Evaluator for CSS math functions: calc() (nested), min(), max(), clamp(), parentheses, unary +/-,
 * `+ - * /` with mixed units. Every dimension is converted to pt (percentages against the reference size),
 * plain numbers stay plain; type checking of unit dimensions is intentionally lenient.
 * Returns null for an invalid expression (syntax error, unbalanced parentheses, division by zero, unknown function).
 */
final class CssCalc
{
    private string $s = '';
    private int $i = 0;
    private int $n = 0;
    private int $depth = 0;

    private function __construct(
        private readonly float $ref,
        private readonly float $fontSize,
        private readonly float $rootFontSize,
        private readonly float $vw,
        private readonly float $vh
    ) {
    }

    /** True when the value starts with a math function this class evaluates. */
    public static function isMathFunction(string $value): bool
    {
        return preg_match('/^\s*(?:calc|min|max|clamp)\(/i', $value) === 1;
    }

    /** @param string $value a complete value such as "calc(100% - 4pt)" or "min(100%, 400px)" */
    public static function evaluate(
        string $value,
        float $referenceSizePt = 0.0,
        float $fontSizePt = 12.0,
        float $rootFontSizePt = 12.0,
        float $viewportWidthPt = 595.28,
        float $viewportHeightPt = 841.89
    ): ?float {
        $me = new self($referenceSizePt, $fontSizePt, $rootFontSizePt, $viewportWidthPt, $viewportHeightPt);
        $me->s = strtolower(trim($value));
        $me->n = strlen($me->s);
        try {
            $v = $me->parseSum();
            $me->ws();
            if ($me->i !== $me->n || !is_finite($v)) {
                return null;
            }
            return $v;
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    private function ws(): void
    {
        while ($this->i < $this->n && ctype_space($this->s[$this->i])) {
            $this->i++;
        }
    }

    private function fail(): never
    {
        throw new \UnexpectedValueException('invalid calc');
    }

    private function parseSum(): float
    {
        $v = $this->parseProduct();
        while (true) {
            $this->ws();
            $c = $this->s[$this->i] ?? '';
            if ($c !== '+' && $c !== '-') {
                return $v;
            }
            $this->i++;
            $r = $this->parseProduct();
            $v = $c === '+' ? $v + $r : $v - $r;
        }
    }

    private function parseProduct(): float
    {
        $v = $this->parseUnary();
        while (true) {
            $this->ws();
            $c = $this->s[$this->i] ?? '';
            if ($c !== '*' && $c !== '/') {
                return $v;
            }
            $this->i++;
            $r = $this->parseUnary();
            if ($c === '*') {
                $v *= $r;
            } else {
                if ($r == 0.0) {
                    $this->fail();
                }
                $v /= $r;
            }
        }
    }

    private function parseUnary(): float
    {
        $this->ws();
        $c = $this->s[$this->i] ?? '';
        if ($c === '-' || $c === '+') {
            $this->i++;
            $v = $this->parseUnary();
            return $c === '-' ? -$v : $v;
        }
        return $this->parsePrimary();
    }

    private function parsePrimary(): float
    {
        $this->ws();
        if (++$this->depth > 32) {
            $this->fail();
        }
        try {
            $c = $this->s[$this->i] ?? '';
            if ($c === '(') {
                $this->i++;
                $v = $this->parseSum();
                $this->expect(')');
                return $v;
            }
            if (preg_match('/\G(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?/', $this->s, $m, 0, $this->i) === 1) {
                $this->i += strlen($m[0]);
                $num = (float) $m[0];
                if (preg_match('/\G(%|[a-z]+)/', $this->s, $u, 0, $this->i) === 1) {
                    $this->i += strlen($u[1]);
                    return $this->dimension($num, $u[1]);
                }
                return $num;
            }
            if (preg_match('/\G(calc|min|max|clamp)\(/', $this->s, $m, 0, $this->i) === 1) {
                $this->i += strlen($m[0]);
                if ($m[1] === 'calc') {
                    $v = $this->parseSum();
                    $this->expect(')');
                    return $v;
                }
                $args = [$this->parseSum()];
                while (true) {
                    $this->ws();
                    if (($this->s[$this->i] ?? '') === ',') {
                        $this->i++;
                        $args[] = $this->parseSum();
                        continue;
                    }
                    break;
                }
                $this->expect(')');
                return match ($m[1]) {
                    'min' => min($args),
                    'max' => max($args),
                    default => count($args) === 3 ? max($args[0], min($args[1], $args[2])) : $this->fail(),
                };
            }
            $this->fail();
        } finally {
            $this->depth--;
        }
    }

    private function expect(string $ch): void
    {
        $this->ws();
        if (($this->s[$this->i] ?? '') !== $ch) {
            $this->fail();
        }
        $this->i++;
    }

    private function dimension(float $num, string $unit): float
    {
        if (!in_array($unit, ['%', 'rem', 'em', 'ex', 'ch', 'px', 'pt', 'mm', 'cm', 'in', 'pc', 'q', 'vw', 'vh', 'vmin', 'vmax'], true)) {
            $this->fail();
        }
        return CssUnit::toPt($num . $unit, $this->ref, $this->fontSize, $this->rootFontSize, 0.0, $this->vw, $this->vh);
    }
}
