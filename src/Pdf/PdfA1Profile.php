<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Options;
use IvoPdf\RenderLimits;

/**
 * Post-processing that turns the finished object table into PDF/A-1b (ISO 19005-1) content:
 * no transparency (soft-masked images are composited onto white, opacity / blend states are reset, transparency
 * groups removed), /Interpolate false, /CIDSet streams for CID font subsets, annotation print flags, no
 * /NeedAppearances, and removal of the now unreachable objects (old soft masks etc.).
 *
 * The header version (1.4), the XMP part number and the OutputIntent are handled by PdfFinisher / PdfWriter.
 * Everything that had to be flattened is reported through Options::addWarning().
 */
final class PdfA1Profile
{
    /**
     * @param array<int, string> $objects
     */
    public static function apply(array &$objects, int &$counter, int $catalogId, Options $opt): void
    {
        $flat = ['alphaStates' => 0, 'blendStates' => 0, 'groups' => 0, 'softMaskStates' => 0, 'imagesComposited' => 0, 'imagesDropped' => 0, 'interpolate' => 0, 'needAppearances' => 0];

        foreach ($objects as $id => $body) {
            $new = $body;
            $head = substr($body, 0, 400);

            if (str_contains($head, '/Subtype /Image') || str_contains($head, '/Subtype/Image')) {
                $new = self::flattenImage($objects, $id, $new, $flat);
            } else {
                // ExtGState dictionaries (indirect or inline in /Resources), annotation /CA, groups: dictionary part only
                [$d, $data] = self::split($new);
                if (preg_match('~/(?:CA|ca)\s+-?[\d.]|/BM\s*/|/SMask\b~', $d) === 1) {
                    $d = self::flattenExtGState($d, $flat);
                }
                if (str_contains($d, '/Group')) {
                    $d = self::stripTransparencyGroup($d, $flat);
                }
                if (str_contains($head, '/Type /Annot')) {
                    $d = self::fixAnnotation($d);
                }
                $new = $data === null ? $d : $d . "\nstream\n" . $data . "\nendstream";
            }
            if (str_contains($new, '/NeedAppearances')) {
                $n2 = (string) preg_replace('~/NeedAppearances\s+true~', '/NeedAppearances false', $new, -1, $c);
                $flat['needAppearances'] += $c;
                $new = $n2;
            }
            if ($new !== $body) {
                $objects[$id] = $new;
            }
        }

        self::addCidSets($objects, $counter, $opt);
        self::collectGarbage($objects, $catalogId);
        self::warn($opt, $flat, $objects);
    }

    // ------------------------------------------------------------------
    // Warnings
    // ------------------------------------------------------------------

    /**
     * @param array<string, int> $flat
     * @param array<int, string> $objects
     */
    private static function warn(Options $opt, array $flat, array $objects): void
    {
        $parts = [];
        if ($flat['alphaStates'] > 0) {
            $parts[] = $flat['alphaStates'] . ' opacity state(s) reset to 1';
        }
        if ($flat['blendStates'] > 0) {
            $parts[] = $flat['blendStates'] . ' blend mode(s) reset to Normal';
        }
        if ($flat['softMaskStates'] > 0) {
            $parts[] = $flat['softMaskStates'] . ' soft mask(s) removed';
        }
        if ($flat['groups'] > 0) {
            $parts[] = $flat['groups'] . ' transparency group(s) removed';
        }
        if ($flat['imagesComposited'] > 0) {
            $parts[] = $flat['imagesComposited'] . ' soft-masked image(s) composited onto white';
        }
        if ($flat['imagesDropped'] > 0) {
            $parts[] = $flat['imagesDropped'] . ' image alpha channel(s) dropped (could not be composited)';
        }
        if ($flat['needAppearances'] > 0) {
            $parts[] = '/NeedAppearances disabled';
        }
        if ($parts !== []) {
            $opt->addWarning('PDF/A-1b: transparency is not allowed and was flattened: ' . implode('; ', $parts) . '. Use "2b" to keep transparency.');
        }
        foreach ($objects as $body) {
            if (str_contains(substr($body, 0, 300), '/Type /Font') && preg_match('~/Subtype\s*/(Type1|TrueType)\b~', substr($body, 0, 300)) === 1
                && !str_contains(substr($body, 0, 300), '/FontDescriptor')) {
                $opt->addWarning('PDF/A-1b: a non-embedded standard font is used (form fields); PDF/A requires all fonts to be embedded.');
                break;
            }
        }
    }

    // ------------------------------------------------------------------
    // Object rewriting
    // ------------------------------------------------------------------

    /** @return array{0: string, 1: ?string} dictionary text and raw stream data (null when no stream) */
    private static function split(string $body): array
    {
        $pos = strpos($body, ">>\nstream\n");
        if ($pos === false) {
            return [$body, null];
        }
        $data = substr($body, $pos + strlen(">>\nstream\n"), -strlen("\nendstream"));
        return [substr($body, 0, $pos + 2), $data];
    }

    /**
     * @param array<int, string> $objects
     * @param array<string, int> $flat
     */
    private static function flattenImage(array &$objects, int $id, string $body, array &$flat): string
    {
        [$dict, $data] = self::split($body);
        if ($data === null) {
            return $body;
        }
        $changed = false;
        if (preg_match('~/Interpolate\s+true~', $dict) === 1) {
            $dict = (string) preg_replace('~/Interpolate\s+true~', '/Interpolate false', $dict);
            $flat['interpolate']++;
            $changed = true;
        }
        if (preg_match('~/SMask\s+(\d+)\s+0\s+R~', $dict, $m) === 1) {
            $maskId = (int) $m[1];
            $dict = (string) preg_replace('~\s*/SMask\s+\d+\s+0\s+R~', '', $dict);
            $dict = (string) preg_replace('~\s*/SMaskInData\s+\d+~', '', $dict);
            $composited = isset($objects[$maskId]) ? self::composite($dict, $data, $objects[$maskId]) : null;
            if ($composited !== null) {
                [$dict, $data] = $composited;
                $flat['imagesComposited']++;
            } else {
                $flat['imagesDropped']++;
            }
            $changed = true;
        }
        if (!$changed) {
            return $body;
        }
        return $dict . "\nstream\n" . $data . "\nendstream";
    }

    /**
     * Composites an image onto white using its soft mask. Supports 8-bit DeviceRGB / DeviceGray images that are
     * Flate-compressed (with or without PNG predictors) or JPEG (via GD when available).
     *
     * Image and mask are read row by row (ImageRowStream) and every composited row is deflated at once: a decompression
     * bomb never exists decoded in memory. A JPEG is decoded by GD as a whole, so its bitmap is checked against the
     * memory budget first (RenderLimits). When the result would not fit, null is returned and the caller drops the
     * alpha channel instead (a warning tells).
     *
     * @return ?array{0: string, 1: string} new dictionary text and stream data
     */
    private static function composite(string $dict, string $data, string $maskBody): ?array
    {
        if (preg_match('~/Width\s+(\d+)~', $dict, $mw) !== 1 || preg_match('~/Height\s+(\d+)~', $dict, $mh) !== 1) {
            return null;
        }
        $w = (int) $mw[1];
        $h = (int) $mh[1];
        if ($w < 1 || $h < 1 || $w * $h > 40_000_000) {
            return null;
        }
        $channels = str_contains($dict, '/DeviceRGB') ? 3 : (str_contains($dict, '/DeviceGray') ? 1 : 0);
        if ($channels === 0 || preg_match('~/BitsPerComponent\s+8~', $dict) !== 1 || str_contains($dict, '/Decode ')) {
            return null;
        }

        [$maskDict, $maskData] = self::split($maskBody);
        if ($maskData === null || !str_contains($maskDict, '/FlateDecode')
            || preg_match('~/Width\s+' . $w . '\b~', $maskDict) !== 1 || preg_match('~/Height\s+' . $h . '\b~', $maskDict) !== 1
            || ImageRowStream::hasUnsupportedPredictor($maskDict)) {
            return null;
        }
        $alpha = ImageRowStream::open($maskData, $w, 1, ImageRowStream::hasPngPredictor($maskDict));
        if ($alpha === null) {
            return null;
        }

        $stride = $w * $channels;
        $gd = null;
        $rows = null;
        if (str_contains($dict, '/DCTDecode')) {
            if (!function_exists('imagecreatefromstring')
                || !RenderLimits::fits($w * $h * 5 + 4 * $stride + 2 * strlen($data) + 4194304)) {
                return null;
            }
            $gd = ImageRowStream::quietly(static fn() => imagecreatefromstring($data));
            if ($gd === false || imagesx($gd) !== $w || imagesy($gd) !== $h) {
                return null;
            }
        } else {
            if (!str_contains($dict, '/FlateDecode') || ImageRowStream::hasUnsupportedPredictor($dict)) {
                return null;
            }
            $rows = ImageRowStream::open($data, $stride, $channels, ImageRowStream::hasPngPredictor($dict));
            if ($rows === null) {
                return null;
            }
        }

        $z = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 6]);
        if ($z === false) {
            return null;
        }
        $out = ''; // appended to: deflate_add() results keep the capacity of their input
        $lastIn = null;
        $lastOut = '';
        for ($y = 0; $y < $h; $y++) {
            RenderLimits::tick();
            if ($gd !== null) {
                $raw = '';
                for ($x = 0; $x < $w; $x++) {
                    $c = imagecolorat($gd, $x, $y);
                    $r = ($c >> 16) & 255;
                    $g = ($c >> 8) & 255;
                    $b = $c & 255;
                    $raw .= $channels === 3 ? chr($r) . chr($g) . chr($b) : chr(intdiv($r + $g + $b, 3));
                }
            } else {
                $raw = $rows->next();
                if ($raw === null) {
                    return null; // truncated / corrupt pixel data
                }
            }
            $a = $alpha->next();
            if ($a === null) {
                return null; // truncated / corrupt mask
            }
            // Identical rows (flat areas, and every decompression bomb) are composited once
            $sig = $raw . "\0" . $a;
            if ($sig !== $lastIn) {
                $lastIn = $sig;
                if (trim($a, "\xFF") === '') {
                    $lastOut = $raw;
                } else {
                    $lastOut = '';
                    for ($i = 0; $i < $w; $i++) {
                        $av = ord($a[$i]);
                        for ($c = 0; $c < $channels; $c++) {
                            $v = ord($raw[$i * $channels + $c]);
                            $lastOut .= $av === 255 ? chr($v) : chr((int) round(($v * $av + 255 * (255 - $av)) / 255));
                        }
                    }
                }
            }
            $out .= deflate_add($z, $lastOut, ZLIB_NO_FLUSH);
            if (($y & 31) === 31 && !RenderLimits::fits(strlen($out) + 4 * $stride + 4194304)) {
                return null;
            }
        }
        $gd = null;
        $out .= deflate_add($z, '', ZLIB_FINISH);

        $dict = (string) preg_replace('~/Filter\s*(?:\[[^\]]*\]|/\w+)~', '', $dict);
        $dict = (string) preg_replace('~/DecodeParms\s*(?:<<.*?>>|\[[^\]]*\])~s', '', $dict);
        $dict = (string) preg_replace('~/Length\s+\d+~', '', $dict);
        $dict = substr(rtrim($dict), 0, -2) . ' /Filter /FlateDecode /Length ' . strlen($out) . ' >>';
        return [$dict, $out];
    }

    /** @param array<string, int> $flat */
    private static function flattenExtGState(string $body, array &$flat): string
    {
        $new = (string) preg_replace_callback('~/(CA|ca)\s+(-?[\d.]+)~', static function (array $m) use (&$flat): string {
            if (abs((float) $m[2] - 1.0) > 1e-6) {
                $flat['alphaStates']++;
            }
            return '/' . $m[1] . ' 1';
        }, $body);
        $new = (string) preg_replace_callback('~/BM\s*/(\w+)~', static function (array $m) use (&$flat): string {
            if ($m[1] !== 'Normal' && $m[1] !== 'Compatible') {
                $flat['blendStates']++;
            }
            return '/BM /Normal';
        }, $new);
        $new = (string) preg_replace_callback('~/SMask\s*(?:/None|\d+\s+0\s+R|<<.*?>>)~s', static function (array $m) use (&$flat): string {
            if (!str_contains($m[0], '/None')) {
                $flat['softMaskStates']++;
            }
            return '/SMask /None';
        }, $new);
        return $new;
    }

    private static function fixAnnotation(string $body): string
    {
        if (preg_match('~/F\s+(\d+)~', $body, $m) === 1) {
            $f = ((int) $m[1] | 4) & ~(1 | 2 | 32);
            return (string) preg_replace('~/F\s+\d+~', '/F ' . $f, $body, 1);
        }
        return PdfFinisher::appendEntries($body, '/F 4');
    }

    /** @param array<string, int> $flat */
    private static function stripTransparencyGroup(string $body, array &$flat): string
    {
        // /Group << /S /Transparency ... >>  (possibly with a nested dictionary-free body)
        return (string) preg_replace_callback('~\s*/Group\s*<<[^<>]*>>~', static function (array $m) use (&$flat): string {
            if (str_contains($m[0], '/Transparency')) {
                $flat['groups']++;
                return '';
            }
            return $m[0];
        }, $body);
    }

    // ------------------------------------------------------------------
    // CIDSet
    // ------------------------------------------------------------------

    /**
     * @param array<int, string> $objects
     */
    private static function addCidSets(array &$objects, int &$counter, Options $opt): void
    {
        foreach ($objects as $id => $body) {
            if (!preg_match('~/Subtype\s*/CIDFontType[02]\b~', substr($body, 0, 300)) || !str_contains($body, '/FontDescriptor')) {
                continue;
            }
            if (preg_match('~/FontDescriptor\s+(\d+)\s+0\s+R~', $body, $fm) !== 1) {
                continue;
            }
            $descId = (int) $fm[1];
            if (!isset($objects[$descId]) || str_contains($objects[$descId], '/CIDSet')) {
                continue;
            }
            $cids = self::cidsFromWidths($body);
            $cids[0] = true; // .notdef
            $max = max(array_keys($cids));
            $bits = str_repeat("\0", intdiv($max, 8) + 1);
            foreach (array_keys($cids) as $cid) {
                $bits[$cid >> 3] = chr(ord($bits[$cid >> 3]) | (0x80 >> ($cid & 7)));
            }
            $setId = ++$counter;
            if ($opt->compress) {
                $z = (string) gzcompress($bits, 6);
                $objects[$setId] = sprintf("<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream", strlen($z), $z);
            } else {
                $objects[$setId] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($bits), $bits);
            }
            $objects[$descId] = PdfFinisher::appendEntries($objects[$descId], "/CIDSet {$setId} 0 R");
        }
    }

    /** @return array<int, true> */
    private static function cidsFromWidths(string $cidFontBody): array
    {
        $out = [];
        if (preg_match('~/W\s*\[(.*)\]~s', $cidFontBody, $m) !== 1) {
            return $out;
        }
        $w = $m[1];
        // forms: "c [w1 w2 ...]"  and  "c1 c2 w"
        $pos = 0;
        $len = strlen($w);
        while ($pos < $len) {
            if (preg_match('~\G\s*(\d+)\s*\[([^\]]*)\]~', $w, $a, 0, $pos) === 1) {
                $first = (int) $a[1];
                $cnt = preg_match_all('~-?[\d.]+~', $a[2]);
                for ($i = 0; $i < $cnt; $i++) {
                    $out[$first + $i] = true;
                }
                $pos += strlen($a[0]);
            } elseif (preg_match('~\G\s*(\d+)\s+(\d+)\s+-?[\d.]+~', $w, $a, 0, $pos) === 1) {
                for ($c = (int) $a[1]; $c <= min((int) $a[2], (int) $a[1] + 70000); $c++) {
                    $out[$c] = true;
                }
                $pos += strlen($a[0]);
            } else {
                break;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Garbage collection
    // ------------------------------------------------------------------

    /**
     * Replaces objects that are no longer reachable from the catalog / Info dictionary / signature with "null".
     *
     * @param array<int, string> $objects
     */
    private static function collectGarbage(array &$objects, int $catalogId): void
    {
        $roots = [$catalogId];
        foreach ($objects as $id => $body) {
            $h = substr($body, 0, 600);
            if (str_contains($h, '/Producer') && str_contains($h, '/CreationDate')) {
                $roots[] = $id; // document information dictionary
            }
        }
        $reach = [];
        $queue = $roots;
        while ($queue !== []) {
            $id = array_pop($queue);
            if (isset($reach[$id]) || !isset($objects[$id])) {
                continue;
            }
            $reach[$id] = true;
            $body = $objects[$id];
            $pos = strpos($body, ">>\nstream\n");
            $dict = $pos === false ? $body : substr($body, 0, $pos + 2);
            if (preg_match_all('~(\d+)\s+0\s+R\b~', $dict, $m)) {
                foreach ($m[1] as $ref) {
                    $queue[] = (int) $ref;
                }
            }
        }
        foreach ($objects as $id => $_body) {
            if (!isset($reach[$id])) {
                $objects[$id] = 'null';
            }
        }
    }
}
