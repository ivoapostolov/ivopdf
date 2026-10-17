<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use IvoPdf\Options;

/**
 * The PDF/A output intent: sRGB (bundled) by default, or the CMYK ICC output profile of Options::$pdfaIccProfile
 * (mPDF's `ICCProfile`). With a CMYK profile the whole document is written in DeviceCMYK (see ColorOps), which the
 * CMYK output intent allows; without one, CMYK colors are converted to RGB.
 */
final class PdfAIntent
{
    /** @var array<string, string> path => validated profile bytes */
    private static array $cache = [];

    /**
     * Bytes of the configured CMYK output profile, or null when none is configured (the sRGB intent is used).
     *
     * @throws \LogicException when the configured file is not a usable CMYK ICC output profile for this PDF/A part
     */
    public static function profile(Options $o, int $part): ?string
    {
        $path = trim($o->pdfaIccProfile);
        if ($path === '') {
            return null;
        }
        $key = $path . '|' . $part;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        if (Options::isNetworkPath($path)) {
            throw new \LogicException('PDF/A ICC profile refused (UNC / network or stream-wrapper paths are not read): ' . $path);
        }
        if (!is_file($path) || !is_readable($path) || filesize($path) > 8 * 1048576) {
            throw new \LogicException('PDF/A ICC profile not found or too large: ' . $path);
        }
        $icc = (string) file_get_contents($path);
        $problem = self::check($icc, $part);
        if ($problem !== null) {
            throw new \LogicException("PDF/A ICC profile {$path}: {$problem}");
        }
        if (count(self::$cache) > 8) {
            self::$cache = [];
        }
        return self::$cache[$key] = $icc;
    }

    /** Why an ICC profile cannot be the CMYK output intent of PDF/A-$part, or null when it can. */
    public static function check(string $icc, int $part): ?string
    {
        if (strlen($icc) < 132 || substr($icc, 36, 4) !== 'acsp') {
            return 'not an ICC profile';
        }
        if (substr($icc, 16, 4) !== 'CMYK') {
            return 'the colour space of the profile must be CMYK (an RGB document uses the bundled sRGB intent)';
        }
        if (!in_array(substr($icc, 12, 4), ['prtr', 'mntr', 'scnr', 'spac'], true)) {
            return 'the profile class must be output (prtr), display, input or colour space';
        }
        $major = ord($icc[8]);
        if ($part === 1 && $major >= 4) {
            return 'PDF/A-1 needs an ICC version 2 profile (PDF 1.4)';
        }
        if ($major > 4) {
            return 'ICC version ' . $major . ' profiles are not allowed in PDF/A';
        }
        return null;
    }

    /** /Info text of the intent: the profile's file name, like mPDF. */
    public static function info(Options $o): string
    {
        return str_replace('_', ' ', pathinfo($o->pdfaIccProfile, PATHINFO_FILENAME));
    }
}
