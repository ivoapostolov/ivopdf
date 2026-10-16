<?php

declare(strict_types=1);

namespace IvoPdf\Text;

/**
 * Liang/TeX pattern hyphenator (hyph-utf8 pattern sets shipped under resources/hyphenation/).
 */
final class Hyphenator
{
    /** @var array<string, self|null> Loaded instances by pattern set */
    private static array $instances = [];

    /** @var array<string, array<int, int>> pattern letters => digit per inter-letter position (index 0 = before first letter) */
    private array $patterns = [];

    /** @var array<string, list<int>> exception word => break offsets */
    private array $exceptions = [];

    private int $maxPatternLen = 0;

    /** @var array<string, list<int>> word + limits => break offsets */
    private array $cache = [];

    /**
     * @param array{patterns?: string, exceptions?: string} $data
     */
    public function __construct(array $data)
    {
        foreach (preg_split('/\s+/', trim($data['patterns'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $pat) {
            $letters = '';
            $digits = [0];
            foreach (preg_split('//u', $pat, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                if ($ch >= '0' && $ch <= '9') {
                    $digits[count($digits) - 1] = (int) $ch;
                } else {
                    $letters .= $ch;
                    $digits[] = 0;
                }
            }
            $this->patterns[$letters] = $digits;
            $this->maxPatternLen = max($this->maxPatternLen, mb_strlen($letters, 'UTF-8'));
        }
        foreach (preg_split('/\s+/', trim($data['exceptions'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ex) {
            $word = '';
            $breaks = [];
            foreach (preg_split('//u', $ex, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                if ($ch === '-') {
                    $breaks[] = mb_strlen($word, 'UTF-8');
                } else {
                    $word .= $ch;
                }
            }
            $this->exceptions[$word] = $breaks;
        }
    }

    /**
     * Returns the hyphenator for a language tag ("bg", "en-US", "en_GB"...), or null when no patterns exist.
     */
    public static function forLang(string $lang): ?self
    {
        $set = self::patternSet($lang);
        if ($set === null) {
            return null;
        }
        if (!array_key_exists($set, self::$instances)) {
            $file = dirname(__DIR__, 2) . '/resources/hyphenation/' . $set . '.php';
            $data = is_file($file) ? include $file : null;
            self::$instances[$set] = is_array($data) ? new self($data) : null;
        }
        return self::$instances[$set];
    }

    /**
     * Maps a BCP-47 language tag to a shipped pattern set name.
     */
    public static function patternSet(string $lang): ?string
    {
        $primary = strtolower(explode('-', str_replace('_', '-', trim($lang)))[0]);
        return match ($primary) {
            'bg' => 'bg',
            'en' => 'en-us',
            default => null,
        };
    }

    /**
     * Allowed break positions of a word as character offsets (a break after N characters).
     *
     * @return list<int>
     */
    public function hyphenate(string $word, int $minWord = 5, int $leftMin = 2, int $rightMin = 2): array
    {
        $key = $minWord . ':' . $leftMin . ':' . $rightMin . ':' . $word;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $lower = mb_strtolower($word, 'UTF-8');
        $chars = preg_split('//u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($chars);
        $result = [];

        if ($n >= max($minWord, $leftMin + $rightMin)) {
            if (isset($this->exceptions[$lower])) {
                $points = $this->exceptions[$lower];
            } else {
                $points = $this->applyPatterns($chars, $n);
            }
            foreach ($points as $p) {
                if ($p >= $leftMin && $n - $p >= $rightMin) {
                    $result[] = $p;
                }
            }
        }

        if (count($this->cache) > 20000) {
            $this->cache = [];
        }
        return $this->cache[$key] = $result;
    }

    /**
     * @param list<string> $chars
     * @return list<int>
     */
    private function applyPatterns(array $chars, int $n): array
    {
        $ext = array_merge(['.'], $chars, ['.']);
        $m = $n + 2;
        $values = array_fill(0, $m + 1, 0);
        for ($i = 0; $i < $m; $i++) {
            $sub = '';
            $maxLen = min($this->maxPatternLen, $m - $i);
            for ($len = 1; $len <= $maxLen; $len++) {
                $sub .= $ext[$i + $len - 1];
                if (isset($this->patterns[$sub])) {
                    foreach ($this->patterns[$sub] as $k => $d) {
                        if ($d > $values[$i + $k]) {
                            $values[$i + $k] = $d;
                        }
                    }
                }
            }
        }
        // values[j] is the value before ext[j]; ext[1] is the first letter, so a break after p letters is values[p + 1]
        $points = [];
        for ($p = 1; $p < $n; $p++) {
            if (($values[$p + 1] & 1) === 1) {
                $points[] = $p;
            }
        }
        return $points;
    }
}
