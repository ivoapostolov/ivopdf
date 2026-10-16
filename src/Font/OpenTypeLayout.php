<?php

declare(strict_types=1);

namespace IvoPdf\Font;

/**
 * Reader/applier for OpenType layout tables: GSUB (lookup types 1-6 + extension), GPOS (single, pair,
 * mark-to-base/ligature/mark, contextual + extension) and the legacy 'kern' table.
 *
 * Glyph buffers are lists of ['g' => gid, 's' => first source index, 'e' => last source index, 'm' => feature mask].
 * After {@see applyGpos()} each entry additionally carries 'x','y' (placement), 'xa','ya' (advance deltas) in
 * 1/1000 em, and optionally 'att' => [baseIndex, dx, dy] for attached marks.
 * Subtables are parsed lazily and cached, so unused tables cost nothing.
 */
final class OpenTypeLayout
{
    /** @var array<string, string> */
    private array $t = [];

    private ?string $kernTable;
    private float $scale;

    /** @var array<string, array{scripts: array<string, array{def: ?int, langs: array<string, int>}>, features: list<array{0: string, 1: list<int>}>}> */
    private array $headers = [];

    /** @var array<string, array<int, array{type: int, flag: int, mfs: int, subs: list<array{0: int, 1: int}>}|null>> */
    private array $lookups = [];

    /** @var array<string, array<string, list<int>>> */
    private array $featureCache = [];

    /** @var array<string, array{f: int, m?: array<int, int>, r?: list<array{0: int, 1: int, 2: int}>, c?: array<int, int>, s?: int, a?: list<int>}> */
    private array $coverageCache = [];

    /** @var array<string, array{f: int, s?: int, a?: list<int>, r?: list<array{0: int, 1: int, 2: int}>}> */
    private array $classCache = [];

    /** @var array<int, int>|null legacy kern pairs (left << 16 | right) => value */
    private ?array $kernPairs = null;

    private int $depth = 0;

    public function __construct(?string $gsub, ?string $gpos, ?string $gdef, ?string $kern, float $scale)
    {
        if ($gsub !== null) {
            $this->t['GSUB'] = $gsub;
        }
        if ($gpos !== null) {
            $this->t['GPOS'] = $gpos;
        }
        if ($gdef !== null) {
            $this->t['GDEF'] = $gdef;
        }
        $this->kernTable = $kern;
        $this->scale = $scale;
    }

    public function hasTable(string $table): bool
    {
        return isset($this->t[$table]);
    }

    public function hasLegacyKern(): bool
    {
        return $this->kernTable !== null && $this->kernPairs() !== [];
    }

    /**
     * Lookup indices enabled by a feature for the best matching script (first of $scripts present, else DFLT, else latn).
     *
     * @param list<string> $scripts
     * @return list<int>
     */
    public function featureLookups(string $table, array $scripts, string $tag): array
    {
        $key = implode(',', $scripts) . '|' . $tag;
        if (isset($this->featureCache[$table][$key])) {
            return $this->featureCache[$table][$key];
        }
        $result = [];
        $h = $this->header($table);
        if ($h !== null) {
            $script = null;
            foreach (array_merge($scripts, ['DFLT', 'dflt', 'latn']) as $s) {
                if (isset($h['scripts'][$s])) {
                    $script = $h['scripts'][$s];
                    break;
                }
            }
            if ($script !== null && $script['def'] !== null) {
                $d = $this->t[$table];
                $ls = $script['def'];
                $req = $this->u16($d, $ls + 2);
                $indices = [];
                if ($req !== 0xFFFF) {
                    $indices[] = $req;
                }
                $n = $this->u16($d, $ls + 4);
                for ($i = 0; $i < $n; $i++) {
                    $indices[] = $this->u16($d, $ls + 6 + 2 * $i);
                }
                foreach ($indices as $fi) {
                    if (isset($h['features'][$fi]) && $h['features'][$fi][0] === $tag) {
                        foreach ($h['features'][$fi][1] as $li) {
                            $result[$li] = $li;
                        }
                    }
                }
            }
        }
        sort($result);
        return $this->featureCache[$table][$key] = array_values($result);
    }

    /**
     * Applies GSUB lookups stage by stage.
     *
     * @param list<array{g: int, s: int, e: int, m: int}> $buf
     * @param list<array<int, int>> $stages each stage: lookupIndex => feature mask bits (sorted by lookup index)
     * @return list<array{g: int, s: int, e: int, m: int}>
     */
    public function applyGsub(array $buf, array $stages): array
    {
        if (!isset($this->t['GSUB'])) {
            return $buf;
        }
        foreach ($stages as $stage) {
            foreach ($stage as $li => $mask) {
                $lk = $this->lookup('GSUB', $li);
                if ($lk === null) {
                    continue;
                }
                for ($i = 0; $i < count($buf);) {
                    if (($buf[$i]['m'] & $mask) === 0 || $this->skip($buf[$i]['g'], $lk)) {
                        $i++;
                        continue;
                    }
                    $r = $this->applyAt('GSUB', $lk, $buf, $i);
                    $i = $r < 0 ? $i + 1 : $r;
                }
            }
        }
        return $buf;
    }

    /**
     * Applies GPOS lookups (lookupIndex => mask, sorted by index).
     *
     * @param list<array{g: int, s: int, e: int, m: int}> $buf
     * @param array<int, int> $lookups
     * @return list<array<string, mixed>>
     */
    public function applyGpos(array $buf, array $lookups): array
    {
        foreach ($buf as $i => $_) {
            $buf[$i]['x'] = 0.0;
            $buf[$i]['y'] = 0.0;
            $buf[$i]['xa'] = 0.0;
            $buf[$i]['ya'] = 0.0;
        }
        if (!isset($this->t['GPOS'])) {
            return $buf;
        }
        foreach ($lookups as $li => $mask) {
            $lk = $this->lookup('GPOS', $li);
            if ($lk === null) {
                continue;
            }
            for ($i = 0; $i < count($buf);) {
                if (($buf[$i]['m'] & $mask) === 0 || $this->skip($buf[$i]['g'], $lk)) {
                    $i++;
                    continue;
                }
                $r = $this->applyAt('GPOS', $lk, $buf, $i);
                $i = $r < 0 ? $i + 1 : $r;
            }
        }
        return $buf;
    }

    /**
     * Glyph class from GDEF: 1 base, 2 ligature, 3 mark, 4 component, 0 unknown.
     */
    public function glyphClass(int $gid): int
    {
        if (!isset($this->t['GDEF'])) {
            return 0;
        }
        $off = $this->u16($this->t['GDEF'], 4);
        return $off === 0 ? 0 : $this->classOf('GDEF', $off, $gid);
    }

    public function hasGdef(): bool
    {
        return isset($this->t['GDEF']);
    }

    /**
     * Horizontal kerning from the legacy 'kern' table in 1/1000 em.
     */
    public function legacyKern(int $left, int $right): float
    {
        $pairs = $this->kernPairs();
        return isset($pairs[($left << 16) | $right]) ? $pairs[($left << 16) | $right] * $this->scale : 0.0;
    }

    /**
     * @return array<int, int>
     */
    private function kernPairs(): array
    {
        if ($this->kernPairs !== null) {
            return $this->kernPairs;
        }
        $this->kernPairs = [];
        $d = $this->kernTable;
        if ($d === null || strlen($d) < 4) {
            return $this->kernPairs;
        }
        $version = $this->u16($d, 0);
        if ($version !== 0) {
            return $this->kernPairs;
        }
        $nTables = $this->u16($d, 2);
        $pos = 4;
        for ($t = 0; $t < $nTables; $t++) {
            $len = $this->u16($d, $pos + 2);
            $coverage = $this->u16($d, $pos + 4);
            if (($coverage >> 8) === 0 && ($coverage & 1) === 1 && ($coverage & 4) === 0) {
                $n = $this->u16($d, $pos + 6);
                $p = $pos + 14;
                for ($i = 0; $i < $n; $i++, $p += 6) {
                    $this->kernPairs[($this->u16($d, $p) << 16) | $this->u16($d, $p + 2)] = $this->i16($d, $p + 4);
                }
            }
            $pos += max(6, $len);
        }
        return $this->kernPairs;
    }

    // ---- table headers -----------------------------------------------------------------------------------

    /**
     * @return array{scripts: array<string, array{def: ?int, langs: array<string, int>}>, features: list<array{0: string, 1: list<int>}>}|null
     */
    private function header(string $table): ?array
    {
        if (isset($this->headers[$table])) {
            return $this->headers[$table];
        }
        if (!isset($this->t[$table])) {
            return null;
        }
        $d = $this->t[$table];
        $scriptList = $this->u16($d, 4);
        $featureList = $this->u16($d, 6);
        $scripts = [];
        $n = $this->u16($d, $scriptList);
        for ($i = 0; $i < $n; $i++) {
            $tag = substr($d, $scriptList + 2 + 6 * $i, 4);
            $so = $scriptList + $this->u16($d, $scriptList + 6 + 6 * $i);
            $def = $this->u16($d, $so);
            $langs = [];
            $lc = $this->u16($d, $so + 2);
            for ($k = 0; $k < $lc; $k++) {
                $langs[substr($d, $so + 4 + 6 * $k, 4)] = $so + $this->u16($d, $so + 8 + 6 * $k);
            }
            $scripts[$tag] = ['def' => $def !== 0 ? $so + $def : null, 'langs' => $langs];
        }
        $features = [];
        $n = $this->u16($d, $featureList);
        for ($i = 0; $i < $n; $i++) {
            $tag = substr($d, $featureList + 2 + 6 * $i, 4);
            $fo = $featureList + $this->u16($d, $featureList + 6 + 6 * $i);
            $lc = $this->u16($d, $fo + 2);
            $ls = [];
            for ($k = 0; $k < $lc; $k++) {
                $ls[] = $this->u16($d, $fo + 4 + 2 * $k);
            }
            $features[] = [$tag, $ls];
        }
        return $this->headers[$table] = ['scripts' => $scripts, 'features' => $features];
    }

    /**
     * @return array{type: int, flag: int, mfs: int, subs: list<array{0: int, 1: int}>}|null
     */
    private function lookup(string $table, int $li): ?array
    {
        if (array_key_exists($li, $this->lookups[$table] ?? [])) {
            return $this->lookups[$table][$li];
        }
        $d = $this->t[$table] ?? null;
        if ($d === null) {
            return null;
        }
        $listOff = $this->u16($d, 8);
        $count = $this->u16($d, $listOff);
        if ($li >= $count) {
            return $this->lookups[$table][$li] = null;
        }
        $lo = $listOff + $this->u16($d, $listOff + 2 + 2 * $li);
        $type = $this->u16($d, $lo);
        $flag = $this->u16($d, $lo + 2);
        $sc = $this->u16($d, $lo + 4);
        $extType = $table === 'GSUB' ? 7 : 9;
        $subs = [];
        for ($i = 0; $i < $sc; $i++) {
            $so = $lo + $this->u16($d, $lo + 6 + 2 * $i);
            if ($type === $extType) {
                $rt = $this->u16($d, $so + 2);
                $subs[] = [$rt, $so + $this->u32($d, $so + 4)];
            } else {
                $subs[] = [$type, $so];
            }
        }
        $mfs = ($flag & 0x10) ? $this->u16($d, $lo + 6 + 2 * $sc) : 0;
        return $this->lookups[$table][$li] = ['type' => $type, 'flag' => $flag, 'mfs' => $mfs, 'subs' => $subs];
    }

    // ---- skipping / coverage / classes ---------------------------------------------------------------------

    /**
     * @param array{type: int, flag: int, mfs: int, subs: list<array{0: int, 1: int}>} $lk
     */
    private function skip(int $gid, array $lk): bool
    {
        $flag = $lk['flag'];
        if (($flag & 0xFF1E) === 0 || !isset($this->t['GDEF'])) {
            return false;
        }
        $cls = $this->glyphClass($gid);
        if ($cls === 1) {
            return ($flag & 2) !== 0;
        }
        if ($cls === 2) {
            return ($flag & 4) !== 0;
        }
        if ($cls === 3) {
            if ($flag & 8) {
                return true;
            }
            if ($flag & 0x10) {
                return !$this->inMarkSet($lk['mfs'], $gid);
            }
            $att = $flag >> 8;
            if ($att !== 0) {
                $off = $this->u16($this->t['GDEF'], 10);
                return $off === 0 || $this->classOf('GDEF', $off, $gid) !== $att;
            }
        }
        return false;
    }

    private function inMarkSet(int $set, int $gid): bool
    {
        $d = $this->t['GDEF'];
        if ($this->u16($d, 0) !== 1 || $this->u16($d, 2) < 2) {
            return true;
        }
        $msOff = $this->u16($d, 12);
        if ($msOff === 0) {
            return true;
        }
        $cnt = $this->u16($d, $msOff + 2);
        if ($set >= $cnt) {
            return false;
        }
        $covOff = $msOff + $this->u32($d, $msOff + 4 + 4 * $set);
        return $this->coverage('GDEF', $covOff, $gid) >= 0;
    }

    /**
     * Coverage index of $gid in the coverage table at $off, or -1.
     */
    private function coverage(string $table, int $off, int $gid): int
    {
        $key = $table . $off;
        $c = $this->coverageCache[$key] ?? null;
        if ($c === null) {
            $d = $this->t[$table];
            $fmt = $this->u16($d, $off);
            $n = $this->u16($d, $off + 2);
            if ($fmt === 1) {
                $glyphs = $n > 0 ? array_values(unpack('n*', substr($d, $off + 4, 2 * $n)) ?: []) : [];
                $c = ['f' => 1, 'm' => array_flip($glyphs)];
            } else {
                $ranges = [];
                for ($i = 0; $i < $n; $i++) {
                    $p = $off + 4 + 6 * $i;
                    $ranges[] = [$this->u16($d, $p), $this->u16($d, $p + 2), $this->u16($d, $p + 4)];
                }
                $c = ['f' => 2, 'r' => $ranges];
            }
            $this->coverageCache[$key] = $c;
        }
        if ($c['f'] === 1) {
            return $c['m'][$gid] ?? -1;
        }
        $lo = 0;
        $hi = count($c['r']) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            [$s, $e, $si] = $c['r'][$mid];
            if ($gid < $s) {
                $hi = $mid - 1;
            } elseif ($gid > $e) {
                $lo = $mid + 1;
            } else {
                return $si + $gid - $s;
            }
        }
        return -1;
    }

    /**
     * Class of $gid in the ClassDef table at $off (0 when absent).
     */
    private function classOf(string $table, int $off, int $gid): int
    {
        $key = $table . $off;
        $c = $this->classCache[$key] ?? null;
        if ($c === null) {
            $d = $this->t[$table];
            $fmt = $this->u16($d, $off);
            if ($fmt === 1) {
                $n = $this->u16($d, $off + 4);
                $arr = $n > 0 ? array_values(unpack('n*', substr($d, $off + 6, 2 * $n)) ?: []) : [];
                $c = ['f' => 1, 's' => $this->u16($d, $off + 2), 'a' => $arr];
            } else {
                $n = $this->u16($d, $off + 2);
                $ranges = [];
                for ($i = 0; $i < $n; $i++) {
                    $p = $off + 4 + 6 * $i;
                    $ranges[] = [$this->u16($d, $p), $this->u16($d, $p + 2), $this->u16($d, $p + 4)];
                }
                $c = ['f' => 2, 'r' => $ranges];
            }
            $this->classCache[$key] = $c;
        }
        if ($c['f'] === 1) {
            $idx = $gid - $c['s'];
            return $idx >= 0 ? ($c['a'][$idx] ?? 0) : 0;
        }
        $lo = 0;
        $hi = count($c['r']) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            [$s, $e, $cl] = $c['r'][$mid];
            if ($gid < $s) {
                $hi = $mid - 1;
            } elseif ($gid > $e) {
                $lo = $mid + 1;
            } else {
                return $cl;
            }
        }
        return 0;
    }

    // ---- lookup application --------------------------------------------------------------------------------

    /**
     * Applies the first matching subtable of a lookup at $i. Returns the next index to process or -1 when nothing matched.
     *
     * @param array{type: int, flag: int, mfs: int, subs: list<array{0: int, 1: int}>} $lk
     * @param array<int, array<string, mixed>> $buf
     */
    private function applyAt(string $table, array $lk, array &$buf, int $i): int
    {
        foreach ($lk['subs'] as [$type, $off]) {
            $r = $table === 'GSUB' ? $this->gsubSub($type, $off, $lk, $buf, $i) : $this->gposSub($type, $off, $lk, $buf, $i);
            if ($r >= 0) {
                return $r;
            }
        }
        return -1;
    }

    /**
     * @param array{type: int, flag: int, mfs: int, subs: list<array{0: int, 1: int}>} $lk
     * @param array<int, array<string, mixed>> $buf
     */
    private function gsubSub(int $type, int $off, array $lk, array &$buf, int $i): int
    {
        $d = $this->t['GSUB'];
        $g = $buf[$i]['g'];
        switch ($type) {
            case 1:
                $idx = $this->coverage('GSUB', $off + $this->u16($d, $off + 2), $g);
                if ($idx < 0) {
                    return -1;
                }
                if ($this->u16($d, $off) === 1) {
                    $buf[$i]['g'] = ($g + $this->i16($d, $off + 4)) & 0xFFFF;
                } else {
                    $buf[$i]['g'] = $this->u16($d, $off + 6 + 2 * $idx);
                }
                return $i + 1;

            case 2:
                $idx = $this->coverage('GSUB', $off + $this->u16($d, $off + 2), $g);
                if ($idx < 0) {
                    return -1;
                }
                $so = $off + $this->u16($d, $off + 6 + 2 * $idx);
                $cnt = $this->u16($d, $so);
                if ($cnt === 0) {
                    array_splice($buf, $i, 1);
                    return $i;
                }
                $new = [];
                for ($k = 0; $k < $cnt; $k++) {
                    $e = $buf[$i];
                    $e['g'] = $this->u16($d, $so + 2 + 2 * $k);
                    $new[] = $e;
                }
                array_splice($buf, $i, 1, $new);
                return $i + $cnt;

            case 3:
                $idx = $this->coverage('GSUB', $off + $this->u16($d, $off + 2), $g);
                if ($idx < 0) {
                    return -1;
                }
                $so = $off + $this->u16($d, $off + 6 + 2 * $idx);
                if ($this->u16($d, $so) < 1) {
                    return -1;
                }
                $buf[$i]['g'] = $this->u16($d, $so + 2);
                return $i + 1;

            case 4:
                $idx = $this->coverage('GSUB', $off + $this->u16($d, $off + 2), $g);
                if ($idx < 0) {
                    return -1;
                }
                $setOff = $off + $this->u16($d, $off + 6 + 2 * $idx);
                $ligCount = $this->u16($d, $setOff);
                for ($l = 0; $l < $ligCount; $l++) {
                    $lo = $setOff + $this->u16($d, $setOff + 2 + 2 * $l);
                    $ligGlyph = $this->u16($d, $lo);
                    $compCount = $this->u16($d, $lo + 2);
                    $pos = [$i];
                    $j = $i;
                    $ok = true;
                    for ($c = 1; $c < $compCount; $c++) {
                        $j = $this->nextIdx($buf, $j, $lk);
                        if ($j < 0 || $buf[$j]['g'] !== $this->u16($d, $lo + 2 + 2 * $c)) {
                            $ok = false;
                            break;
                        }
                        $pos[] = $j;
                    }
                    if (!$ok) {
                        continue;
                    }
                    $s = $buf[$i]['s'];
                    $e = $buf[$i]['e'];
                    foreach ($pos as $p) {
                        $s = min($s, $buf[$p]['s']);
                        $e = max($e, $buf[$p]['e']);
                    }
                    $buf[$i]['g'] = $ligGlyph;
                    $buf[$i]['s'] = $s;
                    $buf[$i]['e'] = $e;
                    for ($k = count($pos) - 1; $k >= 1; $k--) {
                        array_splice($buf, $pos[$k], 1);
                    }
                    return $i + 1;
                }
                return -1;

            case 5:
            case 6:
                return $this->contextSub('GSUB', $type === 6, $off, $lk, $buf, $i);
        }
        return -1;
    }

    /**
     * Contextual (type 5/7) and chaining contextual (type 6/8) subtables, formats 1-3, for GSUB and GPOS.
     *
     * @param array{type: int, flag: int, mfs: int, subs: list<array{0: int, 1: int}>} $lk
     * @param array<int, array<string, mixed>> $buf
     */
    private function contextSub(string $table, bool $chain, int $off, array $lk, array &$buf, int $i): int
    {
        $d = $this->t[$table];
        $fmt = $this->u16($d, $off);
        $g = $buf[$i]['g'];

        if ($fmt === 1) {
            $idx = $this->coverage($table, $off + $this->u16($d, $off + 2), $g);
            if ($idx < 0 || $idx >= $this->u16($d, $off + 4)) {
                return -1;
            }
            $setOff = $this->u16($d, $off + 6 + 2 * $idx);
            if ($setOff === 0) {
                return -1;
            }
            $setOff += $off;
            $rc = $this->u16($d, $setOff);
            for ($r = 0; $r < $rc; $r++) {
                $ro = $setOff + $this->u16($d, $setOff + 2 + 2 * $r);
                [$bt, $in, $la, $recs] = $this->readRule($d, $ro, $chain);
                $pos = [$i];
                if ($this->matchRule($table, $buf, $i, $lk, 0, 0, 0, 0, $bt, $in, $la, $pos)) {
                    return $this->applyRecords($table, $buf, $pos, $recs);
                }
            }
            return -1;
        }

        if ($fmt === 2) {
            $idx = $this->coverage($table, $off + $this->u16($d, $off + 2), $g);
            if ($idx < 0) {
                return -1;
            }
            if ($chain) {
                $btDef = $this->u16($d, $off + 4);
                $inDef = $this->u16($d, $off + 6);
                $laDef = $this->u16($d, $off + 8);
                $setCount = $this->u16($d, $off + 10);
                $setsAt = $off + 12;
            } else {
                $btDef = 0;
                $inDef = $this->u16($d, $off + 4);
                $laDef = 0;
                $setCount = $this->u16($d, $off + 6);
                $setsAt = $off + 8;
            }
            $inDefAbs = $off + $inDef;
            $class = $this->classOf($table, $inDefAbs, $g);
            if ($class >= $setCount) {
                return -1;
            }
            $setOff = $this->u16($d, $setsAt + 2 * $class);
            if ($setOff === 0) {
                return -1;
            }
            $setOff += $off;
            $rc = $this->u16($d, $setOff);
            for ($r = 0; $r < $rc; $r++) {
                $ro = $setOff + $this->u16($d, $setOff + 2 + 2 * $r);
                [$bt, $in, $la, $recs] = $this->readRule($d, $ro, $chain);
                $pos = [$i];
                if ($this->matchRule($table, $buf, $i, $lk, 1, $btDef ? $off + $btDef : 0, $inDefAbs, $laDef ? $off + $laDef : 0, $bt, $in, $la, $pos)) {
                    return $this->applyRecords($table, $buf, $pos, $recs);
                }
            }
            return -1;
        }

        if ($fmt === 3) {
            if ($chain) {
                $p = $off + 2;
                $btc = $this->u16($d, $p);
                $bt = [];
                for ($k = 0; $k < $btc; $k++) {
                    $bt[] = $off + $this->u16($d, $p + 2 + 2 * $k);
                }
                $p += 2 + 2 * $btc;
                $inc = $this->u16($d, $p);
                $inAll = [];
                for ($k = 0; $k < $inc; $k++) {
                    $inAll[] = $off + $this->u16($d, $p + 2 + 2 * $k);
                }
                $p += 2 + 2 * $inc;
                $lac = $this->u16($d, $p);
                $la = [];
                for ($k = 0; $k < $lac; $k++) {
                    $la[] = $off + $this->u16($d, $p + 2 + 2 * $k);
                }
                $p += 2 + 2 * $lac;
                $sc = $this->u16($d, $p);
                $recStart = $p + 2;
            } else {
                $inc = $this->u16($d, $off + 2);
                $sc = $this->u16($d, $off + 4);
                $inAll = [];
                for ($k = 0; $k < $inc; $k++) {
                    $inAll[] = $off + $this->u16($d, $off + 6 + 2 * $k);
                }
                $bt = [];
                $la = [];
                $recStart = $off + 6 + 2 * $inc;
            }
            if ($inAll === [] || $this->coverage($table, $inAll[0], $g) < 0) {
                return -1;
            }
            $recs = [];
            for ($k = 0; $k < $sc; $k++) {
                $recs[] = [$this->u16($d, $recStart + 4 * $k), $this->u16($d, $recStart + 4 * $k + 2)];
            }
            $pos = [$i];
            if ($this->matchRule($table, $buf, $i, $lk, 2, 0, 0, 0, $bt, array_slice($inAll, 1), $la, $pos)) {
                return $this->applyRecords($table, $buf, $pos, $recs);
            }
        }
        return -1;
    }

    /**
     * @return array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<array{0: int, 1: int}>} backtrack, input(after first), lookahead, records
     */
    private function readRule(string $d, int $ro, bool $chain): array
    {
        $bt = [];
        $la = [];
        $in = [];
        $p = $ro;
        if ($chain) {
            $bc = $this->u16($d, $p);
            for ($k = 0; $k < $bc; $k++) {
                $bt[] = $this->u16($d, $p + 2 + 2 * $k);
            }
            $p += 2 + 2 * $bc;
            $ic = $this->u16($d, $p);
            for ($k = 0; $k < $ic - 1; $k++) {
                $in[] = $this->u16($d, $p + 2 + 2 * $k);
            }
            $p += 2 + 2 * max(0, $ic - 1);
            $lc = $this->u16($d, $p);
            for ($k = 0; $k < $lc; $k++) {
                $la[] = $this->u16($d, $p + 2 + 2 * $k);
            }
            $p += 2 + 2 * $lc;
            $sc = $this->u16($d, $p);
            $p += 2;
        } else {
            $ic = $this->u16($d, $p);
            $sc = $this->u16($d, $p + 2);
            for ($k = 0; $k < $ic - 1; $k++) {
                $in[] = $this->u16($d, $p + 4 + 2 * $k);
            }
            $p += 4 + 2 * max(0, $ic - 1);
        }
        $recs = [];
        for ($k = 0; $k < $sc; $k++) {
            $recs[] = [$this->u16($d, $p + 4 * $k), $this->u16($d, $p + 4 * $k + 2)];
        }
        return [$bt, $in, $la, $recs];
    }

    /**
     * @param array<int, array<string, mixed>> $buf
     * @param array{type: int, flag: int, mfs: int, subs: list<array{0: int, 1: int}>} $lk
     * @param list<int> $bt
     * @param list<int> $in
     * @param list<int> $la
     * @param list<int> $pos
     */
    private function matchRule(string $table, array &$buf, int $i, array $lk, int $mode, int $btDef, int $inDef, int $laDef, array $bt, array $in, array $la, array &$pos): bool
    {
        $j = $i;
        foreach ($in as $item) {
            $j = $this->nextIdx($buf, $j, $lk);
            if ($j < 0 || !$this->testItem($table, $mode, $inDef, $item, $buf[$j]['g'])) {
                return false;
            }
            $pos[] = $j;
        }
        $k = $j;
        foreach ($la as $item) {
            $k = $this->nextIdx($buf, $k, $lk);
            if ($k < 0 || !$this->testItem($table, $mode, $laDef, $item, $buf[$k]['g'])) {
                return false;
            }
        }
        $k = $i;
        foreach ($bt as $item) {
            $k = $this->prevIdx($buf, $k, $lk);
            if ($k < 0 || !$this->testItem($table, $mode, $btDef, $item, $buf[$k]['g'])) {
                return false;
            }
        }
        return true;
    }

    private function testItem(string $table, int $mode, int $defOff, int $item, int $gid): bool
    {
        if ($mode === 0) {
            return $item === $gid;
        }
        if ($mode === 1) {
            return $this->classOf($table, $defOff, $gid) === $item;
        }
        return $this->coverage($table, $item, $gid) >= 0;
    }

    /**
     * @param array<int, array<string, mixed>> $buf
     * @param list<int> $pos
     * @param list<array{0: int, 1: int}> $recs
     */
    private function applyRecords(string $table, array &$buf, array $pos, array $recs): int
    {
        if ($this->depth > 4) {
            return end($pos) + 1;
        }
        $this->depth++;
        foreach ($recs as [$seqIdx, $li]) {
            if (!isset($pos[$seqIdx])) {
                continue;
            }
            $p = $pos[$seqIdx];
            $lk = $this->lookup($table, $li);
            if ($lk === null || !isset($buf[$p])) {
                continue;
            }
            $before = count($buf);
            $this->applyAt($table, $lk, $buf, $p);
            $delta = count($buf) - $before;
            if ($delta !== 0) {
                foreach ($pos as $k => $pp) {
                    if ($pp > $p) {
                        $pos[$k] = $pp + $delta;
                    }
                }
            }
        }
        $this->depth--;
        return min(count($buf), end($pos) + 1);
    }

    /**
     * @param array<int, array<string, mixed>> $buf
     * @param array{type: int, flag: int, mfs: int, subs: list<array{0: int, 1: int}>} $lk
     */
    private function nextIdx(array &$buf, int $j, array $lk): int
    {
        $n = count($buf);
        for ($j++; $j < $n; $j++) {
            if (!$this->skip($buf[$j]['g'], $lk)) {
                return $j;
            }
        }
        return -1;
    }

    /**
     * @param array<int, array<string, mixed>> $buf
     * @param array{type: int, flag: int, mfs: int, subs: list<array{0: int, 1: int}>} $lk
     */
    private function prevIdx(array &$buf, int $j, array $lk): int
    {
        for ($j--; $j >= 0; $j--) {
            if (!$this->skip($buf[$j]['g'], $lk)) {
                return $j;
            }
        }
        return -1;
    }

    // ---- GPOS ---------------------------------------------------------------------------------------------

    /**
     * @param array{type: int, flag: int, mfs: int, subs: list<array{0: int, 1: int}>} $lk
     * @param array<int, array<string, mixed>> $buf
     */
    private function gposSub(int $type, int $off, array $lk, array &$buf, int $i): int
    {
        $d = $this->t['GPOS'];
        $g = $buf[$i]['g'];
        switch ($type) {
            case 1:
                $idx = $this->coverage('GPOS', $off + $this->u16($d, $off + 2), $g);
                if ($idx < 0) {
                    return -1;
                }
                $vf = $this->u16($d, $off + 4);
                if ($this->u16($d, $off) === 1) {
                    $v = $this->readValue($d, $off + 6, $vf);
                } else {
                    $v = $this->readValue($d, $off + 8 + $idx * $this->valueSize($vf), $vf);
                }
                $this->addValue($buf[$i], $v);
                return $i + 1;

            case 2:
                $j = $this->nextIdx($buf, $i, $lk);
                if ($j < 0) {
                    return -1;
                }
                $idx = $this->coverage('GPOS', $off + $this->u16($d, $off + 2), $g);
                if ($idx < 0) {
                    return -1;
                }
                $vf1 = $this->u16($d, $off + 4);
                $vf2 = $this->u16($d, $off + 6);
                $s1 = $this->valueSize($vf1);
                $s2 = $this->valueSize($vf2);
                $g2 = $buf[$j]['g'];
                if ($this->u16($d, $off) === 1) {
                    $setOff = $off + $this->u16($d, $off + 10 + 2 * $idx);
                    $n = $this->u16($d, $setOff);
                    $recSize = 2 + $s1 + $s2;
                    $lo = 0;
                    $hi = $n - 1;
                    while ($lo <= $hi) {
                        $mid = ($lo + $hi) >> 1;
                        $rp = $setOff + 2 + $mid * $recSize;
                        $sg = $this->u16($d, $rp);
                        if ($g2 < $sg) {
                            $hi = $mid - 1;
                        } elseif ($g2 > $sg) {
                            $lo = $mid + 1;
                        } else {
                            $this->addValue($buf[$i], $this->readValue($d, $rp + 2, $vf1));
                            $this->addValue($buf[$j], $this->readValue($d, $rp + 2 + $s1, $vf2));
                            return $vf2 !== 0 ? $j + 1 : $j;
                        }
                    }
                    return -1;
                }
                $c1Off = $this->u16($d, $off + 8);
                $c2Off = $this->u16($d, $off + 10);
                $c2Count = $this->u16($d, $off + 14);
                $c1 = $c1Off !== 0 ? $this->classOf('GPOS', $off + $c1Off, $g) : 0;
                $c2 = $c2Off !== 0 ? $this->classOf('GPOS', $off + $c2Off, $g2) : 0;
                if ($c1 >= $this->u16($d, $off + 12) || $c2 >= $c2Count) {
                    return -1;
                }
                $rp = $off + 16 + ($c1 * $c2Count + $c2) * ($s1 + $s2);
                $v1 = $this->readValue($d, $rp, $vf1);
                $v2 = $this->readValue($d, $rp + $s1, $vf2);
                $this->addValue($buf[$i], $v1);
                $this->addValue($buf[$j], $v2);
                if ($vf1 === 0 && $vf2 === 0) {
                    return -1;
                }
                return $vf2 !== 0 ? $j + 1 : $j;

            case 4:
            case 5:
            case 6:
                return $this->markSub($type, $off, $buf, $i);

            case 7:
            case 8:
                return $this->contextSub('GPOS', $type === 8, $off, $lk, $buf, $i);
        }
        return -1;
    }

    /**
     * Mark-to-base (4), mark-to-ligature (5) and mark-to-mark (6) attachment.
     *
     * @param array<int, array<string, mixed>> $buf
     */
    private function markSub(int $type, int $off, array &$buf, int $i): int
    {
        $d = $this->t['GPOS'];
        $markIdx = $this->coverage('GPOS', $off + $this->u16($d, $off + 2), $buf[$i]['g']);
        if ($markIdx < 0) {
            return -1;
        }
        $baseCovOff = $off + $this->u16($d, $off + 4);
        $classCount = $this->u16($d, $off + 6);
        $markArr = $off + $this->u16($d, $off + 8);
        $baseArr = $off + $this->u16($d, $off + 10);

        // Find the attachment target
        $j = $i - 1;
        if ($type === 6) {
            if ($j < 0 || $this->glyphClass($buf[$j]['g']) !== 3 && !isset($buf[$j]['att'])) {
                return -1;
            }
        } else {
            while ($j >= 0 && ($this->glyphClass($buf[$j]['g']) === 3 || isset($buf[$j]['att']))) {
                $j--;
            }
            if ($j < 0) {
                return -1;
            }
        }
        $baseIdx = $this->coverage('GPOS', $baseCovOff, $buf[$j]['g']);
        if ($baseIdx < 0 || $markIdx >= $this->u16($d, $markArr)) {
            return -1;
        }
        $mrec = $markArr + 2 + 4 * $markIdx;
        $mClass = $this->u16($d, $mrec);
        $mAnchorOff = $this->u16($d, $mrec + 2);
        if ($mClass >= $classCount || $mAnchorOff === 0) {
            return -1;
        }
        $markAnchor = $this->anchor($d, $markArr + $mAnchorOff);

        if ($type === 5) {
            if ($baseIdx >= $this->u16($d, $baseArr)) {
                return -1;
            }
            $la = $baseArr + $this->u16($d, $baseArr + 2 + 2 * $baseIdx);
            $comps = $this->u16($d, $la);
            if ($comps < 1) {
                return -1;
            }
            $aOff = $this->u16($d, $la + 2 + 2 * (($comps - 1) * $classCount + $mClass));
            $baseAnchor = $aOff !== 0 ? $this->anchor($d, $la + $aOff) : null;
        } else {
            if ($baseIdx >= $this->u16($d, $baseArr)) {
                return -1;
            }
            $aOff = $this->u16($d, $baseArr + 2 + 2 * ($baseIdx * $classCount + $mClass));
            $baseAnchor = $aOff !== 0 ? $this->anchor($d, $baseArr + $aOff) : null;
        }
        if ($baseAnchor === null) {
            return -1;
        }
        $buf[$i]['att'] = [$j, $baseAnchor[0] - $markAnchor[0], $baseAnchor[1] - $markAnchor[1]];
        return $i + 1;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function anchor(string $d, int $off): array
    {
        return [$this->i16($d, $off + 2) * $this->scale, $this->i16($d, $off + 4) * $this->scale];
    }

    private function valueSize(int $fmt): int
    {
        $n = 0;
        for ($b = 1; $b <= 0x80; $b <<= 1) {
            if ($fmt & $b) {
                $n += 2;
            }
        }
        return $n;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float} xPlacement, yPlacement, xAdvance, yAdvance
     */
    private function readValue(string $d, int $off, int $fmt): array
    {
        $v = [0.0, 0.0, 0.0, 0.0];
        if ($fmt === 0) {
            return $v;
        }
        $p = $off;
        for ($b = 0; $b < 8; $b++) {
            if ($fmt & (1 << $b)) {
                if ($b < 4) {
                    $v[$b] = $this->i16($d, $p) * $this->scale;
                }
                $p += 2;
            }
        }
        return $v;
    }

    /**
     * @param array<string, mixed> $glyph
     * @param array{0: float, 1: float, 2: float, 3: float} $v
     */
    private function addValue(array &$glyph, array $v): void
    {
        $glyph['x'] += $v[0];
        $glyph['y'] += $v[1];
        $glyph['xa'] += $v[2];
        $glyph['ya'] += $v[3];
    }

    // ---- binary helpers -------------------------------------------------------------------------------------

    private function u16(string $d, int $o): int
    {
        return (ord($d[$o] ?? "\0") << 8) | ord($d[$o + 1] ?? "\0");
    }

    private function i16(string $d, int $o): int
    {
        $v = $this->u16($d, $o);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32(string $d, int $o): int
    {
        return ($this->u16($d, $o) << 16) | $this->u16($d, $o + 2);
    }
}
