<?php

declare(strict_types=1);

namespace IvoPdf\Paged;

/**
 * Collects every `@page` rule of the document: plain, `:first` / `:left` / `:right`, named (`@page wide`, `@page wide:first`)
 * and their nested margin boxes (`@top-center { content: ... }`), and resolves the cascade for one page.
 */
class PageRules
{
    public const MARGIN_BOXES = [
        'top-left-corner', 'top-left', 'top-center', 'top-right', 'top-right-corner',
        'bottom-left-corner', 'bottom-left', 'bottom-center', 'bottom-right', 'bottom-right-corner',
        'left-top', 'left-middle', 'left-bottom', 'right-top', 'right-middle', 'right-bottom',
    ];

    /** @var array<string, array{props: array<string, string>, boxes: array<string, array<string, string>>}> */
    private array $rules = [];

    /** Page 1 is a left page (root element direction rtl) */
    public bool $firstPageLeft = false;

    /**
     * Registers one `@page <selector> { body }` rule. Later rules override earlier ones property by property.
     */
    public function add(string $selector, string $body): void
    {
        [$props, $boxes] = $this->parseBody($body);
        foreach (explode(',', $selector) as $sel) {
            $key = strtolower((string) preg_replace('/\s+/', '', $sel));
            $this->rules[$key] ??= ['props' => [], 'boxes' => []];
            foreach ($props as $p => $v) {
                $this->rules[$key]['props'][$p] = $v;
            }
            foreach ($boxes as $bn => $bp) {
                foreach ($bp as $p => $v) {
                    $this->rules[$key]['boxes'][$bn][$p] = $v;
                }
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    /** True if any rule differs per page (selectors other than the plain `@page`) or margin boxes exist. */
    public function hasVariants(): bool
    {
        foreach ($this->rules as $key => $rule) {
            if ($key !== '' || $rule['boxes'] !== []) {
                return true;
            }
        }
        return false;
    }

    /** True if the page geometry (size / margins) can differ between pages. */
    public function hasPageVariants(): bool
    {
        foreach (array_keys($this->rules) as $key) {
            if ($key !== '') {
                return true;
            }
        }
        return false;
    }

    public function hasMarginBoxes(): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule['boxes'] !== []) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> Names of all named page rules */
    public function getNames(): array
    {
        $names = [];
        foreach (array_keys($this->rules) as $key) {
            $n = explode(':', $key)[0];
            if ($n !== '' && !in_array($n, $names, true)) {
                $names[] = $n;
            }
        }
        return $names;
    }

    public function hasName(string $name): bool
    {
        return in_array(strtolower($name), $this->getNames(), true);
    }

    /**
     * Cascade for a page: unnamed rules, then :left/:right, then :first; named rules override unnamed ones.
     * Page index 0 is a right (recto) page.
     *
     * @return array{props: array<string, string>, boxes: array<string, array<string, string>>}
     */
    public function resolve(int $pageIndex, ?string $name = null): array
    {
        // Page 1 is a right page, or a left page when the root element's direction is rtl
        $side = ($pageIndex % 2 === 0) !== $this->firstPageLeft ? ':right' : ':left';
        $keys = ['', $side];
        if ($pageIndex === 0) {
            $keys[] = ':first';
        }
        if ($name !== null && $name !== '') {
            $n = strtolower($name);
            $keys[] = $n;
            $keys[] = $n . $side;
            if ($pageIndex === 0) {
                $keys[] = $n . ':first';
            }
        }

        $props = [];
        $boxes = [];
        foreach ($keys as $key) {
            if (!isset($this->rules[$key])) {
                continue;
            }
            foreach ($this->rules[$key]['props'] as $p => $v) {
                if ($p === 'margin') {
                    unset($props['margin-top'], $props['margin-right'], $props['margin-bottom'], $props['margin-left']);
                }
                $props[$p] = $v;
            }
            foreach ($this->rules[$key]['boxes'] as $bn => $bp) {
                foreach ($bp as $p => $v) {
                    $boxes[$bn][$p] = $v;
                }
            }
        }
        // Margin boxes inherit from the page context (CSS Paged Media 3): `@page { font-family: Arial; font-size: 9pt }`
        // styles the text of every margin box that does not set the property itself, as in browsers
        foreach ($boxes as $bn => $bp) {
            $merged = [];
            foreach ($props as $p => $v) {
                if (!isset($bp[$p]) && preg_match('/^(font|color$|line-height$|letter-spacing$|word-spacing$|text-transform$|direction$|white-space$)/', $p) === 1) {
                    $merged[$p] = $v;
                }
            }
            $boxes[$bn] = $merged === [] ? $bp : $merged + $bp;
        }
        return ['props' => $props, 'boxes' => $boxes];
    }

    /**
     * Splits an @page body into declarations and nested margin-box blocks.
     *
     * @return array{0: array<string, string>, 1: array<string, array<string, string>>}
     */
    private function parseBody(string $body): array
    {
        $props = [];
        $boxes = [];
        $len = strlen($body);
        $i = 0;
        $decl = '';
        while ($i < $len) {
            $ch = $body[$i];
            if ($ch === '"' || $ch === "'") {
                $j = $i + 1;
                while ($j < $len && $body[$j] !== $ch) {
                    $j += $body[$j] === '\\' ? 2 : 1;
                }
                $decl .= substr($body, $i, $j - $i + 1);
                $i = $j + 1;
                continue;
            }
            if ($ch === '@') {
                $brace = strpos($body, '{', $i);
                if ($brace === false) {
                    break;
                }
                $name = strtolower(trim(substr($body, $i + 1, $brace - $i - 1)));
                $depth = 1;
                $j = $brace + 1;
                while ($j < $len && $depth > 0) {
                    $c = $body[$j];
                    if ($c === '"' || $c === "'") {
                        $k = $j + 1;
                        while ($k < $len && $body[$k] !== $c) {
                            $k += $body[$k] === '\\' ? 2 : 1;
                        }
                        $j = $k + 1;
                        continue;
                    }
                    if ($c === '{') {
                        $depth++;
                    } elseif ($c === '}') {
                        $depth--;
                    }
                    $j++;
                }
                $inner = substr($body, $brace + 1, max(0, $j - $brace - 2));
                if (in_array($name, self::MARGIN_BOXES, true)) {
                    foreach ($this->parseDeclarations($inner) as $p => $v) {
                        $boxes[$name][$p] = $v;
                    }
                }
                $i = $j;
                continue;
            }
            $decl .= $ch;
            $i++;
        }
        foreach ($this->parseDeclarations($decl) as $p => $v) {
            $props[$p] = $v;
        }
        return [$props, $boxes];
    }

    /**
     * @return array<string, string>
     */
    private function parseDeclarations(string $text): array
    {
        $out = [];
        $len = strlen($text);
        $cur = '';
        $parts = [];
        $paren = 0;
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($ch === '"' || $ch === "'") {
                $j = $i + 1;
                while ($j < $len && $text[$j] !== $ch) {
                    $j += $text[$j] === '\\' ? 2 : 1;
                }
                $cur .= substr($text, $i, $j - $i + 1);
                $i = $j;
                continue;
            }
            if ($ch === '(') {
                $paren++;
            } elseif ($ch === ')') {
                $paren = max(0, $paren - 1);
            }
            if ($ch === ';' && $paren === 0) {
                $parts[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        $parts[] = $cur;

        foreach ($parts as $part) {
            $pos = strpos($part, ':');
            if ($pos === false) {
                continue;
            }
            $prop = strtolower(trim(substr($part, 0, $pos)));
            $val = trim((string) preg_replace('/!\s*important\s*$/i', '', trim(substr($part, $pos + 1))));
            if ($prop !== '' && $val !== '') {
                $out[$prop] = $val;
            }
        }
        return $out;
    }
}
