<?php

declare(strict_types=1);

namespace IvoPdf\Css;

/**
 * Holds computed CSS properties for a node, expanding shorthands and managing inheritance.
 */
class ComputedStyle
{
    /** @var array<string, string> */
    private array $props = [];

    /** @var array<string, string> Parent properties for `inherit` keyword resolution */
    private array $parentProps = [];

    /** @var array<string, string> CSS custom properties (--var-name) */
    private array $variables = [];

    private float $parentFontSizePt = 12.0;
    private float $rootFontSizePt = 12.0;
    private float $computedFontSizePt = 12.0;
    private string $defaultBoxSizing = 'content-box';

    /** @var array<string, string>|null Cached initial values used for the `initial` / `unset` keywords */
    private static ?array $initialValues = null;

    private const INHERITED_PROPS = [
        'color',
        'font-family',
        'font-size',
        'font-weight',
        'font-style',
        'line-height',
        'letter-spacing',
        'text-align',
        'text-decoration',
        'text-transform',
        'text-indent',
        'white-space',
        'word-break',
        'overflow-wrap',
        'line-break',
        'text-justify',
        'visibility',
        'list-style-type',
        'list-style-position',
        'border-collapse',
        'border-spacing',
        'word-spacing',
        'list-style-image',
        'cursor',
        'direction',
        'hyphens',
        'hyphenate-character',
        'hyphenate-limit-chars',
        '-ivopdf-lang',
        'tab-size',
        'font-feature-settings',
        'font-kerning',
        'font-variant',
        'font-variant-ligatures',
        'font-variant-caps',
        'font-variant-numeric',
        'text-shadow',
    ];

    /**
     * @param array<string, string> $initialProps
     * @param array<string, string> $variables
     */
    public function __construct(
        array $initialProps = [],
        array $variables = [],
        float $parentFontSizePt = 12.0,
        float $rootFontSizePt = 12.0,
        string $defaultBoxSizing = 'content-box'
    ) {
        $this->variables = $variables;
        $this->parentFontSizePt = $parentFontSizePt;
        $this->rootFontSizePt = $rootFontSizePt;
        $this->computedFontSizePt = $parentFontSizePt;
        $this->defaultBoxSizing = $defaultBoxSizing;
        $this->setDefaults($defaultBoxSizing);
        foreach ($initialProps as $k => $v) {
            $this->set($k, $v);
        }
    }

    private function setDefaults(string $defaultBoxSizing = 'content-box'): void
    {
        $fsStr = round($this->computedFontSizePt, 4) . 'pt';
        $this->props = [
            'display' => 'block',
            'position' => 'static',
            'float' => 'none',
            'clear' => 'none',
            'box-sizing' => $defaultBoxSizing,
            'width' => 'auto',
            'height' => 'auto',
            'min-width' => '0',
            'max-width' => 'none',
            'min-height' => '0',
            'max-height' => 'none',

            'margin-top' => '0',
            'margin-right' => '0',
            'margin-bottom' => '0',
            'margin-left' => '0',

            'padding-top' => '0',
            'padding-right' => '0',
            'padding-bottom' => '0',
            'padding-left' => '0',

            'border-top-width' => '0',
            'border-right-width' => '0',
            'border-bottom-width' => '0',
            'border-left-width' => '0',

            'border-top-style' => 'none',
            'border-right-style' => 'none',
            'border-bottom-style' => 'none',
            'border-left-style' => 'none',

            'border-top-color' => 'currentcolor',
            'border-right-color' => 'currentcolor',
            'border-bottom-color' => 'currentcolor',
            'border-left-color' => 'currentcolor',

            'border-top-left-radius' => '0',
            'border-top-right-radius' => '0',
            'border-bottom-right-radius' => '0',
            'border-bottom-left-radius' => '0',

            'background-color' => 'transparent',
            'background-image' => 'none',
            'background-size' => 'auto',
            'background-position' => '0% 0%',
            'background-repeat' => 'repeat',
            'list-style-type' => 'disc',
            'list-style-position' => 'outside',
            'text-decoration-color' => 'currentcolor',
            'word-spacing' => '0',
            'object-fit' => 'fill',
            'object-position' => '50% 50%',
            'color' => '#111827',
            'opacity' => '1',

            'font-family' => 'Inter',
            'font-size' => $fsStr,
            'font-weight' => 'normal',
            'font-style' => 'normal',
            'line-height' => '1.4',
            'letter-spacing' => '0',
            'text-align' => 'start',
            'text-decoration' => 'none',
            'text-transform' => 'none',
            'text-indent' => '0',
            'vertical-align' => 'baseline',
            'white-space' => 'normal',
            'word-break' => 'normal',
            'overflow-wrap' => 'normal',

            // Flexbox properties
            'flex-direction' => 'row',
            'flex-wrap' => 'nowrap',
            'justify-content' => 'flex-start',
            'align-items' => 'stretch',
            'align-content' => 'stretch',
            'align-self' => 'auto',
            'justify-self' => 'auto',
            'justify-items' => 'stretch',
            'flex-grow' => '0',
            'flex-shrink' => '1',
            'flex-basis' => 'auto',
            'order' => '0',
            'row-gap' => '0',
            'column-gap' => 'normal',
            'column-count' => 'auto',
            'column-width' => 'auto',
            'column-fill' => 'balance',
            'column-span' => 'none',
            'column-rule-width' => '0',
            'column-rule-style' => 'none',
            'column-rule-color' => 'currentcolor',

            // CSS Grid properties
            'grid-template-columns' => 'none',
            'grid-template-rows' => 'none',
            'grid-auto-rows' => 'auto',
            'grid-auto-flow' => 'row',
            'grid-column-start' => 'auto',
            'grid-column-end' => 'auto',
            'grid-row-start' => 'auto',
            'grid-row-end' => 'auto',

            // Table properties
            'border-collapse' => 'collapse',
            'border-spacing' => '0',
            'table-layout' => 'auto',

            // Positioning & Pagination
            'top' => 'auto',
            'right' => 'auto',
            'bottom' => 'auto',
            'left' => 'auto',
            'z-index' => '0',
            'overflow' => 'visible',
            'box-shadow' => 'none',
            'transform' => 'none',
            'page-break-before' => 'auto',
            'page-break-after' => 'auto',
            'page-break-inside' => 'auto',
        ];
    }

    public function getFontSizePt(): float
    {
        return $this->computedFontSizePt;
    }

    public function getRootFontSizePt(): float
    {
        return $this->rootFontSizePt;
    }

    /**
     * Creates a child ComputedStyle inheriting inheritable properties and CSS variables from parent.
     * Note: font-size is inherited as its already-resolved pt value, preventing em/% compounding.
     */
    public function inheritForElement(string $tagName): self
    {
        $child = new self(
            [],
            $this->variables,
            $this->computedFontSizePt,
            $this->rootFontSizePt,
            $this->defaultBoxSizing
        );
        $child->parentProps = $this->props;

        foreach (self::INHERITED_PROPS as $prop) {
            if (isset($this->props[$prop])) {
                $child->props[$prop] = $this->props[$prop];
            }
        }
        $child->computedFontSizePt = $this->computedFontSizePt;
        $child->props['font-size'] = round($this->computedFontSizePt, 4) . 'pt';

        $child->applyUserAgentDefaults(strtolower($tagName));
        return $child;
    }

    public function applyUserAgentDefaults(string $tag): void
    {
        switch ($tag) {
            case 'html':
            case 'body':
                $this->set('display', 'block');
                break;
            case 'center':
                $this->set('display', 'block');
                $this->set('text-align', 'center');
                break;
            case 'span':
            case 'a':
            case 'strong':
            case 'b':
            case 'em':
            case 'i':
            case 'u':
            case 's':
            case 'del':
            case 'strike':
            case 'code':
            case 'small':
            case 'mark':
            case 'sub':
            case 'sup':
            case 'label':
            case 'abbr':
            case 'font':
            case 'q':
            case 'cite':
            case 'dfn':
            case 'kbd':
            case 'samp':
            case 'var':
            case 'ins':
            case 'time':
            case 'tt':
            case 'big':
            case 'bdi':
            case 'bdo':
            case 'data':
            case 'output':
            case 'nobr':
            case 'acronym':
            case 'wbr':
            case 'meter':
            case 'progress':
            case 'barcode':
                $this->set('display', 'inline');
                break;
            case 'img':
            case 'svg':
                $this->set('display', 'inline-block');
                break;
            case 'button':
            case 'input':
            case 'select':
            case 'textarea':
                // Form controls: replaced inline-blocks (input[type] refinements are applied by CssParser)
                \IvoPdf\Layout\FormControls::applyDefaults($this, $tag);
                break;
            case 'h1':
                $this->set('font-size', '22pt');
                $this->set('font-weight', 'bold');
                $this->set('margin-top', '6pt');
                $this->set('margin-bottom', '8pt');
                $this->set('line-height', '1.25');
                break;
            case 'h2':
                $this->set('font-size', '17pt');
                $this->set('font-weight', 'bold');
                $this->set('margin-top', '6pt');
                $this->set('margin-bottom', '6pt');
                $this->set('line-height', '1.3');
                break;
            case 'h3':
                $this->set('font-size', '13.5pt');
                $this->set('font-weight', 'bold');
                $this->set('margin-top', '5pt');
                $this->set('margin-bottom', '5pt');
                $this->set('line-height', '1.3');
                break;
            case 'h4':
                $this->set('font-size', '11.5pt');
                $this->set('font-weight', 'bold');
                $this->set('margin-top', '4pt');
                $this->set('margin-bottom', '4pt');
                break;
            case 'h5':
            case 'h6':
                $this->set('font-size', '10pt');
                $this->set('font-weight', 'bold');
                $this->set('margin-top', '3pt');
                $this->set('margin-bottom', '3pt');
                break;
            case 'p':
                $this->set('margin-top', '0');
                $this->set('margin-bottom', '6pt');
                break;
            case 'ul':
            case 'ol':
            case 'menu':
                $this->set('margin-top', '3pt');
                $this->set('margin-bottom', '6pt');
                $this->set('padding-left', '16pt');
                $this->set('list-style-type', $tag === 'ol' ? 'decimal' : 'disc');
                break;
            case 'li':
                $this->set('display', 'list-item');
                $this->set('margin-bottom', '2pt');
                break;
            case 'dd':
                $this->set('margin-left', '16pt');
                $this->set('margin-bottom', '4pt');
                break;
            case 'dt':
                $this->set('font-weight', 'bold');
                break;
            case 'blockquote':
                $this->set('margin-top', '6pt');
                $this->set('margin-bottom', '6pt');
                $this->set('padding-left', '10pt');
                $this->set('border-left-width', '3pt');
                $this->set('border-left-style', 'solid');
                $this->set('border-left-color', '#cbd5e1');
                break;
            case 'pre':
                $this->set('font-family', 'DejaVu Sans Mono');
                $this->set('white-space', 'pre-wrap');
                $this->set('padding', '8pt');
                $this->set('background-color', '#f8fafc');
                $this->set('border', '1px solid #e2e8f0');
                $this->set('border-radius', '4pt');
                $this->set('margin-bottom', '6pt');
                break;
            case 'hr':
                $this->set('display', 'block');
                $this->set('height', '0');
                $this->set('border-top-width', '1px');
                $this->set('border-top-style', 'solid');
                $this->set('border-top-color', '#e2e8f0');
                $this->set('margin-top', '8pt');
                $this->set('margin-bottom', '8pt');
                break;
            case 'table':
                $this->set('display', 'table');
                $this->set('width', '100%');
                $this->set('border-collapse', 'collapse');
                $this->set('margin-bottom', '8pt');
                break;
            case 'thead':
                $this->set('display', 'table-header-group');
                break;
            case 'tbody':
                $this->set('display', 'table-row-group');
                break;
            case 'tfoot':
                $this->set('display', 'table-footer-group');
                break;
            case 'tr':
                $this->set('display', 'table-row');
                break;
            case 'th':
                $this->set('display', 'table-cell');
                $this->set('font-weight', 'bold');
                $this->set('text-align', 'start');
                $this->set('vertical-align', 'middle');
                $this->set('padding', '6pt 8pt');
                break;
            case 'td':
                $this->set('display', 'table-cell');
                $this->set('vertical-align', 'middle');
                $this->set('padding', '6pt 8pt');
                break;
            case 'caption':
                $this->set('display', 'table-caption');
                $this->set('text-align', 'center');
                $this->set('padding-bottom', '4pt');
                break;
            case 'colgroup':
                $this->set('display', 'table-column-group');
                break;
            case 'col':
                $this->set('display', 'table-column');
                break;
            case 'figure':
                $this->set('margin-top', '6pt');
                $this->set('margin-bottom', '6pt');
                break;
            case 'figcaption':
                $this->set('font-size', '0.9em');
                $this->set('color', '#475569');
                break;
            case 'nav':
            case 'address':
                if ($tag === 'address') {
                    $this->set('font-style', 'italic');
                }
                break;
        }

        // Autonomous custom elements (<my-widget>) are inline by default, like in browsers
        if (str_contains($tag, '-')) {
            $this->set('display', 'inline');
        }

        // Inline tag semantic styling
        if ($tag === 'strong' || $tag === 'b') {
            $this->set('font-weight', 'bold');
        } elseif ($tag === 'em' || $tag === 'i' || $tag === 'cite' || $tag === 'dfn' || $tag === 'var') {
            $this->set('font-style', 'italic');
        } elseif ($tag === 'u' || $tag === 'ins') {
            $this->addTextDecoration('underline');
        } elseif ($tag === 'big') {
            $this->set('font-size', '1.2em');
        } elseif ($tag === 'tt') {
            $this->set('font-family', 'DejaVu Sans Mono');
        } elseif ($tag === 's' || $tag === 'del' || $tag === 'strike') {
            $this->addTextDecoration('line-through');
        } elseif ($tag === 'code' || $tag === 'kbd' || $tag === 'samp') {
            $this->set('font-family', 'DejaVu Sans Mono');
            $this->set('font-size', '0.9em');
        } elseif ($tag === 'small') {
            $this->set('font-size', '0.85em');
        } elseif ($tag === 'sub') {
            $this->set('font-size', '0.75em');
            $this->set('vertical-align', 'sub');
        } elseif ($tag === 'sup') {
            $this->set('font-size', '0.75em');
            $this->set('vertical-align', 'super');
        } elseif ($tag === 'mark') {
            $this->set('background-color', '#fef08a');
        } elseif ($tag === 'a') {
            $this->set('color', '#2563eb');
            $this->addTextDecoration('underline');
        }
    }

    private function addTextDecoration(string $deco): void
    {
        $existing = strtolower(trim($this->props['text-decoration'] ?? 'none'));
        if ($existing === '' || $existing === 'none') {
            $this->props['text-decoration'] = $deco;
        } elseif (!str_contains($existing, $deco)) {
            $this->props['text-decoration'] = $existing . ' ' . $deco;
        }
    }

    public function get(string $prop, string $default = ''): string
    {
        // Hot path: callers almost always pass the canonical lowercase key, stored keys are canonical
        $val = $this->props[$prop] ?? null;
        if ($val !== null) {
            $key = $prop;
        } else {
            $key = strtolower(trim($prop));
            $val = $this->props[$key] ?? $default;
        }
        // currentColor (the CSS initial value of border/decoration colors) resolves to the element's color
        if (strlen($val) === 12 && $key !== 'color' && strcasecmp($val, 'currentcolor') === 0) {
            return $this->props['color'] ?? '#000000';
        }
        return $val;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->props;
    }

    /**
     * @return array<string, string>
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    /**
     * Returns a copy suitable for an anonymous text node: inherited text properties are kept, while box-level
     * properties of the parent (position, float, clear, transform…) are reset so the text is never treated as
     * positioned, floated or transformed on its own.
     */
    public function forTextNode(): self
    {
        $copy = clone $this;
        foreach ([
            'position' => 'static', 'float' => 'none', 'clear' => 'none', 'display' => 'inline',
            'transform' => 'none', 'z-index' => 'auto', 'top' => 'auto', 'right' => 'auto',
            'bottom' => 'auto', 'left' => 'auto', 'column-count' => 'auto', 'column-width' => 'auto',
        ] as $prop => $initial) {
            if (array_key_exists($prop, $copy->props)) {
                $copy->props[$prop] = $initial;
            }
        }
        return $copy;
    }

    public function set(string $prop, string $value): void
    {
        $key = strtolower(trim($prop));
        $val = trim($value);
        $val = trim((string) preg_replace('/!\s*important$/i', '', $val));

        if (str_starts_with($key, '--')) {
            $this->variables[$key] = $this->resolveVariables($val);
            return;
        }

        $val = $this->resolveVariables($val);
        $lowerVal = strtolower($val);

        if ($lowerVal === 'unset' || $lowerVal === 'revert' || $lowerVal === 'revert-layer') {
            $lowerVal = in_array($key, self::INHERITED_PROPS, true) ? 'inherit' : 'initial';
        }

        if ($lowerVal === 'initial') {
            $this->applyInitialValue($key);
            return;
        }

        if ($lowerVal === 'inherit') {
            if ($key === 'font-size') {
                $this->computedFontSizePt = $this->parentFontSizePt;
                $this->props['font-size'] = round($this->parentFontSizePt, 4) . 'pt';
                return;
            }
            if (isset($this->parentProps[$key])) {
                $this->props[$key] = $this->parentProps[$key];
                return;
            }
            // Shorthand (e.g. margin: inherit) -> copy every longhand from the parent
            foreach ($this->parentProps as $pk => $pv) {
                if (str_starts_with($pk, $key . '-')) {
                    $this->props[$pk] = $pv;
                }
            }
            return;
        }

        if ($key === 'font-size') {
            $this->computedFontSizePt = $this->resolveFontSizeValue($val);
            $this->props['font-size'] = round($this->computedFontSizePt, 4) . 'pt';
            return;
        }

        if ($key === 'color' && strtolower($val) === 'currentcolor') {
            $this->props['color'] = $this->parentProps['color'] ?? ($this->props['color'] ?? '#111827');
            return;
        }

        switch ($key) {
            case 'margin':
                $this->expandBoxFour('margin', $val);
                break;
            case 'margin-inline':
                $parts = $this->splitSpaceTokens($val);
                $this->props['margin-left'] = $parts[0] ?? '0';
                $this->props['margin-right'] = $parts[1] ?? ($parts[0] ?? '0');
                break;
            case 'margin-block':
                $parts = $this->splitSpaceTokens($val);
                $this->props['margin-top'] = $parts[0] ?? '0';
                $this->props['margin-bottom'] = $parts[1] ?? ($parts[0] ?? '0');
                break;
            case 'padding':
                $this->expandBoxFour('padding', $val);
                break;
            case 'padding-inline':
                $parts = $this->splitSpaceTokens($val);
                $this->props['padding-left'] = $parts[0] ?? '0';
                $this->props['padding-right'] = $parts[1] ?? ($parts[0] ?? '0');
                break;
            case 'padding-block':
                $parts = $this->splitSpaceTokens($val);
                $this->props['padding-top'] = $parts[0] ?? '0';
                $this->props['padding-bottom'] = $parts[1] ?? ($parts[0] ?? '0');
                break;
            case 'inset':
                $parts = $this->expandFourValues($val);
                $this->props['top'] = $parts[0];
                $this->props['right'] = $parts[1];
                $this->props['bottom'] = $parts[2];
                $this->props['left'] = $parts[3];
                break;
            case 'border':
                $this->expandBorderShorthand(['top', 'right', 'bottom', 'left'], $val);
                break;
            case 'border-top':
                $this->expandBorderShorthand(['top'], $val);
                break;
            case 'border-right':
                $this->expandBorderShorthand(['right'], $val);
                break;
            case 'border-bottom':
                $this->expandBorderShorthand(['bottom'], $val);
                break;
            case 'border-left':
                $this->expandBorderShorthand(['left'], $val);
                break;
            case 'border-width':
                $parts = $this->expandFourValues($val);
                $this->props['border-top-width'] = $parts[0];
                $this->props['border-right-width'] = $parts[1];
                $this->props['border-bottom-width'] = $parts[2];
                $this->props['border-left-width'] = $parts[3];
                break;
            case 'border-style':
                $parts = $this->expandFourValues($val);
                $this->props['border-top-style'] = $parts[0];
                $this->props['border-right-style'] = $parts[1];
                $this->props['border-bottom-style'] = $parts[2];
                $this->props['border-left-style'] = $parts[3];
                break;
            case 'border-color':
                $parts = $this->expandFourValues($val);
                $this->props['border-top-color'] = $parts[0];
                $this->props['border-right-color'] = $parts[1];
                $this->props['border-bottom-color'] = $parts[2];
                $this->props['border-left-color'] = $parts[3];
                break;
            case 'border-radius':
                $slashParts = explode('/', $val);
                $parts = $this->expandFourValues(trim($slashParts[0]));
                $this->props['border-top-left-radius'] = $parts[0];
                $this->props['border-top-right-radius'] = $parts[1];
                $this->props['border-bottom-right-radius'] = $parts[2];
                $this->props['border-bottom-left-radius'] = $parts[3];
                break;
            case 'background':
                $this->expandBackgroundShorthand($val);
                break;
            case 'font':
                $this->expandFontShorthand($val);
                break;
            case 'flex':
                $this->expandFlexShorthand($val);
                break;
            case 'flex-flow':
                $parts = $this->splitSpaceTokens($val);
                foreach ($parts as $p) {
                    $pl = strtolower($p);
                    if (in_array($pl, ['row', 'row-reverse', 'column', 'column-reverse'], true)) {
                        $this->props['flex-direction'] = $pl;
                    } elseif (in_array($pl, ['nowrap', 'wrap', 'wrap-reverse'], true)) {
                        $this->props['flex-wrap'] = $pl;
                    }
                }
                break;
            case 'gap':
            case 'grid-gap':
                $parts = $this->splitSpaceTokens($val);
                $this->props['row-gap'] = $parts[0] ?? '0';
                $this->props['column-gap'] = $parts[1] ?? ($parts[0] ?? '0');
                break;
            case 'columns':
                $this->props['column-count'] = 'auto';
                $this->props['column-width'] = 'auto';
                foreach ($this->splitSpaceTokens($val) as $tok) {
                    if (preg_match('/^\d+$/', $tok) === 1) {
                        $this->props['column-count'] = $tok;
                    } elseif (strtolower($tok) !== 'auto') {
                        $this->props['column-width'] = $tok;
                    }
                }
                break;
            case 'column-rule':
                $this->props['column-rule-width'] = 'medium';
                $this->props['column-rule-style'] = 'none';
                $this->props['column-rule-color'] = 'currentcolor';
                foreach ($this->splitTopLevel($val, ' ') as $tok) {
                    $tl = strtolower(trim($tok));
                    if ($tl === '') {
                        continue;
                    }
                    if (in_array($tl, ['none', 'hidden', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset'], true)) {
                        $this->props['column-rule-style'] = $tl;
                    } elseif (CssColor::parse($tl) !== null || $tl === 'currentcolor') {
                        $this->props['column-rule-color'] = $tl;
                    } else {
                        $this->props['column-rule-width'] = $tl;
                    }
                }
                break;
            case 'grid-row-gap':
                $this->props['row-gap'] = $val;
                break;
            case 'grid-column-gap':
                $this->props['column-gap'] = $val;
                break;
            case 'grid-column':
                $this->expandGridLineShorthand('column', $val);
                break;
            case 'grid-row':
                $this->expandGridLineShorthand('row', $val);
                break;
            case 'place-items':
                $parts = $this->splitSpaceTokens($val);
                $this->props['align-items'] = $parts[0] ?? 'stretch';
                $this->props['justify-items'] = $parts[1] ?? ($parts[0] ?? 'stretch');
                break;
            case 'place-content':
                $parts = $this->splitSpaceTokens($val);
                $this->props['align-content'] = $parts[0] ?? 'stretch';
                $this->props['justify-content'] = $parts[1] ?? ($parts[0] ?? 'flex-start');
                break;
            case 'place-self':
                $parts = $this->splitSpaceTokens($val);
                $this->props['align-self'] = $parts[0] ?? 'auto';
                $this->props['justify-self'] = $parts[1] ?? ($parts[0] ?? 'auto');
                break;
            case 'list-style':
                $this->expandListStyleShorthand($val);
                break;
            case 'text-decoration':
            case 'text-decoration-line':
                $this->props['text-decoration'] = $val;
                foreach ($this->splitSpaceTokens($val) as $tok) {
                    if (CssColor::parse($tok) !== null) {
                        $this->props['text-decoration-color'] = $tok;
                    }
                }
                break;
            case 'overflow-x':
            case 'overflow-y':
                if (($this->props['overflow'] ?? 'visible') === 'visible') {
                    $this->props['overflow'] = $val;
                }
                $this->props[$key] = $val;
                break;
            case 'break-before':
                $this->props['page-break-before'] = ($val === 'page') ? 'always' : $val;
                break;
            case 'break-after':
                $this->props['page-break-after'] = ($val === 'page') ? 'always' : $val;
                break;
            case 'break-inside':
                $this->props['page-break-inside'] = $val;
                break;
            case 'display':
                $this->props['display'] = $val;
                if (strtolower($val) === 'inline-table' && ($this->props['width'] ?? '') === '100%') {
                    $this->props['width'] = 'auto'; // the UA's table width:100% must not stretch an inline-table
                }
                break;
            case 'outline':
                $this->expandOutlineShorthand($val);
                break;
            case 'border-image':
                $this->expandBorderImageShorthand($val);
                break;
            default:
                $this->props[$key] = $val;
                break;
        }
    }

    private function resolveFontSizeValue(string $val): float
    {
        $v = strtolower(trim($val));
        return match ($v) {
            'xx-small' => $this->rootFontSizePt * 0.6,
            'x-small' => $this->rootFontSizePt * 0.75,
            'small' => $this->rootFontSizePt * 0.89,
            'medium', 'initial' => $this->rootFontSizePt,
            'large' => $this->rootFontSizePt * 1.2,
            'x-large' => $this->rootFontSizePt * 1.5,
            'xx-large' => $this->rootFontSizePt * 2.0,
            'smaller' => $this->parentFontSizePt * 0.85,
            'larger' => $this->parentFontSizePt * 1.15,
            default => max(
                1.0,
                CssUnit::toPt(
                    $v,
                    $this->parentFontSizePt,
                    $this->parentFontSizePt,
                    $this->rootFontSizePt,
                    $this->parentFontSizePt
                )
            ),
        };
    }

    private function expandFontShorthand(string $val): void
    {
        // Match optional style/weight, then size(/line-height), then family
        if (preg_match('/^(?:(italic|oblique|normal|bold|bolder|lighter|\d{3})\s+)*(?:(italic|oblique|normal|bold|bolder|lighter|\d{3})\s+)?([\d.]+(?:px|pt|em|rem|%)|xx-small|x-small|small|medium|large|x-large|xx-large)(?:\/([\d.]+(?:px|pt|em|rem|%)?))?\s+(.+)$/i', trim($val), $m)) {
            foreach ([$m[1], $m[2]] as $mod) {
                $modL = strtolower(trim($mod));
                if ($modL === 'italic' || $modL === 'oblique') {
                    $this->props['font-style'] = $modL;
                } elseif ($modL !== '' && $modL !== 'normal') {
                    $this->props['font-weight'] = $modL;
                }
            }
            $this->set('font-size', $m[3]);
            if (isset($m[4]) && $m[4] !== '') {
                $this->props['line-height'] = $m[4];
            }
            $this->props['font-family'] = trim($m[5]);
        }
    }

    private function resolveVariables(string $val): string
    {
        if (!str_contains($val, 'var(')) {
            return $val;
        }

        $maxIterations = 5;
        while ($maxIterations-- > 0 && str_contains($val, 'var(')) {
            $val = (string) preg_replace_callback(
                '/var\(\s*(--[a-zA-Z0-9_-]+)\s*(?:,\s*([^)]+))?\)/',
                function (array $m): string {
                    $varName = strtolower(trim($m[1]));
                    if (isset($this->variables[$varName])) {
                        return $this->variables[$varName];
                    }
                    return isset($m[2]) ? trim($m[2]) : '';
                },
                $val
            );
        }

        return $val;
    }

    private function expandBoxFour(string $prefix, string $val): void
    {
        $parts = $this->expandFourValues($val);
        $this->props["{$prefix}-top"] = $parts[0];
        $this->props["{$prefix}-right"] = $parts[1];
        $this->props["{$prefix}-bottom"] = $parts[2];
        $this->props["{$prefix}-left"] = $parts[3];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function expandFourValues(string $val): array
    {
        $parts = $this->splitSpaceTokens($val);
        $count = count($parts);
        if ($count === 0) {
            return ['0', '0', '0', '0'];
        }
        if ($count === 1) {
            return [$parts[0], $parts[0], $parts[0], $parts[0]];
        }
        if ($count === 2) {
            return [$parts[0], $parts[1], $parts[0], $parts[1]];
        }
        if ($count === 3) {
            return [$parts[0], $parts[1], $parts[2], $parts[1]];
        }
        return [$parts[0], $parts[1], $parts[2], $parts[3]];
    }

    /**
     * @param list<string> $sides
     */
    private function expandBorderShorthand(array $sides, string $val): void
    {
        if (strtolower($val) === 'none' || $val === '0') {
            foreach ($sides as $side) {
                $this->props["border-{$side}-width"] = '0';
                $this->props["border-{$side}-style"] = 'none';
            }
            return;
        }

        $tokens = $this->splitSpaceTokens($val);
        $width = 'medium';
        $style = 'none';
        $color = 'currentcolor';

        foreach ($tokens as $tok) {
            $tl = strtolower($tok);
            if (in_array($tl, ['none', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset'], true)) {
                $style = $tl;
            } elseif (preg_match('/^([+]?\.?\d|thin$|medium$|thick$|calc\()/i', $tl)) {
                $width = $tok;
            } else {
                $color = $tok;
            }
        }

        foreach ($sides as $side) {
            $this->props["border-{$side}-width"] = $width;
            $this->props["border-{$side}-style"] = $style;
            $this->props["border-{$side}-color"] = $color;
        }
    }

    private function expandBackgroundShorthand(string $val): void
    {
        // The shorthand resets every background longhand first (so `background:#fff` clears an earlier gradient)
        $this->props['background-color'] = 'transparent';
        $this->props['background-image'] = 'none';
        $this->props['background-size'] = 'auto';
        $this->props['background-position'] = '0% 0%';
        $this->props['background-repeat'] = 'repeat';

        $vl = strtolower(trim($val));
        if ($vl === '' || $vl === 'none' || $vl === 'transparent') {
            return;
        }

        // Multiple layers: the image comes from the first layer, the color from the last layer
        $layers = $this->splitTopLevel($val, ',');
        $firstLayer = $layers[0];
        $lastLayer = $layers[count($layers) - 1];

        $remaining = [];
        foreach ($this->splitSpaceTokens($firstLayer) as $tok) {
            $tl = strtolower($tok);
            if (str_starts_with($tl, 'url(') || str_contains($tl, 'gradient(')) {
                if ($this->props['background-image'] === 'none') {
                    $this->props['background-image'] = str_starts_with($tl, 'url(')
                        ? 'url(' . trim(substr($tok, 4, -1), " \t\n\r\0\x0B'\"") . ')'
                        : $tok;
                }
                continue;
            }
            $remaining[] = $tok;
        }

        // Split "position / size"
        $slashParts = $this->splitTopLevel(implode(' ', $remaining), '/');
        $positionTokens = [];
        $sizeTokens = [];
        $repeatTokens = [];
        $otherTokens = $this->splitSpaceTokens($slashParts[0]);
        if (count($slashParts) > 1) {
            $afterSlash = $this->splitSpaceTokens($slashParts[1]);
            while (!empty($afterSlash) && count($sizeTokens) < 2 && $this->isBackgroundSizeToken($afterSlash[0])) {
                $sizeTokens[] = array_shift($afterSlash);
            }
            $otherTokens = array_merge($otherTokens, $afterSlash);
        }

        foreach ($otherTokens as $tok) {
            $tl = strtolower($tok);
            if (in_array($tl, ['repeat', 'no-repeat', 'repeat-x', 'repeat-y', 'space', 'round'], true)) {
                $repeatTokens[] = $tl;
            } elseif (in_array($tl, ['scroll', 'fixed', 'local', 'border-box', 'padding-box', 'content-box', 'text'], true)) {
                continue;
            } elseif (in_array($tl, ['left', 'right', 'top', 'bottom', 'center'], true) || preg_match('/^-?[\d.]/', $tl) || str_starts_with($tl, 'calc(')) {
                $positionTokens[] = $tok;
            } elseif (CssColor::parse($tok) !== null || $tl === 'currentcolor') {
                $this->props['background-color'] = $tok;
            }
        }

        if ($lastLayer !== $firstLayer) {
            foreach ($this->splitSpaceTokens($lastLayer) as $tok) {
                if (CssColor::parse($tok) !== null) {
                    $this->props['background-color'] = $tok;
                }
            }
        }

        if (!empty($positionTokens)) {
            $this->props['background-position'] = implode(' ', $positionTokens);
        }
        if (!empty($sizeTokens)) {
            $this->props['background-size'] = implode(' ', $sizeTokens);
        }
        if (!empty($repeatTokens)) {
            $this->props['background-repeat'] = implode(' ', $repeatTokens);
        }
    }

    private function isBackgroundSizeToken(string $tok): bool
    {
        $tl = strtolower($tok);
        return in_array($tl, ['cover', 'contain', 'auto'], true) || (bool) preg_match('/^(\.?\d|calc\()/', $tl);
    }

    private function expandListStyleShorthand(string $val): void
    {
        $typeSet = false;
        foreach ($this->splitSpaceTokens($val) as $tok) {
            $tl = strtolower($tok);
            if ($tl === 'inside' || $tl === 'outside') {
                $this->props['list-style-position'] = $tl;
            } elseif (str_starts_with($tl, 'url(')) {
                $this->props['list-style-image'] = $tok;
            } elseif ($tl === 'none') {
                if (!$typeSet) {
                    $this->props['list-style-type'] = 'none';
                }
            } else {
                $this->props['list-style-type'] = $tl;
                $typeSet = true;
            }
        }
    }

    /**
     * Resets a property (or every longhand of a shorthand) to its CSS initial value.
     */
    /**
     * Expands `outline: <width> <style> <color>` into its longhands.
     */
    private function expandOutlineShorthand(string $val): void
    {
        $this->props['outline-width'] = 'medium';
        $this->props['outline-style'] = 'none';
        $this->props['outline-color'] = 'currentcolor';
        if (strtolower($val) === 'none' || $val === '0') {
            return;
        }
        foreach ($this->splitSpaceTokens($val) as $tok) {
            $tl = strtolower($tok);
            if (in_array($tl, ['none', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset', 'auto', 'hidden'], true)) {
                $this->props['outline-style'] = $tl;
            } elseif (preg_match('/^([+]?\.?\d|thin$|medium$|thick$|calc\()/i', $tl)) {
                $this->props['outline-width'] = $tok;
            } else {
                $this->props['outline-color'] = $tok;
            }
        }
    }

    /**
     * Expands `border-image: <source> <slice> / <width> / <outset> <repeat>` into longhands (kept as raw strings).
     */
    private function expandBorderImageShorthand(string $val): void
    {
        $this->props['border-image-source'] = 'none';
        $this->props['border-image-slice'] = '100%';
        $this->props['border-image-width'] = '1';
        $this->props['border-image-outset'] = '0';
        $this->props['border-image-repeat'] = 'stretch';
        if (strtolower($val) === 'none') {
            return;
        }
        if (preg_match('/(?:repeating-)?(?:linear|radial)-gradient\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)|url\([^)]*\)/i', $val, $m, PREG_OFFSET_CAPTURE)) {
            $this->props['border-image-source'] = $m[0][0];
            $val = trim(substr($val, 0, $m[0][1]) . ' ' . substr($val, $m[0][1] + strlen($m[0][0])));
        }
        if (preg_match('/\b(stretch|repeat|round|space)(?:\s+(stretch|repeat|round|space))?\s*$/i', $val, $rm)) {
            $this->props['border-image-repeat'] = strtolower(trim($rm[0]));
            $val = trim(substr($val, 0, -strlen($rm[0])));
        }
        $parts = array_map('trim', explode('/', $val));
        if (($parts[0] ?? '') !== '') {
            $this->props['border-image-slice'] = $parts[0];
        }
        if (isset($parts[1]) && $parts[1] !== '') {
            $this->props['border-image-width'] = $parts[1];
        }
        if (isset($parts[2]) && $parts[2] !== '') {
            $this->props['border-image-outset'] = $parts[2];
        }
    }

    private function applyInitialValue(string $key): void
    {
        if (self::$initialValues === null) {
            self::$initialValues = (new self())->props;
        }
        if ($key === 'font-size') {
            $this->computedFontSizePt = $this->rootFontSizePt;
            $this->props['font-size'] = round($this->rootFontSizePt, 4) . 'pt';
            return;
        }
        if ($key === 'color') {
            $this->props['color'] = '#000000';
            return;
        }
        if (isset(self::$initialValues[$key])) {
            $this->props[$key] = self::$initialValues[$key];
            return;
        }
        foreach (self::$initialValues as $ik => $iv) {
            if ($ik !== 'font-size' && str_starts_with($ik, $key . '-')) {
                $this->props[$ik] = $iv;
            }
        }
    }

    /**
     * Splits a value on a separator character that is not nested inside parentheses.
     *
     * @return list<string>
     */
    private function splitTopLevel(string $str, string $separator): array
    {
        $result = [];
        $current = '';
        $depth = 0;
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $ch = $str[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === $separator && $depth === 0) {
                $result[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $result[] = trim($current);
        return $result;
    }

    private function expandFlexShorthand(string $val): void
    {
        $vl = strtolower(trim($val));
        if ($vl === 'none') {
            $this->props['flex-grow'] = '0';
            $this->props['flex-shrink'] = '0';
            $this->props['flex-basis'] = 'auto';
            return;
        }
        if ($vl === 'auto') {
            $this->props['flex-grow'] = '1';
            $this->props['flex-shrink'] = '1';
            $this->props['flex-basis'] = 'auto';
            return;
        }
        if ($vl === 'initial') {
            $this->props['flex-grow'] = '0';
            $this->props['flex-shrink'] = '1';
            $this->props['flex-basis'] = 'auto';
            return;
        }

        $parts = $this->splitSpaceTokens($val);
        if (count($parts) === 1) {
            if (is_numeric($parts[0])) {
                $this->props['flex-grow'] = $parts[0];
                $this->props['flex-shrink'] = '1';
                $this->props['flex-basis'] = '0%';
            } else {
                $this->props['flex-grow'] = '1';
                $this->props['flex-shrink'] = '1';
                $this->props['flex-basis'] = $parts[0];
            }
        } elseif (count($parts) === 2) {
            $this->props['flex-grow'] = $parts[0];
            if (is_numeric($parts[1])) {
                $this->props['flex-shrink'] = $parts[1];
                $this->props['flex-basis'] = '0%';
            } else {
                $this->props['flex-shrink'] = '1';
                $this->props['flex-basis'] = $parts[1];
            }
        } elseif (count($parts) >= 3) {
            $this->props['flex-grow'] = $parts[0];
            $this->props['flex-shrink'] = $parts[1];
            $this->props['flex-basis'] = $parts[2];
        }
    }

    private function expandGridLineShorthand(string $axis, string $val): void
    {
        if (str_contains($val, '/')) {
            [$start, $end] = array_map('trim', explode('/', $val, 2));
            $this->props["grid-{$axis}-start"] = $start;
            $this->props["grid-{$axis}-end"] = $end;
        } else {
            $this->props["grid-{$axis}-start"] = trim($val);
            $this->props["grid-{$axis}-end"] = 'auto';
        }
    }

    /**
     * Splits a CSS property value by top-level whitespace (ignoring whitespace inside parentheses).
     *
     * @return list<string>
     */
    public function splitSpaceTokens(string $str): array
    {
        $result = [];
        $current = '';
        $depth = 0;
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $ch = $str[$i];
            if ($ch === '(') {
                $depth++;
                $current .= $ch;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
                $current .= $ch;
            } elseif (ctype_space($ch) && $depth === 0) {
                if ($current !== '') {
                    $result[] = $current;
                    $current = '';
                }
            } else {
                $current .= $ch;
            }
        }

        if ($current !== '') {
            $result[] = $current;
        }

        return $result;
    }
}
