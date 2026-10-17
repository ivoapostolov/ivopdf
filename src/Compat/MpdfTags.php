<?php

declare(strict_types=1);

namespace IvoPdf\Compat;

/**
 * HTML pre-pass translating mPDF's custom tags into plain HTML/CSS that IvoPDF's engine understands:
 *
 *  - `<pagebreak>` (orientation, sheet-size, margin-*, header/footer names, resetpagenum, pagenumstyle, suppress, type)
 *    -> a break element carrying `page: ivo-pgN` plus a generated `@page ivo-pgN` rule
 *  - `<htmlpageheader>` / `<htmlpagefooter>` definitions and `<sethtmlpageheader>` / `<sethtmlpagefooter>` switches
 *  - `<bookmark>`, `<annotation>`, `<indexentry>` -> zero-size marker elements picked up by the PDF renderer
 *  - `<indexinsert>` -> the sorted index, `<watermarktext>` / `<watermarkimage>` -> document state
 *  - `<columns>` / `<columnbreak>` -> CSS multi-column; `rotate=""` on tables / images -> CSS transform
 *
 * Page numbers of markers are only known after pagination, so IvoPdf re-runs the pass (see IvoPdf::render()).
 */
final class MpdfTags
{
    private const MARKER_STYLE = 'height:0;margin:0;padding:0;border:0;font-size:0;line-height:0;';
    private const INLINE_MARKER_STYLE = 'display:inline-block;width:0;height:0;margin:0;padding:0;border:0;font-size:0;line-height:0;';

    /** Opening tags after which a marker has to stay inline (text flow) instead of being a block. */
    private const INLINE_TAGS = ['a', 'b', 'i', 'u', 'em', 'strong', 'span', 'font', 'small', 'big', 'sub', 'sup', 'label', 'code', 'abbr', 'cite', 'q', 's', 'strike'];

    private const TAG_RE = '~<(/?)(sethtmlpageheader|sethtmlpagefooter|tocpagebreak|pagebreak|bookmark|indexentry|indexinsert|annotation|watermarktext|watermarkimage|columns|columnbreak|column_break|newcolumn|dottab|textcircle)\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>~is';

    public static function needed(string $html): bool
    {
        return (bool) preg_match('~<(?:/?)(?:pagebreak|tocpagebreak|htmlpage(?:header|footer)|sethtmlpage(?:header|footer)|bookmark|indexentry|indexinsert|annotation|watermarktext|watermarkimage|columns|columnbreak|column_break|newcolumn|dottab|textcircle)\b|\b(?:rotate|overflow)\s*=|@page[^{]*\{[^}]*(?:header|footer)\s*:~i', $html);
    }

    /**
     * @param array{ix?: array<string, string>, blank?: array<string, bool>} $mem results of the previous pass
     */
    public static function transform(string $html, MpdfState $s, array $mem = []): string
    {
        if (!self::needed($html)) {
            return $html;
        }

        // Hide <script>, <style> and comments (style blocks are scanned for @page header/footer names first)
        $masked = [];
        $work = (string) preg_replace_callback(
            '~<script\b.*?</script>|<style\b.*?</style>|<!--.*?-->~is',
            static function (array $m) use (&$masked, $s): string {
                if (stripos($m[0], '<style') === 0) {
                    self::scanPageRules($m[0], $s);
                }
                $masked[] = $m[0];
                return "\x01" . (count($masked) - 1) . "\x02";
            },
            $html
        );

        // Named running headers / footers: removed from the flow
        $work = (string) preg_replace_callback(
            '~<htmlpage(header|footer)\b([^>]*)>(.*?)</htmlpage\1\s*>~is',
            static function (array $m) use ($s): string {
                $a = self::attrs($m[2]);
                $name = self::cleanName($a['name'] ?? '');
                if ($name !== '') {
                    if (strtolower($m[1]) === 'header') {
                        $s->headers[$name] = $m[3];
                    } else {
                        $s->footers[$name] = $m[3];
                    }
                }
                return '';
            },
            $work
        );

        $counters = ['pb' => 0, 'ix' => 0, 'sh' => 0, 'an' => 0, 'bm' => 0];
        $columnsOpen = false;
        $indexTags = [];

        $subject = $work;
        $work = (string) preg_replace_callback(
            self::TAG_RE,
            static function (array $m) use ($s, $mem, &$counters, &$columnsOpen, &$indexTags, $subject): string {
                $closing = $m[1][0] === '/';
                $tag = strtolower($m[2][0]);
                $a = self::attrs($m[3][0]);
                $inline = self::inlineContext($subject, (int) $m[0][1]);
                switch ($tag) {
                    case 'pagebreak':
                        return $closing ? '' : self::pageBreak($a, $s, $mem, $counters);

                    case 'tocpagebreak':
                        return $closing ? '' : self::tocPageBreak($a, $s, $mem, $counters);

                    case 'sethtmlpageheader':
                    case 'sethtmlpagefooter':
                        if ($closing) {
                            return '';
                        }
                        $id = 'ivo-sh-' . (++$counters['sh']);
                        $kind = $tag === 'sethtmlpageheader' ? 'hdr' : 'ftr';
                        $side = strtoupper(substr(trim($a['page'] ?? 'ALL'), 0, 1));
                        $name = self::cleanName($a['name'] ?? '');
                        $on = !in_array(strtolower(trim($a['value'] ?? 'on')), ['off', '0', '-1', 'false'], true);
                        $val = $on ? $name : '';
                        if ($on && $name === '') {
                            return ''; // no name and not "off": nothing to switch
                        }
                        $sel = ['O' => null, 'E' => null];
                        foreach (['O', 'E'] as $sd) {
                            if ($side === 'A' || $side === $sd || $side === '') {
                                $sel[$sd] = $val;
                            }
                        }
                        $s->events[] = [
                            'id' => $id,
                            'kind' => 'set',
                            'show' => in_array(strtolower(trim($a['show-this-page'] ?? '0')), ['1', 'on', 'true'], true),
                            $kind => $sel,
                        ];
                        return '<div id="' . $id . '" style="' . self::MARKER_STYLE . '"></div>';

                    case 'bookmark':
                        if ($closing) {
                            return '';
                        }
                        $counters['bm']++;
                        $level = max(0, (int) ($a['level'] ?? 0));
                        return self::marker($inline, 'ivo-bm-' . $counters['bm'], 'data-ivo-bookmark="' . self::e($a['content'] ?? '') . '" data-ivo-level="' . $level . '"');

                    case 'annotation':
                        if ($closing) {
                            return '';
                        }
                        $counters['an']++;
                        $attrs = 'data-ivo-annot="' . self::e($a['content'] ?? '') . '"'
                            . ' data-ivo-title="' . self::e($a['title'] ?? '') . '"'
                            . ' data-ivo-icon="' . self::e($a['icon'] ?? 'Note') . '"'
                            . ' data-ivo-color="' . self::e($a['color'] ?? '') . '"'
                            . ' data-ivo-opacity="' . self::e($a['opacity'] ?? '1') . '"';
                        foreach (['pos-x', 'pos-y'] as $pk) {
                            if (isset($a[$pk])) {
                                $attrs .= ' data-ivo-' . $pk . '="' . self::e($a[$pk]) . '"';
                            }
                        }
                        return self::marker($inline, 'ivo-an-' . $counters['an'], $attrs);

                    case 'indexentry':
                        if ($closing) {
                            return '';
                        }
                        $id = 'ivo-ix-' . (++$counters['ix']);
                        $term = trim($a['content'] ?? '');
                        if ($term !== '') {
                            $s->indexEntries[$id] = $term;
                        }
                        return self::marker($inline, $id, '');

                    case 'indexinsert':
                        if ($closing) {
                            return '';
                        }
                        $indexTags[] = $a;
                        return "\x03" . (count($indexTags) - 1) . "\x04";

                    case 'watermarktext':
                        if (!$closing && ($a['content'] ?? '') !== '') {
                            $s->watermarkText = ['content' => $a['content'], 'alpha' => self::alpha($a['alpha'] ?? '0.2')];
                        }
                        return '';

                    case 'watermarkimage':
                        if (!$closing && ($a['src'] ?? '') !== '') {
                            $s->watermarkImage = [
                                'src' => $a['src'],
                                'alpha' => self::alpha($a['alpha'] ?? '0.2'),
                                'size' => $a['size'] ?? 'D',
                                'pos' => $a['position'] ?? 'P',
                            ];
                        }
                        return '';

                    case 'columns':
                        $out = '';
                        if ($columnsOpen) {
                            $out = '</div>';
                            $columnsOpen = false;
                        }
                        if ($closing) {
                            return $out;
                        }
                        $count = (int) ($a['column-count'] ?? 1);
                        if ($count > 1) {
                            $gap = isset($a['column-gap']) && is_numeric($a['column-gap']) ? (float) $a['column-gap'] : 5.0;
                            $fill = in_array(strtoupper(trim($a['valign'] ?? $a['vertical-align'] ?? '')), ['J', 'JUSTIFY', 'BALANCE'], true) ? 'balance' : 'auto';
                            $columnsOpen = true;
                            $out .= '<div class="ivo-columns" style="column-count:' . $count . ';column-gap:' . $gap . 'mm;column-fill:' . $fill . '">';
                        }
                        return $out;

                    case 'dottab':
                        if ($closing) {
                            return '';
                        }
                        if (!str_contains($s->pageCss, MpdfExtraTags::DOTTAB_CSS)) {
                            $s->pageCss .= MpdfExtraTags::DOTTAB_CSS;
                        }
                        return MpdfExtraTags::dotTab($a, $s);

                    case 'textcircle':
                        return $closing ? '' : MpdfExtraTags::textCircle($a, $s);

                    case 'columnbreak':
                    case 'column_break':
                    case 'newcolumn':
                        return $closing ? '' : '<div style="break-before:column;margin:0;padding:0;font-size:0.1pt;line-height:0.1pt">&#160;</div>';
                }
                return $m[0][0];
            },
            $work,
            -1,
            $count,
            PREG_OFFSET_CAPTURE
        );
        if ($columnsOpen) {
            $pos = strripos($work, '</body>');
            $work = $pos !== false ? substr($work, 0, $pos) . '</div>' . substr($work, $pos) : $work . '</div>';
        }

        // Index placeholders (entries of the whole document are known now)
        if ($indexTags !== []) {
            $work = (string) preg_replace_callback(
                "~\x03(\d+)\x04~",
                static fn(array $m): string => MpdfIndex::build($s->indexEntries, $mem['ix'] ?? [], $indexTags[(int) $m[1]]),
                $work
            );
        }

        // rotate="90" on tables / images -> CSS transform (anticlockwise like mPDF); overflow="shrink|hidden|visible" on tables -> CSS overflow
        if (stripos($work, 'rotate') !== false || stripos($work, 'overflow') !== false) {
            $work = (string) preg_replace_callback(
                '~<(table|img|div)\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>~i',
                static function (array $m): string {
                    $css = '';
                    $attrs = $m[2];
                    if (preg_match('~\srotate\s*=\s*(?:"(-?\d+)"|\'(-?\d+)\'|(-?\d+))~i', $attrs, $rm)) {
                        $deg = (int) ($rm[1] !== '' ? $rm[1] : ($rm[2] !== '' ? $rm[2] : ($rm[3] ?? 0)));
                        $attrs = (string) preg_replace('~\srotate\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $attrs);
                        $css .= 'transform:rotate(' . (-$deg) . 'deg);';
                    }
                    if (strtolower($m[1]) === 'table' && preg_match('~\soverflow\s*=\s*(?:"(shrink|hidden|visible|auto)"|\'(shrink|hidden|visible|auto)\')~i', $attrs, $om)) {
                        $attrs = str_replace($om[0], '', $attrs);
                        $css .= 'overflow:' . strtolower($om[1] !== '' ? $om[1] : $om[2]) . ';';
                    }
                    if ($css === '') {
                        return $m[0];
                    }
                    if (preg_match('~\sstyle\s*=\s*(["\'])(.*?)\1~is', $attrs, $sm)) {
                        $attrs = str_replace($sm[0], ' style=' . $sm[1] . rtrim($sm[2], '; ') . ';' . $css . $sm[1], $attrs);
                    } else {
                        $attrs .= ' style="' . $css . '"';
                    }
                    return '<' . $m[1] . $attrs . '>';
                },
                $work
            );
        }

        if ($counters['bm'] > 0) {
            // Explicit <bookmark> tags replace the automatic heading outline (as in mPDF)
            $work = (string) preg_replace('~<h([1-6])\b(?![^>]*data-ivo-nobm)~i', '<h$1 data-ivo-nobm="1"', $work);
        }

        $work = (string) preg_replace_callback("~\x01(\d+)\x02~", static fn(array $m): string => $masked[(int) $m[1]], $work);
        return self::injectStyle($work, $s->pageCss);
    }

    /**
     * @param array<string, string> $a
     * @param array{ix?: array<string, string>, blank?: array<string, bool>} $mem
     * @param array<string, int> $counters
     */
    private static function pageBreak(array $a, MpdfState $s, array $mem, array &$counters): string
    {
        $id = 'ivo-pb-' . (++$counters['pb']);

        // Geometry: a named @page rule for this section
        $decl = '';
        $orient = strtoupper(substr(trim($a['orientation'] ?? ''), 0, 1));
        $orientWord = $orient === 'L' ? 'landscape' : ($orient === 'P' ? 'portrait' : '');
        $sheet = trim($a['sheet-size'] ?? $a['newformat'] ?? '');
        if ($sheet !== '') {
            if (preg_match('~^([A-Za-z][A-Za-z0-9]*)(?:-([LP]))?$~', $sheet, $sm)) {
                $o = isset($sm[2]) ? (strtoupper($sm[2]) === 'L' ? 'landscape' : 'portrait') : $orientWord;
                $decl .= 'size:' . $sm[1] . ($o !== '' ? ' ' . $o : '') . ';';
            } elseif (preg_match('~^([\d.]+)\s*(?:mm)?\s*[, ]\s*([\d.]+)\s*(?:mm)?$~', $sheet, $sm)) {
                [$w, $h] = $orientWord === 'landscape' ? [max((float) $sm[1], (float) $sm[2]), min((float) $sm[1], (float) $sm[2])] : [(float) $sm[1], (float) $sm[2]];
                $decl .= 'size:' . $w . 'mm ' . $h . 'mm;';
            }
        } elseif ($orientWord !== '') {
            $decl .= 'size:' . $orientWord . ';';
        }
        foreach (['left', 'right', 'top', 'bottom'] as $side) {
            $v = $a['margin-' . $side] ?? null;
            if ($v !== null && is_numeric(rtrim(trim($v), 'mm'))) {
                $decl .= 'margin-' . $side . ':' . (float) rtrim(trim($v), 'mm') . 'mm;';
            }
        }

        $style = '';
        if ($decl !== '') {
            $name = self::pageName($s, $decl);
            $style = 'page:' . $name . ';';
        }

        // Header / footer / page-number changes taking effect on the new page
        $ev = ['id' => $id, 'kind' => 'pb', 'hdr' => ['O' => null, 'E' => null], 'ftr' => ['O' => null, 'E' => null], 'reset' => null, 'style' => null, 'suppress' => null];
        foreach (['header' => 'hdr', 'footer' => 'ftr'] as $word => $key) {
            foreach (['odd' => 'O', 'even' => 'E'] as $parity => $side) {
                $name = $a["{$parity}-{$word}-name"] ?? null;
                $val = $a["{$parity}-{$word}-value"] ?? null;
                if ($name === null && $val === null) {
                    continue;
                }
                $on = !in_array(strtolower(trim((string) $val)), ['0', '-1', 'off', 'false'], true);
                if (!$on) {
                    $ev[$key][$side] = '';
                } elseif ($name !== null && self::cleanName($name) !== '') {
                    $ev[$key][$side] = self::cleanName($name);
                }
            }
        }
        if (isset($a['resetpagenum']) && is_numeric($a['resetpagenum'])) {
            $ev['reset'] = (int) $a['resetpagenum'];
        }
        if (isset($a['pagenumstyle']) && in_array($a['pagenumstyle'], ['1', 'A', 'a', 'I', 'i'], true)) {
            $ev['style'] = $a['pagenumstyle'];
        }
        if (isset($a['suppress'])) {
            $sv = strtolower(trim($a['suppress']));
            $ev['suppress'] = in_array($sv, ['on', '1', 'true', 'yes'], true) ? true : (in_array($sv, ['off', '0', '-1', 'false', 'no'], true) ? false : null);
        }
        $s->events[] = $ev;

        // NEXT-ODD / NEXT-EVEN: a blank filler page when the previous pass landed on the wrong side
        $filler = '';
        $type = strtoupper(trim($a['type'] ?? $a['condition'] ?? ''));
        if (in_array($type, ['NEXT-ODD', 'ODD', 'O', 'NEXT-EVEN', 'EVEN', 'E'], true)) {
            $s->events[count($s->events) - 1]['type'] = str_contains($type, 'ODD') || $type === 'O' ? 'NEXT-ODD' : 'NEXT-EVEN';
            if (!empty($mem['blank'][$id])) {
                $filler = '<div style="page-break-before:always;height:6pt;margin:0;padding:0;font-size:6pt;line-height:6pt">&#160;</div>';
            }
        }

        return $filler . '<pagebreak id="' . $id . '"' . ($style !== '' ? ' style="' . $style . '"' : '') . ' />';
    }

    /**
     * `<tocpagebreak toc-orientation=".." resetpagenum="1" toc-preHTML="..">` -> TOC pagebreak + `<toc>` + pagebreak of the
     * following page. `toc-` prefixed page attributes apply to the TOC page, plain ones to the page after the TOC.
     *
     * @param array<string, string> $a
     * @param array{ix?: array<string, string>, blank?: array<string, bool>} $mem
     * @param array<string, int> $counters
     */
    private static function tocPageBreak(array $a, MpdfState $s, array $mem, array &$counters): string
    {
        $pbKey = '~^(orientation|sheet-size|margin-(?:left|right|top|bottom)|resetpagenum|pagenumstyle|suppress|type|(?:odd|even)-(?:header|footer)-(?:name|value))$~';
        $toc = [];
        $after = [];
        $rest = '';
        foreach ($a as $k => $v) {
            if (str_starts_with($k, 'toc-') && preg_match($pbKey, substr($k, 4)) === 1) {
                $toc[substr($k, 4)] = $v;
            } elseif (preg_match($pbKey, $k) === 1) {
                $after[$k] = $v;
            } else {
                $rest .= ' ' . $k . '="' . self::e($v) . '"';
            }
        }
        // <toc> itself does not break pages: the content after the TOC starts on a new page
        return self::pageBreak($toc, $s, $mem, $counters) . '<toc' . $rest . ' />' . self::pageBreak($after, $s, $mem, $counters);
    }

    /** Registers (or reuses) a named `@page` rule for the declaration block and returns its name. */
    private static function pageName(MpdfState $s, string $decl): string
    {
        $sig = md5($decl);
        $name = 'ivo-pg-' . substr($sig, 0, 8);
        if (!str_contains($s->pageCss, '@page ' . $name . ' ')) {
            $s->pageCss .= '@page ' . $name . ' {' . $decl . '}';
        }
        return $name;
    }

    /** Reads `header: html_x` / `odd-header-name: x` style declarations from `@page { }` rules of a <style> block. */
    private static function scanPageRules(string $styleBlock, MpdfState $s): void
    {
        if (!preg_match_all('~@page\s*([^{]*)\{([^{}]*)\}~i', $styleBlock, $rules, PREG_SET_ORDER)) {
            return;
        }
        foreach ($rules as $r) {
            $selector = strtolower(preg_replace('~\s+~', '', $r[1]) ?? '');
            if ($selector !== '') {
                continue; // :first / named page rules keep the engine's own handling
            }
            foreach (['header' => 'baseHeader', 'footer' => 'baseFooter'] as $word => $prop) {
                $names = ['O' => null, 'E' => null];
                if (preg_match('~(?<![\w-])' . $word . '\s*:\s*([^;}]+)~i', $r[2], $dm)) {
                    $n = self::cleanName($dm[1]);
                    $names = ['O' => $n, 'E' => $n];
                }
                foreach (['odd' => 'O', 'even' => 'E'] as $parity => $side) {
                    if (preg_match('~' . $parity . '-' . $word . '-name\s*:\s*([^;}]+)~i', $r[2], $dm)) {
                        $names[$side] = self::cleanName($dm[1]);
                    }
                    if (preg_match('~' . $parity . '-' . $word . '-value\s*:\s*([^;}]+)~i', $r[2], $dm)
                        && in_array(strtolower(trim($dm[1])), ['0', '-1', 'off'], true)) {
                        $names[$side] = '';
                    }
                }
                foreach (['O', 'E'] as $side) {
                    if ($names[$side] !== null) {
                        $s->{$prop}[$side] = $names[$side];
                    }
                }
            }
        }
    }

    /** `html_MyHeader` / "MyHeader" / 'MyHeader' -> MyHeader ('none' / 'off' -> ''). */
    public static function cleanName(string $n): string
    {
        $n = trim($n, " \t\r\n'\"");
        if (preg_match('~^html_(.*)$~i', $n, $m)) {
            $n = $m[1];
        }
        return in_array(strtolower($n), ['none', 'off'], true) ? '' : $n;
    }

    /** A zero-size marker: block-level between blocks, inline-block inside running text. */
    private static function marker(bool $inline, string $id, string $extraAttrs): string
    {
        return '<span id="' . $id . '" ' . $extraAttrs . ' style="' . ($inline ? self::INLINE_MARKER_STYLE : self::MARKER_STYLE . 'display:block') . '"></span>';
    }

    /** True when the tag sits inside running text (preceded by text or an inline tag) rather than between blocks. */
    private static function inlineContext(string $subject, int $offset): bool
    {
        $before = rtrim(substr($subject, max(0, $offset - 300), min($offset, 300)));
        if ($before === '') {
            return false;
        }
        if (!str_ends_with($before, '>')) {
            return !in_array(substr($before, -1), ["", ""], true);
        }
        if (preg_match('~<(/?)([a-z][a-z0-9]*)\b[^<>]*>$~i', $before, $tm) !== 1) {
            return false;
        }
        return in_array(strtolower($tm[2]), self::INLINE_TAGS, true);
    }

    private static function alpha(string $v): float
    {
        return is_numeric($v) ? max(0.01, min(1.0, (float) $v)) : 0.2;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function injectStyle(string $html, string $css): string
    {
        if ($css === '') {
            return $html;
        }
        $style = '<style>' . $css . '</style>';
        if (preg_match('~<head\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $pos) . $style . substr($html, $pos);
        }
        if (preg_match('~<html\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $pos) . '<head>' . $style . '</head>' . substr($html, $pos);
        }
        return $style . $html;
    }

    /**
     * @return array<string, string> lower-case attribute names => decoded values
     */
    public static function attrs(string $s): array
    {
        $out = [];
        if (preg_match_all('/([\w:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/', $s, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $v = $m[2] !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''));
                $out[strtolower($m[1])] = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return $out;
    }
}
