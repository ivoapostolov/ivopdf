<?php

declare(strict_types=1);

namespace IvoPdf\Paged;

use IvoPdf\Layout\LayoutBox;

/**
 * CSS named strings (`string-set: chapter content();`) collected from the paginated layout tree,
 * for `string(chapter)` in page margin boxes.
 */
final class StringSets
{
    /** @var array<int, array<string, list<string>>> page index => name => values assigned on that page (document order) */
    private array $perPage = [];

    /** @var array<string, bool> */
    private array $names = [];

    /**
     * @param \Closure(float): int $pageOfY
     */
    public static function collect(LayoutBox $root, \Closure $pageOfY): self
    {
        $self = new self();
        $self->walk($root, $pageOfY);
        return $self;
    }

    public function isEmpty(): bool
    {
        return $this->names === [];
    }

    /**
     * Value of a named string on a page. $mode: first (default) | last | start | first-except.
     */
    public function get(string $name, int $pageIndex, string $mode = 'first'): string
    {
        $mode = strtolower($mode);
        $onPage = $this->perPage[$pageIndex][$name] ?? [];
        $carry = '';
        for ($p = $pageIndex - 1; $p >= 0; $p--) {
            if (!empty($this->perPage[$p][$name])) {
                $carry = end($this->perPage[$p][$name]);
                break;
            }
        }
        return match ($mode) {
            'last' => $onPage !== [] ? (string) end($onPage) : $carry,
            'start' => $carry,
            'first-except' => $onPage !== [] ? '' : $carry,
            default => $onPage !== [] ? $onPage[0] : $carry,
        };
    }

    /**
     * @param \Closure(float): int $pageOfY
     */
    private function walk(LayoutBox $box, \Closure $pageOfY): void
    {
        if ($box->isTextNode()) {
            return;
        }
        $decl = trim($box->style->get('string-set', 'none'));
        if ($decl !== '' && strtolower($decl) !== 'none') {
            $page = $pageOfY($box->y);
            foreach (self::parse($decl, $box) as $name => $value) {
                $this->perPage[$page][$name][] = $value;
                $this->names[$name] = true;
            }
        }
        foreach ($box->children as $child) {
            $this->walk($child, $pageOfY);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function parse(string $decl, LayoutBox $box): array
    {
        $out = [];
        // "name value-list, name2 value-list": split on commas outside strings / parentheses
        $parts = [];
        $cur = '';
        $paren = 0;
        $quote = '';
        for ($i = 0, $n = strlen($decl); $i < $n; $i++) {
            $c = $decl[$i];
            if ($quote !== '') {
                $cur .= $c;
                if ($c === '\\' && $i + 1 < $n) {
                    $cur .= $decl[++$i];
                } elseif ($c === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(') {
                $paren++;
            } elseif ($c === ')') {
                $paren--;
            } elseif ($c === ',' && $paren === 0) {
                $parts[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        $parts[] = $cur;

        $text = $box->domElement !== null ? trim((string) preg_replace('/\s+/u', ' ', $box->domElement->textContent)) : '';
        foreach ($parts as $part) {
            if (!preg_match('/^\s*([\w-]+)\s*(.*)$/s', $part, $m)) {
                continue;
            }
            $value = '';
            $rest = trim($m[2]);
            $len = strlen($rest);
            $i = 0;
            while ($i < $len) {
                $c = $rest[$i];
                if ($c === '"' || $c === "'") {
                    $j = $i + 1;
                    $s = '';
                    while ($j < $len && $rest[$j] !== $c) {
                        if ($rest[$j] === '\\' && $j + 1 < $len) {
                            $j++;
                        }
                        $s .= $rest[$j];
                        $j++;
                    }
                    $value .= $s;
                    $i = $j + 1;
                    continue;
                }
                if (preg_match('/^(content|attr)\(([^)]*)\)/i', substr($rest, $i), $fm)) {
                    if (strtolower($fm[1]) === 'content') {
                        $value .= $text;
                    } elseif ($box->domElement !== null) {
                        $value .= $box->domElement->getAttribute(trim($fm[2], " \t'\""));
                    }
                    $i += strlen($fm[0]);
                    continue;
                }
                $i++;
            }
            $out[$m[1]] = $value;
        }
        return $out;
    }
}
