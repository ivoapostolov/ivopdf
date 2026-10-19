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

    /**
     * Property values layered over $props (anonymous text nodes, see forTextNode()): lets the many text styles of a
     * document share their parent's property array instead of each holding a modified copy of it.
     *
     * @var array<string, string>
     */
    private array $over = [];

    /** @var array<string, array<string, string>> Interned text-node overlays (see forTextNode()) */
    private static array $overPool = [];

    /** @see shareToken() */
    private ?StyleToken $shareToken = null;

    /** @see share() */
    private bool $shared = false;

    /** Number of ancestor dir/menu/ol/ul elements (UA list defaults: nested margins and bullet styles) */
    private int $listDepth = 0;

    /** Whether an ancestor is a list (dir, dl, menu, ol, ul) */
    private bool $inList = false;

    /** The tag the user-agent defaults were applied for */
    private string $uaTag = '';

    private float $parentFontSizePt = 12.0;
    private float $rootFontSizePt = 12.0;
    private float $computedFontSizePt = 12.0;
    private string $defaultBoxSizing = 'content-box';

    /**
     * Supplies the `ex` / `ch` unit sizes (as fractions of the font size) for a font family, weight and style.
     * Set by the renderer while a document is parsed; without it both units fall back to 0.5 em.
     *
     * @var (\Closure(string, string, string): array{0: float, 1: float})|null
     */
    public static ?\Closure $fontUnitResolver = null;

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
        '-ivopdf-block-align',
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
        'caption-side',
        'font-feature-settings',
        'font-kerning',
        'font-variant',
        'font-variant-ligatures',
        'font-variant-caps',
        'font-variant-numeric',
        'text-shadow',
        'orphans',
        'widows',
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
            'line-height' => 'normal',
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
            'grid-auto-columns' => 'auto',
            'grid-template-areas' => 'none',
            'grid-auto-flow' => 'row',
            'grid-column-start' => 'auto',
            'grid-column-end' => 'auto',
            'grid-row-start' => 'auto',
            'grid-row-end' => 'auto',

            // Table properties
            'border-collapse' => 'separate',
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
        $this->materialize();
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
        $isList = in_array($this->uaTag, ['ul', 'ol', 'menu', 'dir'], true);
        $child->listDepth = $this->listDepth + ($isList ? 1 : 0);
        $child->inList = $this->inList || $isList || $this->uaTag === 'dl';

        $child->applyUserAgentDefaults(strtolower($tagName));
        return $child;
    }

    public function applyUserAgentDefaults(string $tag): void
    {
        $this->assertNotShared();
        $this->shareToken = null;
        $this->materialize();
        $this->uaTag = $tag;
        switch ($tag) {
            case 'html':
                $this->set('display', 'block');
                break;
            case 'body':
                $this->set('display', 'block');
                // Browsers and dompdf: body { margin: 8px } (the mPDF API, WriteHTML(), resets it to mPDF's 0)
                foreach (['margin-top', 'margin-right', 'margin-bottom', 'margin-left'] as $side) {
                    $this->set($side, '6pt');
                }
                break;
            case 'center':
                $this->set('display', 'block');
                $this->set('text-align', 'center');
                $this->props['-ivopdf-block-align'] = 'center'; // <center> (text-align: -webkit-center) also centres block children such as tables
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
                $this->set('font-size', '2em');
                $this->set('font-weight', 'bold');
                $this->set('margin-top', '0.67em');
                $this->set('margin-bottom', '0.67em');
                break;
            case 'h2':
                $this->set('font-size', '1.5em');
                $this->set('font-weight', 'bold');
                $this->set('margin-top', '0.83em');
                $this->set('margin-bottom', '0.83em');
                break;
            case 'h3':
                $this->set('font-size', '1.17em');
                $this->set('font-weight', 'bold');
                $this->set('margin-top', '1em');
                $this->set('margin-bottom', '1em');
                break;
            case 'h4':
                $this->set('font-size', '1em');
                $this->set('font-weight', 'bold');
                $this->set('margin-top', '1.33em');
                $this->set('margin-bottom', '1.33em');
                break;
            case 'h5':
                $this->set('font-size', '0.83em');
                $this->set('font-weight', 'bold');
                $this->set('margin-top', '1.67em');
                $this->set('margin-bottom', '1.67em');
                break;
            case 'h6':
                $this->set('font-size', '0.67em');
                $this->set('font-weight', 'bold');
                $this->set('margin-top', '2.33em');
                $this->set('margin-bottom', '2.33em');
                break;
            case 'p':
                $this->set('margin-top', '1em');
                $this->set('margin-bottom', '1em');
                break;
            case 'ul':
            case 'ol':
            case 'menu':
            case 'dir':
                // Browser defaults: margin-block 1em, padding-inline-start 40px; a list nested in another list has no
                // vertical margins (html.css `:is(dir, dl, menu, ol, ul) :is(dir, menu, ol, ul)`)
                $this->set('display', 'block');
                $nested = $this->inList;
                $this->set('margin-top', $nested ? '0' : '1em');
                $this->set('margin-bottom', $nested ? '0' : '1em');
                $this->set('padding-left', '30pt');
                $this->set('list-style-type', $tag === 'ol' ? 'decimal' : ($this->listDepth >= 2 ? 'square' : ($this->listDepth === 1 ? 'circle' : 'disc')));
                break;
            case 'dl':
                $this->set('display', 'block');
                $this->set('margin-top', $this->inList ? '0' : '1em');
                $this->set('margin-bottom', $this->inList ? '0' : '1em');
                break;
            case 'li':
                $this->set('display', 'list-item');
                break;
            case 'summary':
                $this->set('display', 'list-item');
                $this->set('list-style-type', 'disclosure-closed');
                $this->set('list-style-position', 'inside');
                break;
            case 'dd':
                $this->set('display', 'block');
                $this->set('margin-left', '30pt');
                break;
            case 'dt':
                $this->set('font-weight', 'bold');
                break;
            case 'blockquote':
                // Browser default: margin 1em 40px
                $this->set('margin-top', '1em');
                $this->set('margin-bottom', '1em');
                $this->set('margin-left', '30pt');
                $this->set('margin-right', '30pt');
                break;
            case 'pre':
            case 'listing':
            case 'xmp':
                // Browser default: monospace, white-space: pre, margin 1em 0
                $this->set('font-family', 'DejaVu Sans Mono');
                $this->set('white-space', 'pre');
                $this->set('margin-top', '1em');
                $this->set('margin-bottom', '1em');
                break;
            case 'hr':
                // Browser default: 1px inset gray border on every side (2px tall), margin 0.5em auto
                $this->set('display', 'block');
                $this->set('height', '0');
                $this->set('border', '1px inset gray');
                $this->set('margin-top', '0.5em');
                $this->set('margin-bottom', '0.5em');
                break;
            case 'table':
                // Browsers, dompdf and mPDF: border-collapse: separate; border-spacing: 2px; no margins
                $this->set('display', 'table');
                $this->set('border-collapse', 'separate');
                $this->set('border-spacing', '1.5pt');
                $this->set('box-sizing', 'border-box'); // Chromium / WebKit UA style: `width` is the table's border box
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
                $this->set('text-align', 'center'); // browsers and dompdf: th { text-align: -internal-center }
                $this->set('vertical-align', 'middle');
                $this->set('padding', '1px'); // browser and dompdf default
                break;
            case 'td':
                $this->set('display', 'table-cell');
                $this->set('vertical-align', 'middle');
                $this->set('padding', '1px'); // browser and dompdf default
                break;
            case 'caption':
                $this->set('display', 'table-caption');
                $this->set('text-align', 'center'); // no padding: browsers give captions none
                break;
            case 'colgroup':
                $this->set('display', 'table-column-group');
                break;
            case 'col':
                $this->set('display', 'table-column');
                break;
            case 'figure':
                // Browser default: margin 1em 40px
                $this->set('display', 'block');
                $this->set('margin-top', '1em');
                $this->set('margin-bottom', '1em');
                $this->set('margin-left', '30pt');
                $this->set('margin-right', '30pt');
                break;
            case 'fieldset':
                // Browser default: margin 0 2px; padding 0.35em 0.75em 0.625em; border 2px groove
                $this->set('display', 'block');
                $this->set('margin-left', '1.5pt');
                $this->set('margin-right', '1.5pt');
                $this->set('padding-top', '0.35em');
                $this->set('padding-right', '0.75em');
                $this->set('padding-bottom', '0.625em');
                $this->set('padding-left', '0.75em');
                $this->set('border', '1.5pt groove #c0c0c0');
                break;
            case 'legend':
                $this->set('display', 'block');
                $this->set('padding-left', '1.5pt');
                $this->set('padding-right', '1.5pt');
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
        $val = $this->over[$prop] ?? $this->props[$prop] ?? null;
        if ($val !== null) {
            $key = $prop;
        } else {
            $key = strtolower(trim($prop));
            $val = $this->over[$key] ?? $this->props[$key] ?? $default;
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
        return $this->over === [] ? $this->props : array_replace($this->props, $this->over);
    }

    /** Whether a property is set (declared or defaulted) on this style. */
    public function has(string $prop): bool
    {
        return isset($this->over[$prop]) || isset($this->props[$prop]);
    }

    /** Folds the text-node overrides into the property array (before the style is modified). */
    private function materialize(): void
    {
        if ($this->over !== []) {
            $this->props = array_replace($this->props, $this->over);
            $this->over = [];
        }
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
     * properties of the parent (position, float, clear, transformРІР‚В¦) are reset so the text is never treated as
     * positioned, floated or transformed on its own.
     */
    public function forTextNode(): self
    {
        if ($this->shared && $this->shareToken?->textStyle !== null) {
            return $this->shareToken->textStyle; // every text node of the cells that share this style shares one text style
        }
        $copy = clone $this; // shares the property arrays; only the differing values are layered on top
        $copy->shareToken = null;
        $over = $this->over;
        foreach ([
            'position' => 'static', 'float' => 'none', 'clear' => 'none', 'display' => 'inline',
            'transform' => 'none', 'z-index' => 'auto', 'top' => 'auto', 'right' => 'auto',
            'bottom' => 'auto', 'left' => 'auto', 'column-count' => 'auto', 'column-width' => 'auto',
        ] as $prop => $initial) {
            if (array_key_exists($prop, $this->props) && ($over[$prop] ?? $this->props[$prop]) !== $initial) {
                $over[$prop] = $initial;
            }
        }
        if ($over === []) {
            return $this->shareText($copy);
        }
        // The overlay of thousands of text styles is nearly always the same few arrays: keep one instance of each
        $poolKey = '';
        foreach ($over as $k => $v) {
            $poolKey .= $k . ':' . $v . ';';
        }
        $copy->over = self::$overPool[$poolKey] ??= $over;
        if (count(self::$overPool) > 256) {
            self::$overPool = [];
        }
        return $this->shareText($copy);
    }

    /** Remembers the text style derived from a shared style (see forTextNode()). */
    private function shareText(self $text): self
    {
        if ($this->shared) {
            $text->shared = true;
            $this->shareToken()->textStyle = $text;
        }
        return $text;
    }

    /**
     * An object shared by this style and its clones for as long as none of them is modified: two styles with the same
     * token have identical property arrays (the cascade cache of CssParser keys its entries by it).
     */
    private function assertNotShared(): void
    {
        if ($this->shared) {
            throw new \LogicException('A shared ComputedStyle cannot be modified; clone it first.');
        }
    }

    public function shareToken(): StyleToken
    {
        return $this->shareToken ??= new StyleToken();
    }

    /**
     * Marks this style as shared by many boxes (the cascade cache hands the same object to every identical table
     * cell). A shared style must never change: set() throws; take a clone() (which is not shared) to modify a copy.
     */
    public function share(): self
    {
        $this->shared = true;
        return $this;
    }

    public function isShared(): bool
    {
        return $this->shared;
    }

    /** This style when it is private to its box, otherwise a private copy. */
    public function unshared(): self
    {
        return $this->shared ? clone $this : $this;
    }

    public function __clone()
    {
        $this->shared = false;
    }

    /**
     * `ex` / `ch` bookkeeping, [] when the style declares none: 'p' = properties declared with `ex` / `ch` lengths
     * (key => value), re-resolved when the font changes; 'a' = longhands declared after a pending shorthand
     * (margin-left after `margin: 2ex`), replayed after it.
     *
     * @var array{p?: array<string, string>, a?: array<string, string>}
     */
    private array $exCh = [];

    public function set(string $prop, string $value): void
    {
        $this->assertNotShared();
        $this->shareToken = null;
        $this->setProperty($prop, $value);
        if (($this->exCh['p'] ?? []) !== []) {
            $key = strtolower(trim($prop));
            if ($key === 'font-family' || $key === 'font-weight' || $key === 'font-style' || $key === 'font') {
                // `width: 2ex; font-family: X` -> the unit depends on the element's final font, not the one at declaration time
                $pending = $this->exCh['p'];
                $after = $this->exCh['a'] ?? [];
                $this->exCh = [];
                foreach ($pending as $pk => $pv) {
                    $this->setProperty($pk, $pv);
                }
                // A longhand declared after the shorthand must still win over the re-applied shorthand
                foreach ($after as $ak => $av) {
                    $this->setProperty($ak, $av);
                }
            }
        }
    }

    private function setProperty(string $prop, string $value): void
    {
        $this->materialize();
        $key = strtolower(trim($prop));
        if ($key === 'word-wrap') {
            $key = 'overflow-wrap'; // legacy name, still common in templates (CSS Text 3: an alias)
        }
        $val = trim($value);
        $val = trim((string) preg_replace('/!\s*important$/i', '', $val));

        if (str_starts_with($key, '--')) {
            $this->variables[$key] = $this->resolveVariables($val);
            return;
        }

        $val = $this->resolveVariables($val);
        if (self::$fontUnitResolver !== null && $val !== '' && preg_match('/[\d.](?:ex|ch)(?![a-z0-9_-])/i', $val) === 1) {
            if ($key !== 'font-size' && $key !== 'font') {
                $this->exCh['p'][$key] = $val;
            }
            $val = $this->resolveFontRelativeUnits($key, $val);
        } elseif (($this->exCh['p'] ?? []) !== []) {
            unset($this->exCh['p'][$key]);
            foreach ($this->exCh['p'] as $pk => $_) {
                if (str_starts_with($key, $pk . '-')) {
                    $this->exCh['a'][$key] = $val;
                }
            }
        }
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

        if ($key === 'text-align') {
            // -webkit-center / -webkit-right (and the HTML align attribute) also align shrink-wrapped block children
            if (preg_match('/^-(?:webkit|moz)-(left|center|right)$/', $lowerVal, $wm) === 1) {
                $this->props['text-align'] = $wm[1];
                $this->props['-ivopdf-block-align'] = $wm[1];
                return;
            }
            if (CssValidator::isValid($key, $val)) {
                unset($this->props['-ivopdf-block-align']);
            }
        }

        // CSS error recovery: an invalid value drops the declaration (the earlier cascade value stays)
        if (!CssValidator::isValid($key, $val)) {
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
            case 'grid-area':
            case 'grid-template':
            case 'grid':
                foreach (GridShorthand::expand($key, $val) ?? [] as $longhand => $longValue) {
                    $this->props[$longhand] = $longValue;
                }
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

    /** Rewrites `ex` / `ch` lengths into `em` using the metrics of the element's font (the parent's font for font-size). */
    private function resolveFontRelativeUnits(string $key, string $val): string
    {
        $resolver = self::$fontUnitResolver;
        if ($resolver === null) {
            return $val;
        }
        $src = $key === 'font-size' ? $this->parentProps : $this->props;
        $metrics = $resolver((string) ($src['font-family'] ?? $this->props['font-family'] ?? ''), (string) ($src['font-weight'] ?? 'normal'), (string) ($src['font-style'] ?? 'normal'));
        return (string) preg_replace_callback('/(\d*\.?\d+)(ex|ch)(?![a-z0-9_-])/i', static function (array $m) use ($metrics): string {
            $ratio = strtolower($m[2]) === 'ex' ? $metrics[0] : $metrics[1];
            return rtrim(rtrim(sprintf('%.5f', (float) $m[1] * $ratio), '0'), '.') . 'em';
        }, $val);
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
                CssUnit::clampLength(CssUnit::toPt(
                    $v,
                    $this->parentFontSizePt,
                    $this->parentFontSizePt,
                    $this->rootFontSizePt,
                    $this->parentFontSizePt
                ))
            ),
        };
    }

    private function expandFontShorthand(string $val): void
    {
        $rest = trim($val);
        if ($rest === '') {
            return;
        }
        $lenRe = '(?:\d*\.?\d+(?:[eE][+-]?\d+)?(?:px|pt|em|rem|ex|ch|%|mm|cm|in|pc|q|vw|vh|vmin|vmax)|0)';
        $sizeRe = '(?:' . $lenRe . '|xxx-large|xx-small|x-small|xx-large|x-large|small|medium|large|larger|smaller|calc\([^)]*\))';
        $lhRe = '(?:normal|\d*\.?\d+(?:px|pt|em|rem|ex|ch|%|mm|cm|in|pc|q)?)';
        // CSS Fonts 4: [ <style> || <variant-css2> || <weight> || <stretch-css3> ]? <size> [/ <line-height>]? <family>
        $style = null;
        $weight = null;
        $variant = null;
        $stretch = null;
        $sizeFound = false;
        $size = '';
        $lineHeight = 'normal';
        $family = '';
        $guard = 0;
        $systemFonts = ['caption', 'icon', 'menu', 'message-box', 'small-caption', 'status-bar'];
        if (in_array(strtolower($rest), $systemFonts, true)) {
            // System font keywords: degrade to a plain 13.33px face, reset the rest like the browser does
            $this->props['font-style'] = 'normal';
            $this->props['font-weight'] = 'normal';
            $this->props['font-variant'] = 'normal';
            $this->props['font-stretch'] = 'normal';
            $this->props['line-height'] = 'normal';
            $this->set('font-size', '13.3333px');
            return;
        }
        while ($rest !== '' && $guard++ < 12) {
            if (preg_match('/^(' . $sizeRe . ')(?:\s*\/\s*(' . $lhRe . '))?(?:\s+(.+))?$/is', $rest, $m)) {
                if (!isset($m[3]) || trim($m[3]) === '') {
                    return; // a family is required
                }
                $sizeFound = true;
                $size = $m[1];
                if (isset($m[2]) && $m[2] !== '') {
                    $lineHeight = $m[2];
                }
                $family = trim($m[3]);
                break;
            }
            if (preg_match('/^(\S+)\s*(.*)$/s', $rest, $t) !== 1) {
                return;
            }
            $tok = strtolower($t[1]);
            $rest = $t[2];
            if ($tok === 'normal') {
                continue;
            }
            if ($tok === 'italic' || $tok === 'oblique') {
                $style = $tok;
            } elseif (preg_match('/^(?:bold|bolder|lighter|[1-9]00|1000)$/', $tok) === 1) {
                $weight = $tok;
            } elseif ($tok === 'small-caps') {
                $variant = $tok;
            } elseif (in_array($tok, ['ultra-condensed', 'extra-condensed', 'condensed', 'semi-condensed', 'semi-expanded', 'expanded', 'extra-expanded', 'ultra-expanded'], true)) {
                $stretch = $tok;
            } else {
                return; // invalid: the whole declaration is ignored
            }
        }
        if (!$sizeFound) {
            return;
        }
        // Everything the shorthand does not mention goes back to its initial value
        $this->props['font-style'] = $style ?? 'normal';
        $this->props['font-weight'] = $weight ?? 'normal';
        $this->props['font-variant'] = $variant ?? 'normal';
        $this->props['font-stretch'] = $stretch ?? 'normal';
        $this->props['line-height'] = $lineHeight;
        $this->set('font-size', $size);
        $this->props['font-family'] = $family;
    }

    private function resolveVariables(string $val): string
    {
        if (!str_contains($val, 'var(')) {
            return $val;
        }

        // Values of variables may themselves contain var(): substitute a few rounds
        for ($round = 0; $round < 5 && str_contains($val, 'var('); $round++) {
            $val = $this->substituteVars($val, 0);
        }

        return $val;
    }

    /** Replaces each var(--name[, fallback]) with balanced parentheses, so fallbacks like rgb(0, 0, 0) survive. */
    private function substituteVars(string $val, int $depth): string
    {
        $out = '';
        $pos = 0;
        while (($start = stripos($val, 'var(', $pos)) !== false) {
            // Skip identifiers that merely end in "var(" (e.g. a hypothetical "somevar(")
            if ($start > 0 && preg_match('/[A-Za-z0-9_-]/', $val[$start - 1]) === 1) {
                $out .= substr($val, $pos, $start + 4 - $pos);
                $pos = $start + 4;
                continue;
            }
            $level = 1;
            $comma = -1;
            $len = strlen($val);
            $i = $start + 4;
            for (; $i < $len && $level > 0; $i++) {
                $ch = $val[$i];
                if ($ch === '(') {
                    $level++;
                } elseif ($ch === ')') {
                    $level--;
                } elseif ($ch === ',' && $level === 1 && $comma < 0) {
                    $comma = $i;
                }
            }
            if ($level > 0) {
                break; // unbalanced: leave the rest untouched
            }
            $end = $i - 1; // closing parenthesis
            $nameEnd = $comma >= 0 ? $comma : $end;
            $name = strtolower(trim(substr($val, $start + 4, $nameEnd - $start - 4)));
            if (isset($this->variables[$name])) {
                $replacement = $this->variables[$name];
            } elseif ($comma >= 0) {
                $replacement = trim(substr($val, $comma + 1, $end - $comma - 1));
                if ($depth < 16 && str_contains($replacement, 'var(')) {
                    $replacement = $this->substituteVars($replacement, $depth + 1);
                }
            } else {
                $replacement = '';
            }
            $out .= substr($val, $pos, $start - $pos) . $replacement;
            $pos = $end + 1;
        }
        return $out . substr($val, $pos);
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
            if (in_array($tl, ['none', 'hidden', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset'], true)) {
                $style = $tl; // `hidden` (collapsed table borders: it suppresses every border of the edge) is a style, not a colour
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

        // Multiple layers (painted first = top): each layer has its own image / position / size / repeat, the colour
        // can only be given in the last layer. The longhands become comma-separated lists, one entry per layer.
        $layers = $this->splitTopLevel($val, ',');
        $images = [];
        $positions = [];
        $sizes = [];
        $repeats = [];
        foreach ($layers as $li => $layer) {
            $image = 'none';
            $remaining = [];
            foreach ($this->splitSpaceTokens($layer) as $tok) {
                $tl = strtolower($tok);
                if (str_starts_with($tl, 'url(') || str_contains($tl, 'gradient(')) {
                    if ($image === 'none') {
                        $image = str_starts_with($tl, 'url(')
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
                    if ($li === count($layers) - 1 || $li === 0) {
                        $this->props['background-color'] = $tok;
                    }
                }
            }
            $images[] = $image;
            $positions[] = empty($positionTokens) ? '0% 0%' : implode(' ', $positionTokens);
            $sizes[] = empty($sizeTokens) ? 'auto' : implode(' ', $sizeTokens);
            $repeats[] = empty($repeatTokens) ? 'repeat' : implode(' ', $repeatTokens);
        }

        // A single layer keeps the plain values; several layers keep one entry per layer (layers without an image are
        // dropped: they paint nothing)
        $keep = array_keys(array_filter($images, static fn(string $i): bool => $i !== 'none'));
        if (count($layers) > 1 && count($keep) > 1) {
            $this->props['background-image'] = implode(', ', array_map(static fn(int $k): string => $images[$k], $keep));
            $this->props['background-position'] = implode(', ', array_map(static fn(int $k): string => $positions[$k], $keep));
            $this->props['background-size'] = implode(', ', array_map(static fn(int $k): string => $sizes[$k], $keep));
            $this->props['background-repeat'] = implode(', ', array_map(static fn(int $k): string => $repeats[$k], $keep));
            return;
        }
        $k = $keep[0] ?? 0;
        $this->props['background-image'] = $images[$k];
        $this->props['background-position'] = $positions[$k];
        $this->props['background-size'] = $sizes[$k];
        $this->props['background-repeat'] = $repeats[$k];
    }

    private function isBackgroundSizeToken(string $tok): bool
    {
        $tl = strtolower($tok);
        return in_array($tl, ['cover', 'contain', 'auto'], true) || (bool) preg_match('/^(\.?\d|calc\()/', $tl);
    }

    private function expandListStyleShorthand(string $val): void
    {
        // A shorthand sets every longhand: those not given return to their initial values (`list-style: square`
        // on an item of `ul { list-style-position: inside }` puts the marker outside again, as in browsers)
        $type = null;
        $position = 'outside';
        $image = 'none';
        $none = false;
        foreach ($this->splitSpaceTokens($val) as $tok) {
            $tl = strtolower($tok);
            if ($tl === 'inside' || $tl === 'outside') {
                $position = $tl;
            } elseif (str_starts_with($tl, 'url(')) {
                $image = $tok;
            } elseif ($tl === 'none') {
                $none = true; // the type and / or the image, whichever is not given otherwise
            } else {
                $type = $tl;
            }
        }
        $this->props['list-style-type'] = $type ?? ($none ? 'none' : 'disc');
        $this->props['list-style-position'] = $position;
        $this->props['list-style-image'] = $image;
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
        if (preg_match('/(?:-webkit-)?(?:repeating-)?(?:linear|radial|conic)-gradient\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)|url\([^)]*\)/i', $val, $m, PREG_OFFSET_CAPTURE)) {
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
            // a lone identifier (grid-column: main) names both edges
            $this->props["grid-{$axis}-end"] = preg_match('/^[A-Za-z_][\w\-]*$/', trim($val)) === 1 && strtolower(trim($val)) !== 'auto'
                ? trim($val) : 'auto';
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
