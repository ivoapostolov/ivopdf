<?php

declare(strict_types=1);

namespace IvoPdf\Dom;

/**
 * Converts HTML input to UTF-8 before parsing (Options::$inputEncoding).
 *
 * 'auto' heuristic:
 *   1. a BOM (UTF-8, UTF-16 LE/BE) decides;
 *   2. otherwise a `<meta charset>` / `<meta http-equiv=Content-Type content="...charset=X">` found in the first
 *      4 KB decides (as browsers do) - but only when the bytes are NOT already valid UTF-8, because a wrong/legacy
 *      meta on content that is really UTF-8 is common in templates;
 *   3. otherwise valid UTF-8 is left unchanged;
 *   4. otherwise (invalid UTF-8, no meta) the text is sniffed: mostly bytes 0xC0-0xFF (Cyrillic letters) means
 *      Windows-1251, anything else Windows-1252 (the browsers' default for legacy content); invalid sequences
 *      are replaced by U+FFFD when nothing else applies.
 * Any other value is taken as an explicit charset name (e.g. 'windows-1251', 'ISO-8859-2'), 'utf-8' only scrubs.
 */
final class InputEncoding
{
    public static function toUtf8(string $html, string $mode = 'auto'): string
    {
        return self::neutralizeMeta(self::detectAndConvert($html, $mode));
    }

    /** After conversion the text is UTF-8: a leftover `charset=windows-1251` would mislead libxml's HTML4 parser. */
    private static function neutralizeMeta(string $html): string
    {
        $head = substr($html, 0, 4096);
        if (stripos($head, 'charset') === false) {
            return $html;
        }
        $fixed = preg_replace_callback(
            '~(<meta\b[^>]*?\bcharset\s*=\s*["\']?\s*)([A-Za-z0-9_.:\-]+)~i',
            static fn(array $m): string => $m[1] . 'utf-8',
            $head,
            -1,
            $n
        );
        return $fixed !== null && $n > 0 ? $fixed . substr($html, 4096) : $html;
    }

    private static function detectAndConvert(string $html, string $mode): string
    {
        $mode = strtolower(trim($mode));
        if ($mode === '') {
            $mode = 'auto';
        }
        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            return self::scrub(substr($html, 3));
        }
        if (str_starts_with($html, "\xFF\xFE") || str_starts_with($html, "\xFE\xFF")) {
            $enc = $html[0] === "\xFF" ? 'UTF-16LE' : 'UTF-16BE';
            return self::scrub((string) @mb_convert_encoding(substr($html, 2), 'UTF-8', $enc));
        }

        if ($mode === 'auto') {
            if (self::isValidUtf8($html)) {
                return $html;
            }
            $charset = self::detectMetaCharset($html);
            if ($charset === null || self::isUtf8Name($charset)) {
                $charset = self::looksLikeCyrillic1251($html) ? 'Windows-1251' : 'Windows-1252';
            }
            return self::convert($html, $charset);
        }
        if (self::isUtf8Name($mode)) {
            return self::scrub($html);
        }
        return self::convert($html, $mode);
    }

    public static function detectMetaCharset(string $html): ?string
    {
        $head = substr($html, 0, 4096);
        if (preg_match('~<meta\b[^>]*?\bcharset\s*=\s*["\']?\s*([A-Za-z0-9_.:\-]+)~i', $head, $m) === 1) {
            return $m[1];
        }
        if (preg_match('~<meta\b[^>]*http-equiv\s*=\s*["\']?content-type[^>]*?charset\s*=\s*([A-Za-z0-9_.:\-]+)~i', $head, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * In Windows-1251 every Cyrillic letter is a byte 0xC0-0xFF, so Bulgarian/Russian text is mostly such bytes.
     * Western Windows-1252 text uses them only for occasional accented letters among ASCII ones.
     */
    private static function looksLikeCyrillic1251(string $html): bool
    {
        $text = (string) preg_replace('~<(script|style)\b.*?</\1\s*>|<[^>]*>|&[#A-Za-z0-9]+;~is', ' ', substr($html, 0, 65536));
        $high = preg_match_all('~[\xC0-\xFF]~', $text);
        $ascii = preg_match_all('~[A-Za-z]~', $text);
        return $high >= 4 && $high > 0.4 * ($high + $ascii);
    }

    private static function isUtf8Name(string $name): bool
    {
        $n = strtolower(str_replace(['_', ' '], '-', $name));
        return $n === 'utf-8' || $n === 'utf8';
    }

    private static function isValidUtf8(string $s): bool
    {
        return preg_match('//u', $s) === 1;
    }

    private static function scrub(string $s): string
    {
        if (self::isValidUtf8($s)) {
            return $s;
        }
        return function_exists('mb_scrub') ? mb_scrub($s, 'UTF-8') : (string) @iconv('UTF-8', 'UTF-8//IGNORE', $s);
    }

    private static function convert(string $html, string $charset): string
    {
        $cs = strtolower($charset);
        // WHATWG labels that mbstring does not know
        $cs = match ($cs) {
            'win-1251', 'cp1251', 'x-cp1251' => 'windows-1251',
            'cp1252', 'x-cp1252', 'iso-8859-1', 'latin1', 'us-ascii', 'ascii' => 'windows-1252',
            'cp1250' => 'windows-1250',
            'cp1253' => 'windows-1253',
            'cp1254', 'iso-8859-9' => 'windows-1254',
            'cp1257' => 'windows-1257',
            'koi8r' => 'koi8-r',
            default => $cs,
        };
        $out = null;
        if (function_exists('mb_convert_encoding') && in_array(strtolower($cs), array_map('strtolower', mb_list_encodings()), true)) {
            $out = @mb_convert_encoding($html, 'UTF-8', $cs);
        }
        if (!is_string($out) && function_exists('iconv')) {
            $r = @iconv($cs, 'UTF-8//IGNORE', $html);
            $out = is_string($r) ? $r : null;
        }
        if (!is_string($out)) {
            return self::scrub($html);
        }
        return self::scrub($out);
    }
}
