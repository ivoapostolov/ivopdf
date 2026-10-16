<?php

declare(strict_types=1);

namespace IvoPdf\Pdf\Import;

use IvoPdf\Pdf\PdfFinisher;

/**
 * Writes the annotations of imported pages (see AnnotationImporter) into the output document.
 *
 * Placement rules:
 *  - Appended pages (appendPdf / appendTemplate / UseTemplate): everything is carried over - links (URI links, and
 *    links to other pages of the same source file that were also appended; other internal links are dropped),
 *    markup annotations with their appearances and the form widgets (fields are merged into the document's single
 *    /AcroForm; their /DR fonts are merged, name clashes with HTML-generated fields get a numeric suffix).
 *    Annotation geometry is transformed to the output page: identity apart from crop box / rotation.
 *  - Background templates (setPageTemplate): only the static annotations - URI links and markup annotations with
 *    appearance - are repeated on every page (scaled and centered like the background). Form widgets and internal
 *    links are NOT copied: repeating a field on every page would create duplicate fields and a letterhead's
 *    navigation makes no sense in a different document.
 */
final class ImportedAnnotations
{
    /**
     * @param list<array{index: int, page: ImportedPage, mode: string}> $placements
     * @param array<int, array<int, int>> $poolMaps spl_object_id(pool) => (local id => object number)
     * @param list<int> $pageObjIds
     * @param list<array{0: float, 1: float}> $pageSizes output page sizes
     * @param array<int, string> $objects existing objects (to find the names of HTML-generated fields)
     * @param callable(): int $alloc
     * @param callable(int, string): void $put
     * @return array{annots: array<int, list<int>>, fields: list<int>, fonts: array<string, string>, da: ?string, needAppearances: bool}
     */
    public static function build(array $placements, array $poolMaps, array $pageObjIds, array $pageSizes, array $objects, string $existingDr, callable $alloc, callable $put): array
    {
        $res = ['annots' => [], 'fields' => [], 'fonts' => [], 'da' => null, 'needAppearances' => false];
        $usedNames = self::existingFieldNames($objects);
        $takenFonts = [];
        if (preg_match_all('~/([^\s/<>\[\]()]+)\s+(\d+\s+0\s+R)~', $existingDr, $m, PREG_SET_ORDER)) {
            foreach ($m as $fm) {
                $takenFonts[$fm[1]] = $fm[2];
            }
        }
        $fontSeq = 0;

        foreach ($placements as $pl) {
            $page = $pl['page'];
            if ($page->annots === [] || !isset($pageObjIds[$pl['index']])) {
                continue;
            }
            $append = $pl['mode'] === 'append';
            $map = $poolMaps[spl_object_id($page->pool)] ?? [];
            $resolve = static fn(string $s): string => (string) preg_replace_callback(
                '/\x00R(\d+)\x00/',
                static fn(array $mm): string => isset($map[(int) $mm[1]]) ? $map[(int) $mm[1]] . ' 0 R' : 'null',
                $s
            );
            [$s, $tx, $ty] = [1.0, 0.0, 0.0];
            if (!$append) {
                [$pw, $ph] = $pageSizes[$pl['index']] ?? [$page->width, $page->height];
                $s = min($pw / $page->width, $ph / $page->height);
                $tx = ($pw - $page->width * $s) / 2.0;
                $ty = ($ph - $page->height * $s) / 2.0;
            }
            $pageRef = $pageObjIds[$pl['index']] . ' 0 R';

            // Font resources of this source (renamed on clashes) for the widget /DA strings
            $rename = [];
            $widgets = array_filter($page->annots, static fn(array $a): bool => $a['kind'] === 'widget');
            if ($append && $widgets !== []) {
                foreach ($page->acroFonts as $name => $ser) {
                    $ref = $resolve($ser);
                    $final = $name;
                    if (isset($takenFonts[$name]) && $takenFonts[$name] !== $ref) {
                        do {
                            $final = $name . '_i' . (++$fontSeq);
                        } while (isset($takenFonts[$final]));
                    }
                    $takenFonts[$final] = $ref;
                    $res['fonts'][$final] = $ref;
                    $rename[$name] = $final;
                }
                $res['needAppearances'] = $res['needAppearances'] || $page->needAppearances;
            }

            /** @var array<string, array{id: int, kids: list<int>, fieldEntries: string, name: string, da: ?string}> $groups */
            $groups = [];
            foreach ($page->annots as $a) {
                $geom = self::geometry($a['geom'], $s, $tx, $ty);
                if ($a['kind'] === 'link') {
                    $extra = '';
                    if ($a['dest'] !== null) {
                        if (!$append) {
                            continue;
                        }
                        $target = null;
                        foreach ($placements as $other) {
                            if ($other['mode'] === 'append' && $other['page']->pool === $page->pool && $other['page']->sourcePage === $a['dest']['page'] && isset($pageObjIds[$other['index']])) {
                                $target = $pageObjIds[$other['index']];
                                break;
                            }
                        }
                        if ($target === null) {
                            continue; // link to a page that is not part of the output
                        }
                        $extra = " /Dest [{$target} 0 R " . $a['dest']['tail'] . ']';
                    }
                    $id = $alloc();
                    $put($id, '<< /Type /Annot /Subtype /Link' . $resolve($a['entries']) . $extra . $geom . " /P {$pageRef} >>");
                    $res['annots'][$pl['index']][] = $id;
                    continue;
                }
                if ($a['kind'] === 'markup') {
                    $id = $alloc();
                    $put($id, '<< /Type /Annot' . $resolve($a['entries']) . $geom . " /P {$pageRef} >>");
                    $res['annots'][$pl['index']][] = $id;
                    continue;
                }
                if (!$append) {
                    continue; // widgets only on appended pages
                }
                $da = $a['da'] !== null ? self::renameDa($a['da'], $rename) : null;
                $daEntry = $da !== null ? ' /DA <' . strtoupper(bin2hex($da)) . '>' : '';
                if ($res['da'] === null && $da !== null) {
                    $res['da'] = $da;
                }
                if ($a['group'] === null) {
                    $id = $alloc();
                    $name = self::unique($a['name'] !== '' ? $a['name'] : 'field', $usedNames);
                    $put($id, '<< /Type /Annot' . $resolve($a['entries'] . $a['fieldEntries']) . ' /T ' . PdfFinisher::pdfText($name) . $daEntry . $geom . " /P {$pageRef} >>");
                    $res['annots'][$pl['index']][] = $id;
                    $res['fields'][] = $id;
                    continue;
                }
                if (!isset($groups[$a['group']])) {
                    $groups[$a['group']] = [
                        'id' => $alloc(),
                        'kids' => [],
                        'fieldEntries' => $resolve($a['fieldEntries']),
                        'name' => self::unique($a['name'] !== '' ? $a['name'] : 'field', $usedNames),
                        'da' => $daEntry,
                    ];
                }
                $kid = $alloc();
                $put($kid, '<< /Type /Annot' . $resolve($a['entries']) . $geom . " /P {$pageRef} /Parent " . $groups[$a['group']]['id'] . ' 0 R >>');
                $groups[$a['group']]['kids'][] = $kid;
                $res['annots'][$pl['index']][] = $kid;
            }
            foreach ($groups as $g) {
                $put($g['id'], '<< ' . trim($g['fieldEntries']) . ' /T ' . PdfFinisher::pdfText($g['name']) . $g['da']
                    . ' /Kids [' . implode(' ', array_map(static fn(int $k): string => "{$k} 0 R", $g['kids'])) . '] >>');
                $res['fields'][] = $g['id'];
            }
        }
        return $res;
    }

    /** @param array<string, mixed> $geom */
    private static function geometry(array $geom, float $s, float $tx, float $ty): string
    {
        $out = '';
        foreach ($geom as $key => $val) {
            $out .= " /{$key} " . self::fmtGeom($val, $s, $tx, $ty);
        }
        return $out;
    }

    private static function fmtGeom(mixed $v, float $s, float $tx, float $ty): string
    {
        $parts = [];
        $isNested = $v !== [] && is_array($v[0] ?? null);
        if ($isNested) {
            foreach ($v as $sub) {
                $parts[] = self::fmtGeom($sub, $s, $tx, $ty);
            }
            return '[' . implode(' ', $parts) . ']';
        }
        foreach (array_values($v) as $i => $n) {
            $parts[] = self::num((float) $n * $s + ($i % 2 === 0 ? $tx : $ty));
        }
        return '[' . implode(' ', $parts) . ']';
    }

    private static function num(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.4F', $v), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }

    /** @param array<string, string> $rename */
    private static function renameDa(string $da, array $rename): string
    {
        if ($rename === []) {
            return $da;
        }
        return (string) preg_replace_callback('~/([^\s/<>\[\]()]+)(\s+[\d.]+\s+Tf)~', static fn(array $m): string => '/' . ($rename[$m[1]] ?? $m[1]) . $m[2], $da);
    }

    /** @param array<string, true> $used */
    private static function unique(string $name, array &$used): string
    {
        $candidate = $name;
        $i = 1;
        while (isset($used[$candidate])) {
            $candidate = $name . '_' . (++$i);
        }
        $used[$candidate] = true;
        return $candidate;
    }

    /**
     * Names (/T) of the field dictionaries already present (HTML-generated forms, signature field).
     *
     * @param array<int, string> $objects
     * @return array<string, true>
     */
    private static function existingFieldNames(array $objects): array
    {
        $names = [];
        foreach ($objects as $body) {
            $head = substr($body, 0, 1500);
            if (!str_contains($head, '/FT') && !str_contains($head, '/Widget')) {
                continue;
            }
            if (preg_match('~/T\s*(\((?:[^()\\\\]|\\\\.)*\)|<[0-9A-Fa-f\s]*>)~', $head, $m) !== 1) {
                continue;
            }
            $raw = $m[1];
            if ($raw[0] === '<') {
                $hex = (string) preg_replace('~[^0-9A-Fa-f]~', '', $raw);
                $bin = (string) hex2bin(strlen($hex) % 2 === 1 ? $hex . '0' : $hex);
                $text = str_starts_with($bin, "\xFE\xFF") ? (string) mb_convert_encoding(substr($bin, 2), 'UTF-8', 'UTF-16BE') : $bin;
            } else {
                $text = stripslashes(substr($raw, 1, -1));
            }
            $names[$text] = true;
        }
        return $names;
    }
}
