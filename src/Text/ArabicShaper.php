<?php

declare(strict_types=1);

namespace IvoPdf\Text;

/**
 * Arabic/Syriac joining analysis (ArabicShaping.txt joining types) and the Unicode Arabic Presentation
 * Forms-B fallback used when a font has no GSUB init/medi/fina lookups.
 */
final class ArabicShaper
{
    public const J_U = 0;   // non-joining
    public const J_R = 1;   // right-joining
    public const J_D = 2;   // dual-joining
    public const J_C = 3;   // join-causing
    public const J_T = 4;   // transparent

    public const F_NONE = 0;
    public const F_ISOL = 1;
    public const F_INIT = 2;
    public const F_MEDI = 3;
    public const F_FINA = 4;

    /** @var array<int, int>|null */
    private static ?array $jt = null;

    /** @var array<int, array{0: int, 1: int, 2: int, 3: int}>|null base cp => [isol, fina, init, medi] (0 = none) */
    private static ?array $forms = null;

    public static function isArabicLike(int $cp): bool
    {
        return ($cp >= 0x0600 && $cp <= 0x06FF) || ($cp >= 0x0700 && $cp <= 0x074F) || ($cp >= 0x0750 && $cp <= 0x077F)
            || ($cp >= 0x08A0 && $cp <= 0x08FF) || ($cp >= 0xFB50 && $cp <= 0xFDFF) || ($cp >= 0xFE70 && $cp <= 0xFEFF);
    }

    public static function joiningType(int $cp): int
    {
        if (self::$jt === null) {
            self::initTypes();
        }
        if (isset(self::$jt[$cp])) {
            return self::$jt[$cp];
        }
        if ($cp === 0x200D) {
            return self::J_C;
        }
        if ($cp === 0x200C) {
            return self::J_U;
        }
        // Combining marks (Mn/Me/Cf) are transparent
        if (($cp >= 0x064B && $cp <= 0x065F) || ($cp >= 0x0610 && $cp <= 0x061A) || ($cp >= 0x08D3 && $cp <= 0x08FF)
            || ($cp >= 0x0730 && $cp <= 0x074A) || ($cp >= 0x0300 && $cp <= 0x036F) || $cp === 0x0670 || $cp === 0x061C) {
            return self::J_T;
        }
        // Presentation forms and other Arabic letters default to dual-joining within the letter blocks
        if (($cp >= 0x0750 && $cp <= 0x077F) || ($cp >= 0x08A0 && $cp <= 0x08D2)) {
            return self::J_D;
        }
        return self::J_U;
    }

    /**
     * Contextual form of every codepoint (F_* constants, F_NONE for non-joining/transparent characters).
     *
     * @param list<int> $cps
     * @return list<int>
     */
    public static function analyze(array $cps): array
    {
        $n = count($cps);
        $types = [];
        foreach ($cps as $cp) {
            $types[] = self::joiningType($cp);
        }
        $forms = array_fill(0, $n, self::F_NONE);
        $prev = -1; // index of previous non-transparent char
        for ($i = 0; $i < $n; $i++) {
            $t = $types[$i];
            if ($t === self::J_T) {
                continue;
            }
            // next non-transparent
            $next = -1;
            for ($k = $i + 1; $k < $n; $k++) {
                if ($types[$k] !== self::J_T) {
                    $next = $k;
                    break;
                }
            }
            if ($t === self::J_U) {
                $prev = $i;
                continue;
            }
            $joinPrev = $prev >= 0 && ($types[$prev] === self::J_D || $types[$prev] === self::J_C);
            $joinNext = $next >= 0 && $t !== self::J_R && ($types[$next] === self::J_D || $types[$next] === self::J_R || $types[$next] === self::J_C);
            if ($t === self::J_R) {
                $forms[$i] = $joinPrev ? self::F_FINA : self::F_ISOL;
            } elseif ($joinPrev && $joinNext) {
                $forms[$i] = self::F_MEDI;
            } elseif ($joinPrev) {
                $forms[$i] = self::F_FINA;
            } elseif ($joinNext) {
                $forms[$i] = self::F_INIT;
            } else {
                $forms[$i] = self::F_ISOL;
            }
            $prev = $i;
        }
        return $forms;
    }

    /**
     * Presentation Forms-B codepoint for a base letter in a given form, or null.
     */
    public static function presentationForm(int $cp, int $form): ?int
    {
        if (self::$forms === null) {
            self::initForms();
        }
        $f = self::$forms[$cp] ?? null;
        if ($f === null) {
            return null;
        }
        $v = match ($form) {
            self::F_ISOL => $f[0],
            self::F_FINA => $f[1],
            self::F_INIT => $f[2],
            self::F_MEDI => $f[3],
            default => 0,
        };
        return $v > 0 ? $v : null;
    }

    /**
     * Lam-alef ligature codepoint (isolated or final form), or null when $alef is not an alef variant.
     */
    public static function lamAlef(int $alef, bool $final): ?int
    {
        $base = match ($alef) {
            0x0622 => 0xFEF5,
            0x0623 => 0xFEF7,
            0x0625 => 0xFEF9,
            0x0627 => 0xFEFB,
            default => null,
        };
        return $base === null ? null : $base + ($final ? 1 : 0);
    }

    private static function initTypes(): void
    {
        $t = [];
        $set = static function (int $from, int $to, int $type) use (&$t): void {
            for ($c = $from; $c <= $to; $c++) {
                $t[$c] = $type;
            }
        };
        $set(0x0600, 0x0605, self::J_U);
        $set(0x0610, 0x061A, self::J_T);
        $t[0x061C] = self::J_T;
        $t[0x0620] = self::J_D;
        $t[0x0621] = self::J_U;
        $set(0x0622, 0x0625, self::J_R);
        $t[0x0626] = self::J_D;
        $t[0x0627] = self::J_R;
        $t[0x0628] = self::J_D;
        $t[0x0629] = self::J_R;
        $set(0x062A, 0x062E, self::J_D);
        $set(0x062F, 0x0632, self::J_R);
        $set(0x0633, 0x063F, self::J_D);
        $t[0x0640] = self::J_C;
        $set(0x0641, 0x0647, self::J_D);
        $t[0x0648] = self::J_R;
        $set(0x0649, 0x064A, self::J_D);
        $set(0x064B, 0x065F, self::J_T);
        $set(0x0660, 0x066D, self::J_U);
        $set(0x066E, 0x066F, self::J_D);
        $t[0x0670] = self::J_T;
        $set(0x0671, 0x0673, self::J_R);
        $t[0x0674] = self::J_U;
        $set(0x0675, 0x0677, self::J_R);
        $set(0x0678, 0x0687, self::J_D);
        $set(0x0688, 0x0699, self::J_R);
        $set(0x069A, 0x06BF, self::J_D);
        $t[0x06C0] = self::J_R;
        $set(0x06C1, 0x06C2, self::J_D);
        $set(0x06C3, 0x06CB, self::J_R);
        $t[0x06CC] = self::J_D;
        $t[0x06CD] = self::J_R;
        $t[0x06CE] = self::J_D;
        $t[0x06CF] = self::J_R;
        $set(0x06D0, 0x06D1, self::J_D);
        $set(0x06D2, 0x06D3, self::J_R);
        $t[0x06D4] = self::J_U;
        $t[0x06D5] = self::J_R;
        $set(0x06D6, 0x06DC, self::J_T);
        $set(0x06DD, 0x06DE, self::J_U);
        $set(0x06DF, 0x06E4, self::J_T);
        $set(0x06E5, 0x06E6, self::J_U);
        $set(0x06E7, 0x06E8, self::J_T);
        $t[0x06E9] = self::J_U;
        $set(0x06EA, 0x06ED, self::J_T);
        $set(0x06EE, 0x06EF, self::J_R);
        $set(0x06F0, 0x06F9, self::J_U);
        $set(0x06FA, 0x06FF, self::J_D);
        // Syriac
        $t[0x0710] = self::J_R;
        $t[0x0711] = self::J_T;
        $set(0x0712, 0x072F, self::J_D);
        foreach ([0x0715, 0x0716, 0x0717, 0x0718, 0x0719, 0x071E, 0x0728, 0x072A, 0x072C, 0x072F] as $c) {
            $t[$c] = self::J_R;
        }
        $set(0x0730, 0x074A, self::J_T);
        $t[0x074D] = self::J_R;
        $set(0x074E, 0x074F, self::J_D);
        // Arabic Supplement
        $set(0x0750, 0x077F, self::J_D);
        foreach ([0x0759, 0x075A, 0x075B, 0x076B, 0x076C, 0x0771, 0x0773, 0x0774, 0x0778, 0x0779] as $c) {
            $t[$c] = self::J_R;
        }
        $set(0x08D3, 0x08E1, self::J_T);
        $set(0x08E3, 0x08FF, self::J_T);
        self::$jt = $t;
    }

    private static function initForms(): void
    {
        // Presentation Forms-B layout starting at U+FE80: [base, number of forms (1, 2 or 4)]
        $seq = [
            [0x0621, 1], [0x0622, 2], [0x0623, 2], [0x0624, 2], [0x0625, 2], [0x0626, 4], [0x0627, 2], [0x0628, 4],
            [0x0629, 2], [0x062A, 4], [0x062B, 4], [0x062C, 4], [0x062D, 4], [0x062E, 4], [0x062F, 2], [0x0630, 2],
            [0x0631, 2], [0x0632, 2], [0x0633, 4], [0x0634, 4], [0x0635, 4], [0x0636, 4], [0x0637, 4], [0x0638, 4],
            [0x0639, 4], [0x063A, 4], [0x0641, 4], [0x0642, 4], [0x0643, 4], [0x0644, 4], [0x0645, 4], [0x0646, 4],
            [0x0647, 4], [0x0648, 2], [0x0649, 2], [0x064A, 4],
        ];
        $forms = [];
        $cp = 0xFE80;
        foreach ($seq as [$base, $count]) {
            if ($count === 1) {
                $forms[$base] = [$cp, 0, 0, 0];
            } elseif ($count === 2) {
                $forms[$base] = [$cp, $cp + 1, 0, 0];
            } else {
                $forms[$base] = [$cp, $cp + 1, $cp + 2, $cp + 3];
            }
            $cp += $count;
        }
        // Persian / Urdu letters in Presentation Forms-A (isol, fina, init, medi)
        $forms[0x067E] = [0xFB56, 0xFB57, 0xFB58, 0xFB59];
        $forms[0x0686] = [0xFB7A, 0xFB7B, 0xFB7C, 0xFB7D];
        $forms[0x0698] = [0xFB8A, 0xFB8B, 0, 0];
        $forms[0x06A9] = [0xFB8E, 0xFB8F, 0xFB90, 0xFB91];
        $forms[0x06AF] = [0xFB92, 0xFB93, 0xFB94, 0xFB95];
        $forms[0x06CC] = [0xFBFC, 0xFBFD, 0xFBFE, 0xFBFF];
        self::$forms = $forms;
    }
}
