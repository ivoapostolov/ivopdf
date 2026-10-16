<?php

declare(strict_types=1);

namespace IvoPdf\Compat;

/**
 * mPDF `<indexinsert>`: builds the alphabetical index (sorted by locale-aware collation, with page numbers).
 */
final class MpdfIndex
{
    private const BG_ALPHABET = 'абвгдежзийклмнопрстуфхцчшщъьюя';

    /**
     * @param array<string, string>        $entries marker id => term
     * @param array<string, string>        $labels  marker id => page label (from the previous pass)
     * @param array<string, string>        $attrs   lower-case attributes of the `<indexinsert>` tag
     */
    public static function build(array $entries, array $labels, array $attrs): string
    {
        $collation = $attrs['collation'] ?? '';
        $letters = !isset($attrs['usedivletters']) || !in_array(strtolower($attrs['usedivletters']), ['off', '0', 'false'], true);
        $links = !isset($attrs['links']) || !in_array(strtolower($attrs['links']), ['off', '0', 'false'], true);
        $cols = max(1, (int) ($attrs['cols'] ?? $attrs['columns'] ?? 1));

        // term => [marker ids]
        $byTerm = [];
        foreach ($entries as $id => $term) {
            $term = trim($term);
            if ($term !== '') {
                $byTerm[$term][] = $id;
            }
        }
        $terms = array_keys($byTerm);
        $cmp = self::comparator($collation);
        usort($terms, $cmp);

        $style = 'margin:0 0 10pt 0;' . ($cols > 1 ? "column-count:{$cols};column-gap:7mm;" : '');
        $out = '<div class="ivo-index" style="' . $style . '">';
        $lastLetter = null;
        foreach ($terms as $term) {
            $letter = self::firstLetter($term);
            if ($letters && $letter !== $lastLetter) {
                $out .= '<div class="ivo-index-letter" style="font-weight:bold;font-size:1.2em;margin:6pt 0 2pt 0;break-after:avoid">'
                    . htmlspecialchars($letter, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            $lastLetter = $letter;

            $pages = [];
            foreach ($byTerm[$term] as $id) {
                $label = $labels[$id] ?? '0';
                if ($label === '') {
                    continue;
                }
                $pages[$label] ??= $id; // one reference per distinct page label
            }
            $parts = [];
            foreach ($pages as $label => $id) {
                $parts[] = $links
                    ? '<a href="#' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" style="color:inherit;text-decoration:none">' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</a>'
                    : htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');
            }
            $out .= '<div class="ivo-index-entry" style="margin:1pt 0">' . htmlspecialchars($term, ENT_QUOTES, 'UTF-8')
                . ($parts !== [] ? ', ' . implode(', ', $parts) : '') . '</div>';
        }
        return $out . '</div>';
    }

    /** Uppercase first letter of the sort key (digits and punctuation are skipped). */
    public static function firstLetter(string $term): string
    {
        if (preg_match('/\p{L}/u', $term, $m) === 1) {
            return mb_strtoupper($m[0], 'UTF-8');
        }
        return mb_strtoupper(mb_substr($term, 0, 1, 'UTF-8'), 'UTF-8');
    }

    /**
     * @return \Closure(string, string): int
     */
    public static function comparator(string $locale): \Closure
    {
        $locale = trim($locale) !== '' ? str_replace('-', '_', trim($locale)) : 'en_US';
        if (class_exists(\Collator::class)) {
            $coll = @\Collator::create($locale);
            if ($coll instanceof \Collator) {
                return static fn(string $a, string $b): int => (int) $coll->compare($a, $b);
            }
        }
        // Built-in order: punctuation/digits, Latin A-Z, Bulgarian Cyrillic (Cyrillic first for bg locales, like ICU);
        // case-insensitive, lower case first on ties
        $cyrFirst = str_starts_with(strtolower($locale), 'bg') || str_starts_with(strtolower($locale), 'ru');
        return static function (string $a, string $b) use ($cyrFirst): int {
            $ka = self::fallbackKey($a, $cyrFirst);
            $kb = self::fallbackKey($b, $cyrFirst);
            if ($ka !== $kb) {
                return strcmp($ka, $kb);
            }
            return strcmp($b, $a); // lower case (higher code point in Latin/Cyrillic) first
        };
    }

    /** Sort key: fixed-width numbers per character, so plain string comparison is lexicographic. */
    private static function fallbackKey(string $s, bool $cyrFirst): string
    {
        $key = [];
        $s = mb_strtolower($s, 'UTF-8');
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (strlen($ch) === 1 && $ch >= 'a' && $ch <= 'z') {
                $key[] = ($cyrFirst ? 2000 : 1000) + ord($ch);
                continue;
            }
            $pos = mb_strpos(self::BG_ALPHABET, $ch, 0, 'UTF-8');
            if ($pos !== false) {
                $key[] = ($cyrFirst ? 1000 : 2000) + $pos;
                continue;
            }
            $key[] = ctype_digit($ch) ? 500 + (int) $ch : ($ch === ' ' ? 100 : 300 + (int) (mb_ord($ch, 'UTF-8') % 150));
        }
        return implode('', array_map(static fn(int $k): string => sprintf('%04d', $k), $key));
    }
}
