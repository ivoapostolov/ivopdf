<?php

declare(strict_types=1);

namespace IvoPdf\Css;

use DOMElement;

/**
 * Parses CSS stylesheets, @page / @media / @supports / @font-face rules, CSS custom variables,
 * and matches (pre-compiled, cached) selectors against DOMElements.
 * Uses a linear-time O(N) balanced-brace scanner to avoid PCRE backtracking limits on large stylesheets.
 */
class CssParser
{
    /**
     * @var list<array{
     *   selector: string,
     *   pseudo: ?string,
     *   compiled: list<mixed>,
     *   declarations: array<string, array{value: string, important: bool}>,
     *   specificity: int,
     *   order: int
     * }>
     */
    private array $rules = [];

    /** @var array<string, string> */
    private array $pageProps = [];

    /** @var array<string, string> */
    private array $firstPageProps = [];

    /** All @page rules (selectors, named pages, margin boxes) for the paged-media engine */
    private ?\IvoPdf\Paged\PageRules $pageRules = null;

    /** @var array<string, string> */
    private array $rootVariables = [];

    /** @var list<array<string, string>> Declarations of every @font-face block */
    private array $fontFaces = [];

    /** @var array<string, list<mixed>|null> Compiled selector cache */
    private array $compiledCache = [];

    private int $ruleCounter = 0;
    /** @var array<string, true> pseudo-elements that have at least one rule (cheap pre-check for ::first-letter / ::first-line) */
    private array $pseudoSeen = [];
    /** @var array{id: array<string, list<int>>, class: array<string, list<int>>, tag: array<string, list<int>>, any: list<int>}|null Rules bucketed by the key of their rightmost compound */
    private ?array $ruleIndex = null;
    private int $ruleIndexCount = -1;

    /** @var \WeakMap<ComputedStyle, array<string, ComputedStyle>>|null Per parent style: computed style prototypes of its children by tag + attributes */
    private ?\WeakMap $styleMemo = null;
    /** @var \WeakMap<object, array<string, ComputedStyle>>|null Like $styleMemo, keyed by ComputedStyle::shareToken(): cousins (same ancestors' tags + attributes) share their children's prototypes */
    private ?\WeakMap $tokenMemo = null;
    private int $styleMemoRules = -1;
    private ?bool $positionRules = null;
    /** @var array<int, int> rule order => 2: the outcome depends on neither the position of the subject nor of its ancestors, 1: not on the subject's sibling position, 0: it may */
    private array $positionFreeRules = [];

    /** Media viewport (the printable page area) used to evaluate width/height/orientation media features */
    private float $mediaWidthPt = 523.28;
    private float $mediaHeightPt = 769.89;

    /**
     * Elements whose computed style objects may be shared between boxes (only TableShrink changes them later, on
     * copies): 2 = always, 1 = only while no rule depends on the position of an element (a shared row style stands for
     * rows at different positions, so cell styles cached per parent style object would leak from row to row).
     */
    private const SHARED_STYLE_TAGS = ['td' => 2, 'th' => 2, 'tr' => 1, 'thead' => 1, 'tbody' => 1, 'tfoot' => 1];

    private const NEVER_MATCHING_PSEUDOS = [
        'hover', 'focus', 'focus-within', 'focus-visible', 'active', 'visited', 'target', 'placeholder-shown',
        'before', 'after', 'first-line', 'first-letter', 'selection', 'placeholder', 'marker', 'backdrop',
    ];

    /** @var (\Closure(string): ?string)|null Loads an @import URL (security checks happen in the resolver) */
    private ?\Closure $importResolver = null;

    private int $importDepth = 0;

    /** @var array<string, true> URLs already imported (cycle guard) */
    private array $importedUrls = [];

    /**
     * Sets the callback used to fetch `@import`ed stylesheets. It receives the URL and returns CSS text or null.
     */
    public function setImportResolver(?\Closure $resolver): void
    {
        $this->importResolver = $resolver;
    }

    /**
     * Handles semicolon-terminated at-rules; only `@import url(...) [media];` has an effect.
     */
    private function handleStatementAtRule(string $statement): void
    {
        if (!preg_match('/^@import\s+(?:url\(\s*([\'"]?)(.+?)\1\s*\)|([\'"])(.+?)\3)\s*(.*)$/is', $statement, $m)) {
            return;
        }
        $url = trim($m[2] !== '' ? $m[2] : ($m[4] ?? ''));
        $media = trim((string) preg_replace('/^(layer(\([^)]*\))?|supports\([^)]*\))\s*/i', '', trim($m[5] ?? '')));
        if ($url === '' || $this->importResolver === null || $this->importDepth >= 5 || isset($this->importedUrls[$url])) {
            return;
        }
        if ($media !== '' && !$this->matchesMediaQueryList($media)) {
            return;
        }
        $this->importedUrls[$url] = true;
        $css = ($this->importResolver)($url);
        if ($css === null || $css === '') {
            return;
        }
        $this->importDepth++;
        try {
            $this->parseStylesheet($css);
        } finally {
            $this->importDepth--;
        }
    }

    public function setMediaViewport(float $widthPt, float $heightPt): void
    {
        $this->mediaWidthPt = max(1.0, $widthPt);
        $this->mediaHeightPt = max(1.0, $heightPt);
    }

    public function parseStylesheet(string $css): void
    {
        // Remove CSS comments safely
        $css = (string) preg_replace('/\/\*.*?\*\//s', '', $css);

        // Linear-time O(N) extraction of @page, @media, @supports, @font-face and rule blocks
        $this->parseBlocksLinear($css);
    }

    /**
     * Linear-time balanced-brace scanner for CSS blocks and @-rules.
     */
    private function parseBlocksLinear(string $css): void
    {
        $len = strlen($css);
        $i = 0;

        while ($i < $len) {
            // Skip whitespace and stray semicolons
            while ($i < $len && (ctype_space($css[$i]) || $css[$i] === ';')) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }

            $bracePos = strpos($css, '{', $i);

            // Statement at-rules (@import, @charset, @namespace, @layer a, b;) end with a semicolon before any brace
            if ($css[$i] === '@') {
                $semiAbs = strpos($css, ';', $i);
                if ($semiAbs !== false && ($bracePos === false || $semiAbs < $bracePos)) {
                    $this->handleStatementAtRule(trim(substr($css, $i, $semiAbs - $i)));
                    $i = $semiAbs + 1;
                    continue;
                }
            }

            if ($bracePos === false) {
                break;
            }

            $header = trim(substr($css, $i, $bracePos - $i));

            // Find matching closing '}' using brace depth counter (ignoring braces inside strings)
            $depth = 1;
            $j = $bracePos + 1;
            $inSingleQuote = false;
            $inDoubleQuote = false;

            while ($j < $len && $depth > 0) {
                $ch = $css[$j];
                if ($ch === '\\') {
                    $j += 2;
                    continue;
                }
                if ($ch === "'" && !$inDoubleQuote) {
                    $inSingleQuote = !$inSingleQuote;
                } elseif ($ch === '"' && !$inSingleQuote) {
                    $inDoubleQuote = !$inDoubleQuote;
                } elseif (!$inSingleQuote && !$inDoubleQuote) {
                    if ($ch === '{') {
                        $depth++;
                    } elseif ($ch === '}') {
                        $depth--;
                    }
                }
                $j++;
            }

            $body = substr($css, $bracePos + 1, max(0, $j - $bracePos - 2));
            $i = $j;

            if ($header === '') {
                continue;
            }

            if (str_starts_with($header, '@')) {
                $this->handleAtRule($header, $body);
            } else {
                $this->registerRuleBlock($header, $body);
            }
        }
    }

    private function handleAtRule(string $header, string $body): void
    {
        $lowerHeader = strtolower(trim($header));

        if (str_starts_with($lowerHeader, '@page')) {
            $pageSelector = trim(substr($lowerHeader, 5));
            $this->getPageRules()->add($pageSelector, $body);
            // Ignore nested margin boxes (@top-center { ... }) inside @page
            $body = (string) preg_replace('/@[a-z-]+\s*\{[^}]*\}/i', '', $body);
            $decls = $this->parseDeclarations($body);
            if ($pageSelector === ':first') {
                foreach ($decls as $prop => $info) {
                    $this->firstPageProps[$prop] = $info['value'];
                }
            } elseif ($pageSelector === '') {
                foreach ($decls as $prop => $info) {
                    $this->pageProps[$prop] = $info['value'];
                }
            }
            return;
        }

        if (str_starts_with($lowerHeader, '@media')) {
            if ($this->matchesMediaQueryList(trim(substr($lowerHeader, 6)))) {
                $this->parseBlocksLinear($body);
            }
            return;
        }

        // Feature queries and cascade layers: assume support and parse the nested rules
        if (str_starts_with($lowerHeader, '@supports') || str_starts_with($lowerHeader, '@layer') || str_starts_with($lowerHeader, '@document')) {
            if (!str_starts_with($lowerHeader, '@supports not')) {
                $this->parseBlocksLinear($body);
            }
            return;
        }

        if (str_starts_with($lowerHeader, '@font-face')) {
            $face = [];
            foreach ($this->parseDeclarations($body) as $prop => $info) {
                $face[$prop] = $info['value'];
            }
            if (!empty($face)) {
                $this->fontFaces[] = $face;
            }
        }
        // @keyframes, @container, @counter-style, ... are not applicable to static PDF output
    }

    /**
     * Evaluates a comma-separated media query list against print media and the page viewport.
     */
    public function matchesMediaQueryList(string $query): bool
    {
        $q = strtolower(trim($query));
        if ($q === '') {
            return true;
        }
        foreach (explode(',', $q) as $branch) {
            if ($this->matchesMediaQuery(trim($branch))) {
                return true;
            }
        }
        return false;
    }

    private function matchesMediaQuery(string $q): bool
    {
        if ($q === '') {
            return false;
        }
        $negate = false;
        if (str_starts_with($q, 'not ')) {
            $negate = true;
            $q = trim(substr($q, 4));
        } elseif (str_starts_with($q, 'only ')) {
            $q = trim(substr($q, 5));
        }

        $result = true;
        if (!str_starts_with($q, '(') && preg_match('/^([a-z-]+)\s*/', $q, $tm)) {
            if (!in_array($tm[1], ['all', 'print'], true)) {
                $result = false;
            }
            $q = (string) preg_replace('/^[a-z-]+\s*(and\s+)?/', '', $q);
        }

        if ($result && preg_match_all('/\(([^()]*)\)/', $q, $features)) {
            foreach ($features[1] as $feature) {
                if (!$this->matchesMediaFeature(trim($feature))) {
                    $result = false;
                    break;
                }
            }
        }

        return $negate ? !$result : $result;
    }

    private function matchesMediaFeature(string $feature): bool
    {
        // Range syntax: (width >= 600px)
        if (preg_match('/^([a-z-]+)\s*(<=|>=|<|>|=)\s*(.+)$/', $feature, $m)) {
            $actual = $this->mediaFeatureValue($m[1]);
            if ($actual === null) {
                return false;
            }
            $limit = CssUnit::toPt(trim($m[3]), 0.0, 12.0, 12.0);
            return match ($m[2]) {
                '<=' => $actual <= $limit + 0.01,
                '>=' => $actual >= $limit - 0.01,
                '<' => $actual < $limit,
                '>' => $actual > $limit,
                default => abs($actual - $limit) < 0.01,
            };
        }

        $parts = array_map('trim', explode(':', $feature, 2));
        $name = $parts[0];
        $value = $parts[1] ?? '';

        if (preg_match('/^(min|max)-(width|height)$/', $name, $mm)) {
            $actual = $this->mediaFeatureValue($mm[2]);
            $limit = CssUnit::toPt($value, 0.0, 12.0, 12.0);
            return $mm[1] === 'min' ? $actual >= $limit - 0.01 : $actual <= $limit + 0.01;
        }
        if ($name === 'width' || $name === 'height') {
            return $value === '' || abs((float) $this->mediaFeatureValue($name) - CssUnit::toPt($value, 0.0, 12.0, 12.0)) < 0.5;
        }

        return match ($name) {
            'orientation' => $value === ($this->mediaWidthPt > $this->mediaHeightPt ? 'landscape' : 'portrait'),
            'color', 'min-color', 'color-gamut', 'resolution', 'min-resolution', 'max-resolution', 'grid' => $name !== 'grid' || $value === '0',
            'prefers-color-scheme' => $value === 'light',
            'prefers-reduced-motion' => $value === 'reduce',
            'hover', 'any-hover' => $value === 'none',
            'pointer', 'any-pointer' => $value === 'none',
            'monochrome' => $value === '0',
            default => false,
        };
    }

    private function mediaFeatureValue(string $name): ?float
    {
        return match ($name) {
            'width', 'device-width' => $this->mediaWidthPt,
            'height', 'device-height' => $this->mediaHeightPt,
            default => null,
        };
    }

    private function registerRuleBlock(string $selectorGroup, string $declBlock): void
    {
        $decls = $this->parseDeclarations($declBlock);
        if (empty($decls)) {
            return;
        }

        foreach ($this->splitTopLevelComma($selectorGroup) as $rawSel) {
            $sel = trim((string) preg_replace('/\s+/', ' ', $rawSel));
            if ($sel === '') {
                continue;
            }

            // Generated content: `.x::before` / `.x:after` rules are stored separately for computePseudoStyle()
            $pseudo = null;
            if (preg_match('/::?(before|after|first-letter|first-line)\s*$/i', $sel, $pm) || preg_match('/::(marker)\s*$/i', $sel, $pm)) {
                $pseudo = strtolower($pm[1]);
                $this->pseudoSeen[$pseudo] = true;
                $sel = trim(substr($sel, 0, -strlen($pm[0])));
                if ($sel === '' || preg_match('/[\s>+~]$/', $sel)) {
                    $sel .= '*';
                }
            }

            $compiled = $this->compileSelector($sel);
            if ($compiled === null) {
                // Pseudo-elements, interactive states and unparseable selectors never match in print
                continue;
            }

            if ($sel === ':root' || strtolower($sel) === 'html') {
                foreach ($decls as $p => $info) {
                    if (str_starts_with($p, '--')) {
                        $this->rootVariables[$p] = $info['value'];
                    }
                }
            }

            $this->rules[] = [
                'selector' => $sel,
                'pseudo' => $pseudo,
                'compiled' => $compiled,
                'declarations' => $decls,
                'specificity' => $this->compiledSpecificity($compiled),
                'order' => $this->ruleCounter++,
            ];
        }
    }

    /**
     * @return array<string, string>
     */
    public function getPageProperties(): array
    {
        return $this->pageProps;
    }

    public function getPageRules(): \IvoPdf\Paged\PageRules
    {
        return $this->pageRules ??= new \IvoPdf\Paged\PageRules();
    }

    /**
     * @return array<string, string>
     */
    public function getFirstPageProperties(): array
    {
        return $this->firstPageProps;
    }

    /**
     * @return array<string, string>
     */
    public function getRootVariables(): array
    {
        return $this->rootVariables;
    }

    /**
     * @return list<array<string, string>>
     */
    public function getFontFaces(): array
    {
        return $this->fontFaces;
    }

    /**
     * Parses inline style="..." attribute or declaration block.
     *
     * @return array<string, array{value: string, important: bool}>
     */
    public function parseDeclarations(string $block): array
    {
        $result = [];
        foreach ($this->splitSemicolons($block) as $part) {
            $colonPos = strpos($part, ':');
            if ($colonPos === false) {
                continue;
            }
            $prop = strtolower(trim(substr($part, 0, $colonPos)));
            $val = trim(substr($part, $colonPos + 1));
            if ($prop === '' || $val === '') {
                continue;
            }

            $important = false;
            if (preg_match('/^(.*?)!\s*important$/is', $val, $m)) {
                $val = trim($m[1]);
                $important = true;
            }

            // Invalid value: the whole declaration is ignored, so an earlier valid one of the block survives
            if (!CssValidator::isValid($prop, $val)) {
                continue;
            }
            if ($prop === 'tab-size' && is_numeric($val) && (float) $val > 1000.0) {
                $val = '1000'; // `tab-size: 1e9` from untrusted CSS: no tab stop is ever wider than a thousand spaces
            }

            // A later normal declaration never overrides an earlier !important one of the same block
            if (isset($result[$prop]) && $result[$prop]['important'] && !$important) {
                continue;
            }
            // Re-insert so the declaration keeps its source position relative to other properties
            unset($result[$prop]);
            $result[$prop] = [
                'value' => $val,
                'important' => $important,
            ];
        }

        return $result;
    }

    /**
     * Resolves the ComputedStyle for a given DOMElement by cascading parent style, matching CSS rules,
     * HTML presentation attributes, and inline style attribute.
     */
    public function computeStyleForElement(DOMElement $el, ComputedStyle $parentStyle): ComputedStyle
    {
        \IvoPdf\RenderLimits::tick();
        $this->classSetElement = null; // an element's classes are read afresh for every cascade
        $tag = strtolower($el->tagName);

        // Sibling elements with the same tag and attributes under one parent get the same style unless a selector
        // looks at their position: the first result is kept as a prototype and cloned (clones share the property
        // arrays until written to), so 10 000 identical <td>/<br>/<li> cost one cascade and almost no memory.
        $memoKey = null;
        if ($el->hasAttribute('dir') || $tag === 'bdi') {
            // dir=auto reads the element's text
        } else {
            if ($this->styleMemoRules !== count($this->rules)) {
                $this->styleMemo = new \WeakMap();
                $this->positionRules = null;
                $this->tokenMemo = new \WeakMap();
                $this->styleMemoRules = count($this->rules);
            }
            $memoKey = $tag;
            if ($el->hasAttributes()) {
                foreach ($el->attributes as $attr) {
                    $memoKey .= "\0" . $attr->nodeName . "\1" . $attr->nodeValue;
                }
            }
            $hit = ($this->tokenMemo[$parentStyle->shareToken()] ?? [])[$memoKey] ?? ($this->styleMemo[$parentStyle] ?? [])[$memoKey] ?? null;
            if ($hit !== null) {
                // Table parts hand out the cached (shared, read-only) style itself: 10 000 identical cells keep one style
                return $hit->isShared() ? $hit : clone $hit;
            }
        }

        $style = $parentStyle->inheritForElement($tag);
        if ($tag === 'input') {
            \IvoPdf\Layout\FormControls::applyDefaults($style, 'input', $el->getAttribute('type'));
        }

        // Apply HTML presentation attributes (lowest priority author-level hints)
        $this->applyHtmlAttributes($el, $style);
        \IvoPdf\Text\DirAttribute::apply($el, $style, $tag);
        if ($el->hasAttribute('lang')) {
            $style->set('-ivopdf-lang', trim($el->getAttribute('lang')));
        }

        $matched = [];
        foreach ($this->candidateRules($el) as $rule) {
            if ($rule['pseudo'] === null && $this->matchesCompiled($el, $rule['compiled'], count($rule['compiled']) - 1)) {
                $matched[] = $rule;
            }
        }

        usort($matched, static function (array $a, array $b): int {
            if ($a['specificity'] !== $b['specificity']) {
                return $a['specificity'] <=> $b['specificity'];
            }
            return $a['order'] <=> $b['order'];
        });

        $importantDecls = [];
        foreach ($matched as $rule) {
            foreach ($rule['declarations'] as $prop => $info) {
                if ($info['important']) {
                    unset($importantDecls[$prop]);
                    $importantDecls[$prop] = $info['value'];
                } else {
                    $style->set($prop, $info['value']);
                }
            }
        }

        if ($el->hasAttribute('style')) {
            foreach ($this->parseDeclarations($el->getAttribute('style')) as $prop => $info) {
                if ($info['important']) {
                    unset($importantDecls[$prop]);
                    $importantDecls[$prop] = $info['value'];
                } else {
                    $style->set($prop, $info['value']);
                }
            }
        }

        foreach ($importantDecls as $prop => $val) {
            $style->set($prop, $val);
        }

        \IvoPdf\Text\DirAttribute::finish($style, $tag);

        if ($memoKey !== null) {
            $freedom = $this->positionFreedom($el);
            if ($freedom === 2) {
                // Position-free through the whole ancestor chain: every element with the same ancestor tags and
                // attributes (not just the siblings) gets this style, so the entries hang off the parent's share token
                $style->shareToken();
                $memo = $this->tokenMemo;
                $memoParent = $parentStyle->shareToken();
            } else {
                $memo = $freedom === 1 ? $this->styleMemo : null;
                $memoParent = $parentStyle;
            }
            if ($memo !== null) {
                $entries = $memo[$memoParent] ?? [];
                if (count($entries) < 256) {
                    $proto = clone $style;
                    $entries[$memoKey] = $proto;
                    $memo[$memoParent] = $entries;
                    if (($sharing = self::SHARED_STYLE_TAGS[$tag] ?? 0) === 2 || ($sharing === 1 && !$this->hasPositionRules())) {
                        return $proto->share(); // the first cell already uses the shared object
                    }
                }
            }
        }

        return $style;
    }

    /** Whether any rule of the stylesheet depends on the position of an element (sibling combinators, structural pseudo-classes). */
    private function hasPositionRules(): bool
    {
        if ($this->positionRules === null) {
            $this->positionRules = false;
            foreach ($this->rules as $rule) {
                if ($this->ruleFreedom($rule) < 2) {
                    $this->positionRules = true;
                    break;
                }
            }
        }
        return $this->positionRules;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function ruleFreedom(array $rule): int
    {
        $order = $rule['order'];
        if (!isset($this->positionFreeRules[$order])) {
            $count = count($rule['compiled']);
            $freedom = 2;
            foreach ($rule['compiled'] as $i => $part) {
                if ($i % 2 === 1) {
                    if ($part === '+' || $part === '~') {
                        $freedom = 0;
                        break;
                    }
                } else {
                    foreach ($part['pseudos'] as [$name]) {
                        if (!in_array($name, self::NEVER_MATCHING_PSEUDOS, true)) {
                            if ($i === $count - 1) {
                                $freedom = 0;
                                break 2;
                            }
                            $freedom = 1;
                        }
                    }
                }
            }
            $this->positionFreeRules[$order] = $freedom;
        }
        return $this->positionFreeRules[$order];
    }

    /**
     * 2 when no rule that can apply to $el depends on the position of the element or of one of its ancestors among
     * their siblings (sibling combinators, structural pseudo-classes anywhere in the selector): elements with the same
     * ancestor tags and attributes always get identical styles. 1 when only the element's own sibling position is
     * irrelevant (identical siblings get identical styles). 0 otherwise.
     */
    private function positionFreedom(DOMElement $el): int
    {
        $level = 2;
        foreach ($this->candidateRules($el) as $rule) {
            $level = min($level, $this->ruleFreedom($rule));
            if ($level === 0) {
                return 0;
            }
        }
        return $level;
    }

    /**
     * Rules that can possibly match $el: those whose rightmost compound requires the element's id, one of its classes,
     * its tag name, or nothing specific. Avoids running every selector of a large stylesheet against every element.
     *
     * @return list<array<string, mixed>>
     */
    private function candidateRules(DOMElement $el): array
    {
        if ($this->ruleIndex === null || $this->ruleIndexCount !== count($this->rules)) {
            $idx = ['id' => [], 'class' => [], 'tag' => [], 'any' => []];
            foreach ($this->rules as $i => $rule) {
                $last = $rule['compiled'][count($rule['compiled']) - 1];
                if (!empty($last['ids'])) {
                    $idx['id'][$last['ids'][0]][] = $i;
                } elseif (!empty($last['classes'])) {
                    $idx['class'][$last['classes'][0]][] = $i;
                } elseif ($last['tag'] !== '*') {
                    $idx['tag'][$last['tag']][] = $i;
                } else {
                    $idx['any'][] = $i;
                }
            }
            $this->ruleIndex = $idx;
            $this->ruleIndexCount = count($this->rules);
        }
        $ix = $this->ruleIndex;
        // Lists are merged once at the end: merging per class would copy the growing list for every class
        // (an element with 20 000 classes, each with a rule, was quadratic)
        $parts = [$ix['any']];
        $tagKeys = $ix['tag'][strtolower($el->tagName)] ?? null;
        if ($tagKeys !== null) {
            $parts[] = $tagKeys;
        }
        if ($ix['id'] !== []) {
            $elId = $el->getAttribute('id');
            if ($elId !== '' && isset($ix['id'][$elId])) {
                $parts[] = $ix['id'][$elId];
            }
        }
        if ($ix['class'] !== []) {
            $classAttr = $el->getAttribute('class');
            if ($classAttr !== '') {
                foreach (array_unique(preg_split('/\s+/', $classAttr, -1, PREG_SPLIT_NO_EMPTY) ?: []) as $cls) {
                    if (isset($ix['class'][$cls])) {
                        $parts[] = $ix['class'][$cls];
                    }
                }
            }
        }
        $keys = count($parts) === 1 ? $parts[0] : array_merge(...$parts);
        $out = [];
        foreach ($keys as $i) {
            $out[] = $this->rules[$i];
        }
        return $out;
    }

    /**
     * Whether any rule targets the pseudo-element (before/after/first-letter/first-line/marker).
     */
    public function hasPseudoRules(string $pseudo): bool
    {
        return isset($this->pseudoSeen[$pseudo]);
    }

    /**
     * Names of the properties declared by the rules matching an element's pseudo-element.
     *
     * @return list<string>
     */
    public function pseudoDeclaredProps(DOMElement $el, string $pseudo): array
    {
        $props = [];
        foreach ($this->candidateRules($el) as $rule) {
            if ($rule['pseudo'] === $pseudo && $this->matchesCompiled($el, $rule['compiled'], count($rule['compiled']) - 1)) {
                foreach ($rule['declarations'] as $prop => $_) {
                    $props[$prop] = true;
                }
            }
        }
        return array_keys($props);
    }

    /**
     * Computes the style of an element's ::before / ::after box, or null when it generates no content.
     */
    public function computePseudoStyle(DOMElement $el, ComputedStyle $elementStyle, string $pseudo, bool $requireContent = true): ?ComputedStyle
    {
        $matched = [];
        foreach ($this->candidateRules($el) as $rule) {
            if ($rule['pseudo'] === $pseudo && $this->matchesCompiled($el, $rule['compiled'], count($rule['compiled']) - 1)) {
                $matched[] = $rule;
            }
        }
        if (empty($matched)) {
            return null;
        }
        usort($matched, static fn(array $a, array $b): int => [$a['specificity'], $a['order']] <=> [$b['specificity'], $b['order']]);

        $style = $elementStyle->inheritForElement('span');
        $important = [];
        foreach ($matched as $rule) {
            foreach ($rule['declarations'] as $prop => $info) {
                if ($info['important']) {
                    $important[$prop] = $info['value'];
                } else {
                    $style->set($prop, $info['value']);
                }
            }
        }
        foreach ($important as $prop => $val) {
            $style->set($prop, $val);
        }

        $content = strtolower(trim($style->get('content', 'none')));
        if ($requireContent && ($content === '' || $content === 'none' || $content === 'normal' || strtolower($style->get('display', 'inline')) === 'none')) {
            return null;
        }
        return $style;
    }

    private function applyHtmlAttributes(DOMElement $el, ComputedStyle $style): void
    {
        $tag = strtolower($el->tagName);

        if ($el->hasAttribute('hidden')) {
            $style->set('display', 'none');
        }

        if ($el->hasAttribute('type') && ($tag === 'ol' || $tag === 'ul' || $tag === 'li')) {
            // <ol type="a|A|i|I|1"> / <ul type="disc|circle|square"> / <li type>: presentational hint for list-style-type
            $t = trim($el->getAttribute('type'));
            $mapped = match (true) {
                $t === 'a' => 'lower-alpha',
                $t === 'A' => 'upper-alpha',
                $t === 'i' => 'lower-roman',
                $t === 'I' => 'upper-roman',
                $t === '1' => 'decimal',
                in_array(strtolower($t), ['disc', 'circle', 'square'], true) => strtolower($t),
                default => null,
            };
            if ($mapped !== null) {
                $style->set('list-style-type', $mapped);
            }
        }

        if ($el->hasAttribute('align')) {
            $align = strtolower(trim($el->getAttribute('align')));
            if ($tag === 'img' && ($align === 'left' || $align === 'right')) {
                $style->set('float', $align);
            } elseif (in_array($align, ['left', 'center', 'right', 'justify'], true)) {
                if ($tag === 'table' && $align === 'center') {
                    $style->set('margin-left', 'auto');
                    $style->set('margin-right', 'auto');
                } elseif ($tag === 'table' && ($align === 'left' || $align === 'right')) {
                    $style->set('float', $align); // like browsers: a legacy aligned table floats
                } elseif ($align !== 'justify' && !in_array($tag, ['td', 'th', 'tr', 'thead', 'tbody', 'tfoot', 'caption', 'col', 'colgroup', 'img'], true)) {
                    $style->set('text-align', '-webkit-' . $align); // block-level align=: also moves shrink-wrapped children (tables)
                } else {
                    $style->set('text-align', $align);
                }
            }
        }

        if ($el->hasAttribute('valign')) {
            $valign = strtolower(trim($el->getAttribute('valign')));
            if (in_array($valign, ['top', 'middle', 'bottom', 'baseline'], true)) {
                $style->set('vertical-align', $valign);
            }
        }

        if ($el->hasAttribute('bgcolor')) {
            $style->set('background-color', trim($el->getAttribute('bgcolor')));
        }

        if ($el->hasAttribute('color') && $tag !== 'barcode') {
            $style->set('color', trim($el->getAttribute('color')));
        }

        if ($tag === 'font') {
            if ($el->hasAttribute('face')) {
                $style->set('font-family', trim($el->getAttribute('face')));
            }
            if ($el->hasAttribute('size')) {
                $sz = \IvoPdf\Dom\InputGuards::intAttr($el->getAttribute('size'), -1000, 1000, 0);
                $pt = match ($sz) {
                    1 => '8pt',
                    2 => '10pt',
                    3 => '12pt',
                    4 => '14pt',
                    5 => '18pt',
                    6 => '24pt',
                    7 => '36pt',
                    default => '12pt',
                };
                $style->set('font-size', $pt);
            }
        }

        if ($tag !== 'svg' && $el->hasAttribute('width')) {
            $w = trim($el->getAttribute('width'));
            if ($w !== '') {
                $style->set('width', is_numeric($w) ? "{$w}px" : $w);
            }
        }

        if ($tag !== 'svg' && $el->hasAttribute('height')) {
            $h = trim($el->getAttribute('height'));
            if ($h !== '') {
                $style->set('height', is_numeric($h) ? "{$h}px" : $h);
            }
        }

        if ($tag === 'table') {
            if ($el->hasAttribute('border')) {
                $b = trim($el->getAttribute('border'));
                if ($b !== '' && $b !== '0') {
                    $bw = is_numeric($b) ? "{$b}px" : $b;
                    $style->set('border', "{$bw} outset gray"); // browsers / dompdf: table[border] { border: Npx outset gray }
                }
            }
            if ($el->hasAttribute('cellspacing')) {
                $cs = trim($el->getAttribute('cellspacing'));
                if ($cs !== '') {
                    $style->set('border-spacing', is_numeric($cs) ? "{$cs}px" : $cs);
                }
            }
        }

        if ($tag === 'td' || $tag === 'th') {
            $parentTable = $this->findAncestorTable($el);
            if ($parentTable !== null && $parentTable->hasAttribute('cellpadding')) {
                $cp = trim($parentTable->getAttribute('cellpadding'));
                if ($cp !== '') {
                    $style->set('padding', is_numeric($cp) ? "{$cp}px" : $cp);
                }
            }
            if ($parentTable !== null && $parentTable->hasAttribute('border')) {
                $b = trim($parentTable->getAttribute('border'));
                if ($b !== '' && $b !== '0') {
                    $style->set('border', '1px inset gray'); // cells of a table[border]
                }
            }
            if ($el->hasAttribute('nowrap')) {
                $style->set('white-space', 'nowrap');
            }
        }
    }

    private function findAncestorTable(DOMElement $el): ?DOMElement
    {
        $p = $el->parentNode;
        while ($p instanceof DOMElement) {
            if (strtolower($p->tagName) === 'table') {
                return $p;
            }
            $p = $p->parentNode;
        }
        return null;
    }

    /**
     * Matches a full CSS selector (descendant ` `, child `>`, adjacent `+`, general sibling `~`) against a DOMElement.
     */
    public function matchesSelector(DOMElement $el, string $selector): bool
    {
        foreach ($this->splitTopLevelComma($selector) as $single) {
            $compiled = $this->compileSelector(trim($single));
            if ($compiled !== null && $this->matchesCompiled($el, $compiled, count($compiled) - 1)) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Selector compilation
    // ------------------------------------------------------------------

    /**
     * Compiles a complex selector into [compound, combinator, compound, ...] or null when it can never match.
     *
     * @return list<mixed>|null
     */
    /** Deepest nesting of functional pseudo-classes (`:not(:is(...))`) a selector may have before it is ignored */
    private const MAX_SELECTOR_NESTING = 32;

    private function compileSelector(string $sel): ?array
    {
        if (array_key_exists($sel, $this->compiledCache)) {
            return $this->compiledCache[$sel];
        }

        $parts = [];
        $current = '';
        $len = strlen($sel);
        $depthParen = 0;
        $depthBracket = 0;
        $quote = '';
        $pendingCombinator = null;

        $flushCompound = function () use (&$parts, &$current, &$pendingCombinator): bool {
            $text = trim($current);
            $current = '';
            if ($text === '') {
                return true;
            }
            $compound = $this->parseCompound($text);
            if ($compound === null) {
                return false;
            }
            if (!empty($parts)) {
                $parts[] = $pendingCombinator ?? ' ';
            }
            $parts[] = $compound;
            $pendingCombinator = null;
            return true;
        };

        for ($i = 0; $i < $len; $i++) {
            $ch = $sel[$i];
            if ($quote !== '') {
                $current .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $current .= $sel[++$i];
                } elseif ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $current .= $ch;
                continue;
            }
            if ($ch === '\\' && $i + 1 < $len) {
                $current .= $ch . $sel[++$i];
                continue;
            }
            if ($ch === '(') {
                if (++$depthParen > self::MAX_SELECTOR_NESTING) {
                    // :not(:not(:not(... thousands deep: every level would re-parse and re-match the rest (quadratic);
                    // no real stylesheet nests functional pseudo-classes this deep, so the rule is dropped
                    return $this->compiledCache[$sel] = null;
                }
            } elseif ($ch === ')') {
                $depthParen = max(0, $depthParen - 1);
            } elseif ($ch === '[') {
                $depthBracket++;
            } elseif ($ch === ']') {
                $depthBracket = max(0, $depthBracket - 1);
            }

            if ($depthParen === 0 && $depthBracket === 0 && ($ch === '>' || $ch === '+' || $ch === '~' || ctype_space($ch))) {
                if (!$flushCompound()) {
                    return $this->compiledCache[$sel] = null;
                }
                if (!ctype_space($ch)) {
                    $pendingCombinator = $ch;
                }
                continue;
            }
            $current .= $ch;
        }
        if (!$flushCompound() || empty($parts)) {
            return $this->compiledCache[$sel] = null;
        }

        return $this->compiledCache[$sel] = $parts;
    }

    /**
     * Parses a compound selector like `a.btn#main[href^="http"]:not(.x)` into its parts.
     *
     * @return array{tag: string, ids: list<string>, classes: list<string>, attrs: list<array{0: string, 1: string, 2: string, 3: bool}>, pseudos: list<array{0: string, 1: string}>}|null
     */
    private function parseCompound(string $text): ?array
    {
        $compound = ['tag' => '*', 'ids' => [], 'classes' => [], 'attrs' => [], 'pseudos' => []];
        $len = strlen($text);
        $i = 0;

        while ($i < $len) {
            $ch = $text[$i];
            if ($ch === '*') {
                $i++;
            } elseif ($ch === '#' || $ch === '.') {
                $i++;
                $ident = $this->readIdent($text, $i);
                if ($ident === '') {
                    return null;
                }
                if ($ch === '#') {
                    $compound['ids'][] = $ident;
                } else {
                    $compound['classes'][] = $ident;
                }
            } elseif ($ch === '[') {
                $end = $this->findClosing($text, $i, '[', ']');
                if ($end === null) {
                    return null;
                }
                $attr = $this->parseAttributeSelector(substr($text, $i + 1, $end - $i - 1));
                if ($attr === null) {
                    return null;
                }
                $compound['attrs'][] = $attr;
                $i = $end + 1;
            } elseif ($ch === ':') {
                $i++;
                if ($i < $len && $text[$i] === ':') {
                    return null; // pseudo-element
                }
                $name = strtolower($this->readIdent($text, $i));
                if ($name === '' || in_array($name, self::NEVER_MATCHING_PSEUDOS, true)) {
                    return null;
                }
                $arg = '';
                if ($i < $len && $text[$i] === '(') {
                    $end = $this->findClosing($text, $i, '(', ')');
                    if ($end === null) {
                        return null;
                    }
                    $arg = trim(substr($text, $i + 1, $end - $i - 1));
                    $i = $end + 1;
                }
                $compound['pseudos'][] = [$name, $arg];
            } else {
                $ident = $this->readIdent($text, $i);
                if ($ident === '') {
                    return null;
                }
                $compound['tag'] = strtolower($ident);
            }
        }

        return $compound;
    }

    private function readIdent(string $text, int &$i): string
    {
        $out = '';
        $len = strlen($text);
        while ($i < $len) {
            $ch = $text[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $out .= $text[$i + 1];
                $i += 2;
                continue;
            }
            if (ctype_alnum($ch) || $ch === '-' || $ch === '_' || ord($ch) >= 0x80) {
                $out .= $ch;
                $i++;
                continue;
            }
            break;
        }
        return $out;
    }

    private function findClosing(string $text, int $openPos, string $open, string $close): ?int
    {
        $depth = 0;
        $quote = '';
        $len = strlen($text);
        for ($i = $openPos; $i < $len; $i++) {
            $ch = $text[$i];
            if ($quote !== '') {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === $open) {
                $depth++;
            } elseif ($ch === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: bool}|null [name, operator, value, caseInsensitive]
     */
    private function parseAttributeSelector(string $expr): ?array
    {
        $expr = trim($expr);
        if (preg_match('/^([a-zA-Z0-9_:-]+)\s*$/', $expr, $m)) {
            return [strtolower($m[1]), '', '', false];
        }
        if (!preg_match('/^([a-zA-Z0-9_:-]+)\s*([~|^$*]?=)\s*(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|([^\s]+))\s*([iIsS])?$/', $expr, $m)) {
            return null;
        }
        $value = $m[3] !== '' ? $m[3] : (($m[4] ?? '') !== '' ? $m[4] : ($m[5] ?? ''));
        $value = stripslashes($value);
        return [strtolower($m[1]), $m[2], $value, strtolower($m[6] ?? '') === 'i'];
    }

    /**
     * @param list<mixed> $compiled
     */
    private function compiledSpecificity(array $compiled): int
    {
        $total = 0;
        foreach ($compiled as $part) {
            if (is_array($part)) {
                $total += $this->compoundSpecificity($part);
            }
        }
        return $total;
    }

    /**
     * @param array{tag: string, ids: list<string>, classes: list<string>, attrs: list<mixed>, pseudos: list<array{0: string, 1: string}>} $compound
     */
    private function compoundSpecificity(array $compound): int
    {
        $spec = count($compound['ids']) * 10000 + (count($compound['classes']) + count($compound['attrs'])) * 100;
        if ($compound['tag'] !== '*') {
            $spec += 1;
        }
        foreach ($compound['pseudos'] as [$name, $arg]) {
            if ($name === 'where') {
                continue;
            }
            if (in_array($name, ['not', 'is', 'matches', 'any', '-webkit-any', 'has'], true)) {
                $max = 0;
                foreach ($this->splitTopLevelComma($arg) as $inner) {
                    $c = $this->compileSelector(ltrim(trim($inner), '>+~ '));
                    if ($c !== null) {
                        $max = max($max, $this->compiledSpecificity($c));
                    }
                }
                $spec += $max;
                continue;
            }
            $spec += 100;
        }
        return $spec;
    }

    // ------------------------------------------------------------------
    // Selector matching
    // ------------------------------------------------------------------

    /**
     * Matches compiled parts[0..$idx] with $el as the subject of parts[$idx] (backtracking over ancestors/siblings).
     *
     * @param list<mixed> $parts
     */
    /** element whose class set is in $classSet (see matchesCompound()) */
    private ?DOMElement $classSetElement = null;
    /** @var array<string, int> */
    private array $classSet = [];

    private function matchesCompiled(DOMElement $el, array $parts, int $idx): bool
    {
        if (!$this->matchesCompound($el, $parts[$idx])) {
            return false;
        }
        if ($idx === 0) {
            return true;
        }

        $combinator = $parts[$idx - 1];
        $prevIdx = $idx - 2;

        switch ($combinator) {
            case '>':
                $parent = $el->parentNode;
                return $parent instanceof DOMElement && $this->matchesCompiled($parent, $parts, $prevIdx);
            case '+':
                $prev = $this->getPreviousElementSibling($el);
                return $prev !== null && $this->matchesCompiled($prev, $parts, $prevIdx);
            case '~':
                $prev = $this->getPreviousElementSibling($el);
                while ($prev !== null) {
                    if ($this->matchesCompiled($prev, $parts, $prevIdx)) {
                        return true;
                    }
                    $prev = $this->getPreviousElementSibling($prev);
                }
                return false;
            default:
                $ancestor = $el->parentNode;
                while ($ancestor instanceof DOMElement) {
                    if ($this->matchesCompiled($ancestor, $parts, $prevIdx)) {
                        return true;
                    }
                    $ancestor = $ancestor->parentNode;
                }
                return false;
        }
    }

    /**
     * @param array{tag: string, ids: list<string>, classes: list<string>, attrs: list<array{0: string, 1: string, 2: string, 3: bool}>, pseudos: list<array{0: string, 1: string}>} $c
     */
    private function matchesCompound(DOMElement $el, array $c): bool
    {
        if ($c['tag'] !== '*' && strtolower($el->tagName) !== $c['tag']) {
            return false;
        }

        if (!empty($c['ids'])) {
            $elId = $el->getAttribute('id');
            foreach ($c['ids'] as $reqId) {
                if ($elId !== $reqId) {
                    return false;
                }
            }
        }

        if (!empty($c['classes'])) {
            // The class set of the element is built once and looked up by key: reading, splitting and scanning a class
            // attribute of 20 000 names for every rule that names one of them was quadratic. The element is held, so
            // its object id cannot be reused by another element while it is the cached one.
            if ($el !== $this->classSetElement) {
                $this->classSetElement = $el;
                $this->classSet = array_flip(preg_split('/\s+/', trim($el->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            }
            if ($this->classSet === []) {
                return false;
            }
            foreach ($c['classes'] as $reqClass) {
                if (!isset($this->classSet[$reqClass])) {
                    return false;
                }
            }
        }

        foreach ($c['attrs'] as [$name, $op, $expected, $ci]) {
            if (!$el->hasAttribute($name)) {
                return false;
            }
            if ($op === '') {
                continue;
            }
            $actual = $el->getAttribute($name);
            if ($ci) {
                $actual = mb_strtolower($actual, 'UTF-8');
                $expected = mb_strtolower($expected, 'UTF-8');
            }
            $ok = match ($op) {
                '=' => $actual === $expected,
                '^=' => $expected !== '' && str_starts_with($actual, $expected),
                '$=' => $expected !== '' && str_ends_with($actual, $expected),
                '*=' => $expected !== '' && str_contains($actual, $expected),
                '~=' => in_array($expected, preg_split('/\s+/', trim($actual), -1, PREG_SPLIT_NO_EMPTY) ?: [], true),
                '|=' => $actual === $expected || str_starts_with($actual, $expected . '-'),
                default => false,
            };
            if (!$ok) {
                return false;
            }
        }

        foreach ($c['pseudos'] as [$name, $arg]) {
            if (!$this->matchesPseudoClass($el, $name, $arg)) {
                return false;
            }
        }

        return true;
    }

    private function matchesPseudoClass(DOMElement $el, string $name, string $arg): bool
    {
        switch ($name) {
            case 'not':
                return $arg !== '' && !$this->matchesSelector($el, $arg);
            case 'is':
            case 'where':
            case 'matches':
            case 'any':
            case '-webkit-any':
                return $arg !== '' && $this->matchesSelector($el, $arg);
            case 'has':
                return $this->matchesHas($el, $arg);
            case 'root':
                return strtolower($el->tagName) === 'html' || !($el->parentNode instanceof DOMElement);
            case 'empty':
                foreach ($el->childNodes as $child) {
                    if ($child instanceof DOMElement) {
                        return false;
                    }
                    if (($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) && (string) $child->nodeValue !== '') {
                        return false;
                    }
                }
                return true;
            case 'link':
            case 'any-link':
                return in_array(strtolower($el->tagName), ['a', 'area'], true) && $el->hasAttribute('href');
            case 'checked':
                return $el->hasAttribute('checked') || $el->hasAttribute('selected');
            case 'disabled':
                return $el->hasAttribute('disabled');
            case 'enabled':
                return !$el->hasAttribute('disabled');
            case 'required':
                return $el->hasAttribute('required');
            case 'optional':
                return !$el->hasAttribute('required');
            case 'lang':
                return $this->matchesLang($el, strtolower(trim($arg, " \t'\"")));
            case 'dir':
                $want = strtolower(trim($arg));
                return ($want === 'ltr' || $want === 'rtl') && $this->resolvedDir($el) === $want;
        }

        $argLower = strtolower(trim($arg));
        if (in_array($name, ['first-of-type', 'last-of-type', 'only-of-type', 'nth-of-type', 'nth-last-of-type'], true)) {
            [$typeIdx, $typeTotal] = $this->getSameTagPosition($el);
            return match ($name) {
                'first-of-type' => $typeIdx === 1,
                'last-of-type' => $typeIdx === $typeTotal,
                'only-of-type' => $typeTotal === 1,
                'nth-of-type' => $this->matchesNth($typeIdx, $argLower),
                'nth-last-of-type' => $this->matchesNth($typeTotal - $typeIdx + 1, $argLower),
                default => false,
            };
        }

        if (!in_array($name, ['first-child', 'last-child', 'only-child', 'nth-child', 'nth-last-child'], true)) {
            return false;
        }

        $index = $this->getElementChildIndex($el);
        $total = $this->getElementSiblingCount($el);

        return match ($name) {
            'first-child' => $index === 1,
            'last-child' => $index === $total,
            'only-child' => $total === 1,
            'nth-child' => $this->matchesNth($index, $argLower),
            'nth-last-child' => $this->matchesNth($total - $index + 1, $argLower),
            default => false,
        };
    }

    /**
     * :has() with relative selectors (`:has(img)`, `:has(> .x)`, `:has(+ p)`, `:has(~ p)`).
     */
    private function matchesHas(DOMElement $el, string $arg): bool
    {
        foreach ($this->splitTopLevelComma($arg) as $rel) {
            $rel = trim($rel);
            $mode = ' ';
            if ($rel !== '' && in_array($rel[0], ['>', '+', '~'], true)) {
                $mode = $rel[0];
                $rel = trim(substr($rel, 1));
            }
            if ($rel === '') {
                continue;
            }
            $candidates = [];
            if ($mode === '>') {
                foreach ($el->childNodes as $ch) {
                    if ($ch instanceof DOMElement) {
                        $candidates[] = $ch;
                    }
                }
            } elseif ($mode === '+' || $mode === '~') {
                $next = $el->nextSibling;
                while ($next !== null) {
                    if ($next instanceof DOMElement) {
                        $candidates[] = $next;
                        if ($mode === '+') {
                            break;
                        }
                    }
                    $next = $next->nextSibling;
                }
            } else {
                // Cursor walk in document order: iterating getElementsByTagName('*') is quadratic before PHP 8.3
                $desc = $el->firstElementChild;
                while ($desc !== null) {
                    $candidates[] = $desc;
                    $next = $desc->firstElementChild;
                    while ($next === null && $desc !== null && $desc !== $el) {
                        $next = $desc->nextElementSibling;
                        $desc = $next === null ? $desc->parentNode : $desc;
                    }
                    $desc = $next;
                }
            }
            foreach ($candidates as $cand) {
                if ($this->matchesSelector($cand, $rel)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Directionality for :dir(): the nearest `dir` attribute on the element or an ancestor (`auto` = first strong
     * character of the element's text, ltr when there is none); `<bdi>` defaults to auto; otherwise ltr.
     */
    private function resolvedDir(DOMElement $el): string
    {
        for ($node = $el; $node instanceof DOMElement; $node = $node->parentNode) {
            $tag = strtolower($node->tagName);
            $dir = $node->hasAttribute('dir') ? strtolower(trim($node->getAttribute('dir'))) : ($tag === 'bdi' ? 'auto' : '');
            if ($dir === 'ltr' || $dir === 'rtl') {
                return $dir;
            }
            if ($dir === 'auto') {
                return \IvoPdf\Text\Bidi::firstStrong($node->textContent) === 1 ? 'rtl' : 'ltr';
            }
        }
        return 'ltr';
    }

    private function matchesLang(DOMElement $el, string $lang): bool
    {
        $node = $el;
        while ($node instanceof DOMElement) {
            if ($node->hasAttribute('lang')) {
                $actual = strtolower($node->getAttribute('lang'));
                return $actual === $lang || str_starts_with($actual, $lang . '-');
            }
            $node = $node->parentNode;
        }
        return false;
    }

    /**
     * @return array{0: int, 1: int} [1-based index among same-tag siblings, total same-tag siblings]
     */
    private function getSameTagPosition(DOMElement $el): array
    {
        $tag = strtolower($el->tagName);
        $parent = $el->parentNode;
        if ($parent === null) {
            return [1, 1];
        }
        $idx = 0;
        $total = 0;
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === $tag) {
                $total++;
                if ($child->isSameNode($el)) {
                    $idx = $total;
                }
            }
        }
        return [max(1, $idx), max(1, $total)];
    }

    private function matchesNth(int $index, string $arg): bool
    {
        // Ignore the optional "of S" filter (treated as unfiltered)
        $arg = trim((string) preg_replace('/\s+of\s+.*$/', '', $arg));
        if ($arg === 'odd') {
            return ($index % 2) === 1;
        }
        if ($arg === 'even') {
            return ($index % 2) === 0;
        }
        if (is_numeric($arg)) {
            return $index === (int) $arg;
        }
        if (preg_match('/^([+-]?\d*)n(?:\s*([+-])\s*(\d+))?$/', str_replace(' ', '', $arg), $m)) {
            $aStr = $m[1];
            $a = ($aStr === '' || $aStr === '+') ? 1 : (($aStr === '-') ? -1 : (int) $aStr);
            $b = isset($m[3]) ? ((($m[2] ?? '+') === '-') ? -(int) $m[3] : (int) $m[3]) : 0;
            if ($a === 0) {
                return $index === $b;
            }
            $diff = $index - $b;
            if (($a > 0 && $diff < 0) || ($a < 0 && $diff > 0)) {
                return false;
            }
            return ($diff % $a) === 0;
        }
        return false;
    }

    private function getElementChildIndex(DOMElement $el): int
    {
        $idx = 1;
        $prev = $el->previousSibling;
        while ($prev !== null) {
            if ($prev instanceof DOMElement) {
                $idx++;
            }
            $prev = $prev->previousSibling;
        }
        return $idx;
    }

    private function getElementSiblingCount(DOMElement $el): int
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return 1;
        }
        $count = 0;
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $count++;
            }
        }
        return max(1, $count);
    }

    private function getPreviousElementSibling(DOMElement $el): ?DOMElement
    {
        $prev = $el->previousSibling;
        while ($prev !== null) {
            if ($prev instanceof DOMElement) {
                return $prev;
            }
            $prev = $prev->previousSibling;
        }
        return null;
    }

    /**
     * @return list<string>
     */
    private function splitTopLevelComma(string $str): array
    {
        $result = [];
        $current = '';
        $depth = 0;
        $quote = '';
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $ch = $str[$i];
            if ($quote !== '') {
                $current .= $ch;
                if ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $current .= $ch;
            } elseif ($ch === '(' || $ch === '[') {
                $depth++;
                $current .= $ch;
            } elseif ($ch === ')' || $ch === ']') {
                $depth = max(0, $depth - 1);
                $current .= $ch;
            } elseif ($ch === ',' && $depth === 0) {
                if (trim($current) !== '') {
                    $result[] = trim($current);
                }
                $current = '';
            } else {
                $current .= $ch;
            }
        }

        if (trim($current) !== '') {
            $result[] = trim($current);
        }

        return $result;
    }

    /**
     * Splits declarations by semicolon while respecting parentheses and quoted strings.
     *
     * @return list<string>
     */
    private function splitSemicolons(string $block): array
    {
        $result = [];
        $current = '';
        $depth = 0;
        $quote = '';
        $len = strlen($block);

        for ($i = 0; $i < $len; $i++) {
            $ch = $block[$i];
            if ($quote !== '') {
                $current .= $ch;
                if ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $current .= $ch;
            } elseif ($ch === '(') {
                $depth++;
                $current .= $ch;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
                $current .= $ch;
            } elseif ($ch === ';' && $depth === 0) {
                if (trim($current) !== '') {
                    $result[] = trim($current);
                }
                $current = '';
            } else {
                $current .= $ch;
            }
        }

        if (trim($current) !== '') {
            $result[] = trim($current);
        }

        return $result;
    }
}
