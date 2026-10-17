<?php

declare(strict_types=1);

namespace IvoPdf\Paged;

/**
 * Table-of-contents support at HTML level (runs before the DOM is built):
 *  - `<toc />` / `<toc levels="2" title="Contents" />` placeholders and mPDF `<tocpagebreak>` (TOC on its own pages)
 *  - `<tocentry content="Text" level="1" />` for manual entries
 *  - automatic insertion at the start of the document (IvoPdf::setTableOfContents)
 * Entries are links to the headings (ids are added when missing) whose page numbers come from `target-counter()`,
 * so one layout pass is enough: the placeholder numbers have a fixed width and are substituted after pagination.
 */
final class TocGenerator
{
    public const CSS = '.ivo-toc{margin:0 0 14pt 0}'
        . '.ivo-toc-title{font-size:18pt;font-weight:bold;margin:0 0 10pt 0}'
        . '.ivo-toc-entry{margin:2.5pt 0}'
        . '.ivo-toc-l1{font-weight:bold;margin-top:6pt}'
        . '.ivo-toc-entry a{color:inherit;text-decoration:none}'
        . ".ivo-toc-entry a::after{content:leader('.') target-counter(attr(href), page)}";

    public static function needed(string $html, bool $auto): bool
    {
        return $auto || stripos($html, '<toc') !== false;
    }

    /**
     * @param bool   $auto     insert a TOC at the start when the HTML has no <toc> placeholder
     * @param string $title    heading above the TOC (default for placeholders without a title attribute)
     * @param int    $maxLevel deepest heading level listed (1-6)
     */
    public static function process(string $html, bool $auto, string $title = '', int $maxLevel = 3): string
    {
        if (!self::needed($html, $auto)) {
            return $html;
        }

        // Hide <script>, <style> and comments from the heading scan
        $masked = [];
        $masked_html = (string) preg_replace_callback(
            '~<script\b.*?</script>|<style\b.*?</style>|<!--.*?-->~is',
            static function (array $m) use (&$masked): string {
                $masked[] = $m[0];
                return "\x01" . (count($masked) - 1) . "\x02";
            },
            $html
        );

        $placeholders = preg_match_all('~<toc(?:pagebreak)?\b~i', $masked_html);
        if (!$placeholders && !$auto) {
            return $html;
        }

        // 1. Collect entries (headings + <tocentry>) in document order, add ids and anchors
        $entries = [];
        $counter = 0;
        $maxLevel = max(1, min(6, $maxLevel));
        $scanLevel = 6; // placeholders may ask for more levels than the default
        $zeroBased = preg_match('~<tocentry\b[^>]*\blevel\s*=\s*["\']?0\b~i', $masked_html) === 1;
        // mPDF: a document that marks its entries with <tocentry> gets exactly those entries (headings are not added);
        // the heading scan is the <toc> / setTableOfContents() mode of documents without <tocentry>
        $manual = stripos($masked_html, '<tocentry') !== false;
        $masked_html = (string) preg_replace_callback(
            '~<h([1-6])\b([^>]*)>(.*?)</h\1\s*>|<tocentry\b([^>]*?)/?>(?:\s*</tocentry>)?~is',
            static function (array $m) use (&$entries, &$counter, $scanLevel, $zeroBased, $manual): string {
                if (($m[4] ?? '') !== '' || stripos($m[0], '<tocentry') === 0) {
                    $attrs = self::attrs($m[4] ?? '');
                    $text = trim(html_entity_decode($attrs['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $level = max(1, min(6, \IvoPdf\Dom\InputGuards::intAttr($attrs['level'] ?? null, -1, 99, $zeroBased ? 0 : 1) + ($zeroBased ? 1 : 0) ?: 1));
                    $id = 'ivo-toc-' . (++$counter);
                    if ($text !== '') {
                        $entries[] = ['id' => $id, 'level' => $level, 'text' => $text];
                    }
                    return '<div id="' . $id . '" style="height:0;margin:0;padding:0;border:0;font-size:0;line-height:0"></div>';
                }

                $level = (int) $m[1];
                if ($level > $scanLevel || $manual) { // <tocentry> lists replace the heading scan
                    return $m[0];
                }
                $attrString = $m[2];
                $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if (preg_match('/\bid\s*=\s*(["\'])(.*?)\1/is', $attrString, $im)) {
                    $id = $im[2];
                } else {
                    $id = 'ivo-toc-' . (++$counter);
                    $attrString .= ' id="' . $id . '"';
                }
                if ($text !== '') {
                    $entries[] = ['id' => $id, 'level' => $level, 'text' => $text];
                }
                return '<h' . $level . $attrString . '>' . $m[3] . '</h' . $level . '>';
            },
            $masked_html
        );

        // 2. Replace placeholders
        $used = false;
        $masked_html = (string) preg_replace_callback(
            '~<(toc|tocpagebreak)\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)/?>(?:\s*</\1>)?~i',
            static function (array $m) use (&$used, $entries, $title, $maxLevel): string {
                $used = true;
                $attrs = self::attrs($m[2]);
                $levels = \IvoPdf\Dom\InputGuards::intAttr($attrs['levels'] ?? $attrs['maxlevel'] ?? null, 0, 6, $maxLevel);
                $ttl = array_key_exists('title', $attrs) ? $attrs['title'] : $title;
                $pb = strtolower($m[1]) === 'tocpagebreak';
                // mPDF: toc-preHTML / toc-postHTML (HTML before / after the entries), toc-bookmarkText (outline entry)
                $extra = [
                    'pre' => html_entity_decode($attrs['toc-prehtml'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'post' => html_entity_decode($attrs['toc-posthtml'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'bookmark' => html_entity_decode($attrs['toc-bookmarktext'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ];
                return self::build($entries, $ttl, $levels > 0 ? $levels : $maxLevel, $pb, $pb, $extra);
            },
            $masked_html
        );

        if (!$used) {
            $toc = self::build($entries, $title, $maxLevel, false, true);
            if (preg_match('~<body\b[^>]*>~i', $masked_html, $bm, PREG_OFFSET_CAPTURE)) {
                $pos = $bm[0][1] + strlen($bm[0][0]);
                $masked_html = substr($masked_html, 0, $pos) . $toc . substr($masked_html, $pos);
            } else {
                $masked_html = $toc . $masked_html;
            }
        }

        // 3. Restore masked blocks
        $masked_html = (string) preg_replace_callback(
            "~\x01(\d+)\x02~",
            static fn(array $m): string => $masked[(int) $m[1]],
            $masked_html
        );

        return self::injectStyle($masked_html);
    }

    /**
     * @param list<array{id: string, level: int, text: string}> $entries
     */
    private static function build(array $entries, string $title, int $maxLevel, bool $pageBefore = false, bool $pageAfter = true, array $extra = []): string
    {
        $bookmark = trim((string) ($extra['bookmark'] ?? ''));
        $out = '<div class="ivo-toc" style="' . ($pageBefore ? 'page-break-before:always;' : '') . 'page-break-after:' . ($pageAfter ? 'always;margin-bottom:0' : 'auto') . '"'
            . ($bookmark !== '' ? ' data-ivo-bookmark="' . htmlspecialchars($bookmark, ENT_QUOTES, 'UTF-8') . '" data-ivo-level="0"' : '') . '>';
        $out .= (string) ($extra['pre'] ?? '');
        if (trim($title) !== '') {
            $out .= '<div class="ivo-toc-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        $base = 6;
        foreach ($entries as $e) {
            if ($e['level'] <= $maxLevel) {
                $base = min($base, $e['level']);
            }
        }
        foreach ($entries as $e) {
            if ($e['level'] > $maxLevel) {
                continue;
            }
            $rel = $e['level'] - $base + 1;
            $indent = max(0, $rel - 1) * 14;
            $out .= '<div class="ivo-toc-entry ivo-toc-l' . $rel . '" style="margin-left:' . $indent . 'pt">'
                . '<a href="#' . htmlspecialchars($e['id'], ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($e['text'], ENT_QUOTES, 'UTF-8') . '</a></div>';
        }
        return $out . (string) ($extra['post'] ?? '') . '</div>';
    }

    private static function injectStyle(string $html): string
    {
        $style = '<style>' . self::CSS . '</style>';
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
     * @return array<string, string>
     */
    private static function attrs(string $s): array
    {
        $out = [];
        if (preg_match_all('/([\w:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/', $s, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $out[strtolower($m[1])] = $m[2] !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''));
            }
        }
        return $out;
    }
}
