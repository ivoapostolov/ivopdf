<?php

declare(strict_types=1);

namespace IvoPdf\Pdf\Import;

/**
 * Parses page selections such as 'all', '2', '1-3,5', '4-' or [1, 3, '5-7'] into 1-based page numbers.
 */
final class PageSelector
{
    /**
     * @param array<int|string>|string $spec
     * @return list<int>
     */
    public static function parse(array|string $spec, int $pageCount): array
    {
        $tokens = [];
        foreach (is_array($spec) ? $spec : [$spec] as $item) {
            foreach (explode(',', (string) $item) as $tok) {
                $tok = strtolower(trim($tok));
                if ($tok !== '') {
                    $tokens[] = $tok;
                }
            }
        }
        if ($tokens === [] || $tokens === ['all'] || $tokens === ['*']) {
            return $pageCount > 0 ? range(1, $pageCount) : [];
        }

        $out = [];
        foreach ($tokens as $tok) {
            if (preg_match('/^(\d+)$/', $tok, $m)) {
                $from = $to = (int) $m[1];
            } elseif (preg_match('/^(\d*)\s*-\s*(\d*)$/', $tok, $m) && ($m[1] !== '' || $m[2] !== '')) {
                $from = $m[1] === '' ? 1 : (int) $m[1];
                $to = $m[2] === '' ? $pageCount : (int) $m[2];
            } elseif ($tok === 'last') {
                $from = $to = $pageCount;
            } else {
                throw new \InvalidArgumentException("Invalid page selection '{$tok}'");
            }
            if ($from < 1 || $to < 1 || $from > $pageCount || $to > $pageCount) {
                throw new PdfImportException("Page selection '{$tok}' is outside the document (1-{$pageCount})");
            }
            if ($from <= $to) {
                for ($p = $from; $p <= $to; $p++) {
                    $out[] = $p;
                }
            } else {
                for ($p = $from; $p >= $to; $p--) {
                    $out[] = $p;
                }
            }
        }
        return $out;
    }
}
