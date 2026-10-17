<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

/**
 * PDF standard security handler: AES-256 (V5/R6, ISO 32000-2), AES-128 (V4/R4) and RC4-128 (V2/R3).
 * Provides per-object string/stream encryption and the /Encrypt dictionary.
 */
final class PdfEncryption
{
    /** Permission name => bit mask (ISO 32000-1 table 22). Includes mPDF / dompdf style aliases. */
    public const PERMISSIONS = [
        'print' => 0x004,
        'modify' => 0x008,
        'copy' => 0x010,
        'annotate' => 0x020,
        'annot-forms' => 0x020,
        'fill-forms' => 0x100,
        'extract' => 0x200,
        'assemble' => 0x400,
        'print-high' => 0x800,
        'print-highres' => 0x800,
    ];

    private const PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    public readonly string $method;
    private string $fileKey;
    private string $o;
    private string $u;
    private string $oe = '';
    private string $ue = '';
    private string $perms = '';
    private int $p;
    private int $revision;
    private bool $encryptMetadata;

    /**
     * @param list<string> $permissions allowed operations (see PERMISSIONS), or ['all']
     * @param string|int $method 'aes256' | 'aes128' | 'rc4' (mPDF lengths 40/128 => rc4, 256 => aes256)
     */
    public function __construct(
        array $permissions,
        string $userPassword,
        ?string $ownerPassword,
        string|int $method,
        string $documentId,
        bool $encryptMetadata = true
    ) {
        $this->method = self::normalizeMethod($method);
        $this->encryptMetadata = $encryptMetadata;
        $this->p = self::permissionValue($permissions);
        if ($ownerPassword === null || $ownerPassword === '') {
            $ownerPassword = bin2hex(random_bytes(16));
        }

        if ($this->method === 'aes256') {
            $this->revision = 6;
            $this->initAes256($userPassword, $ownerPassword);
        } else {
            $this->revision = $this->method === 'aes128' ? 4 : 3;
            $this->initLegacy($userPassword, $ownerPassword, $documentId);
        }
    }

    public static function normalizeMethod(string|int $method): string
    {
        $m = strtolower(trim((string) $method));
        return match ($m) {
            'aes256', 'aes-256', 'aes_256', 'aesv3', '256' => 'aes256',
            'aes128', 'aes-128', 'aes_128', 'aesv2' => 'aes128',
            'rc4', 'rc4-128', 'rc4_128', 'rc4128', '40', '128' => 'rc4',
            default => throw new \InvalidArgumentException("Unknown encryption method '{$method}' (use aes256, aes128 or rc4)"),
        };
    }

    /**
     * @param list<string> $permissions
     * @return int signed 32-bit /P value
     */
    public static function permissionValue(array $permissions): int
    {
        $bits = 0;
        foreach ($permissions as $perm) {
            $perm = strtolower(trim((string) $perm));
            if ($perm === 'all') {
                $bits |= 0xF3C; // every defined permission bit
                continue;
            }
            if (!isset(self::PERMISSIONS[$perm])) {
                throw new \InvalidArgumentException("Unknown permission '{$perm}'");
            }
            $bits |= self::PERMISSIONS[$perm];
        }
        $p = (0xFFFFF0C0 | $bits) & 0xFFFFFFFF;
        return $p >= 0x80000000 ? $p - 0x100000000 : $p;
    }

    public function encryptsMetadata(): bool
    {
        return $this->encryptMetadata;
    }

    public function getMinimumVersion(): string
    {
        return $this->method === 'aes256' ? '2.0' : '1.7';
    }

    /**
     * Encrypts a string or stream payload belonging to indirect object $objNum.
     */
    public function encrypt(string $data, int $objNum): string
    {
        if ($this->method === 'aes256') {
            return $this->aesCbc($data, $this->fileKey);
        }
        $suffix = pack('V', $objNum & 0xFFFFFF) ;
        $key = $this->fileKey . substr($suffix, 0, 3) . "\x00\x00";
        if ($this->method === 'aes128') {
            $key .= "sAlT";
        }
        $objKey = substr(md5($key, true), 0, min(strlen($this->fileKey) + 5, 16));
        return $this->method === 'aes128' ? $this->aesCbc($data, $objKey) : self::rc4($objKey, $data);
    }

    /**
     * Body of the /Encrypt dictionary (written unencrypted).
     */
    public function dictionary(): string
    {
        $h = static fn(string $s): string => '<' . strtoupper(bin2hex($s)) . '>';
        $meta = $this->encryptMetadata ? '' : ' /EncryptMetadata false';
        if ($this->method === 'aes256') {
            return '<< /Filter /Standard /V 5 /R 6 /Length 256'
                . ' /CF << /StdCF << /AuthEvent /DocOpen /CFM /AESV3 /Length 32 >> >> /StmF /StdCF /StrF /StdCF'
                . ' /O ' . $h($this->o) . ' /U ' . $h($this->u) . ' /OE ' . $h($this->oe) . ' /UE ' . $h($this->ue)
                . ' /P ' . $this->p . ' /Perms ' . $h($this->perms) . $meta . ' >>';
        }
        if ($this->method === 'aes128') {
            return '<< /Filter /Standard /V 4 /R 4 /Length 128'
                . ' /CF << /StdCF << /AuthEvent /DocOpen /CFM /AESV2 /Length 16 >> >> /StmF /StdCF /StrF /StdCF'
                . ' /O ' . $h($this->o) . ' /U ' . $h($this->u) . ' /P ' . $this->p . $meta . ' >>';
        }
        return '<< /Filter /Standard /V 2 /R 3 /Length 128 /O ' . $h($this->o) . ' /U ' . $h($this->u) . ' /P ' . $this->p . ' >>';
    }

    /**
     * Converts a password to the byte string the R2-R4 (RC4 / AES-128) handlers hash: ISO 32000-1 7.6.3.3 Algorithm 2 step (a)
     * requires PDFDocEncoding, which is what Acrobat, qpdf and MuPDF do with a typed password. Strings that are not valid UTF-8
     * are taken as already-encoded bytes (qpdf "auto" password mode). A password with characters outside PDFDocEncoding (e.g.
     * Cyrillic, Greek, CJK) cannot be typed into any conforming reader for these methods, so - like qpdf - it is refused with an
     * InvalidArgumentException instead of producing a file nobody can open; use 'aes256' (UTF-8/SASLprep, ISO 32000-2) instead.
     */
    public static function legacyPasswordBytes(string $password): string
    {
        if ($password === '' || !preg_match('//u', $password) || !preg_match('/[\x80-\xFF]/', $password)) {
            return $password;
        }
        static $special = null;
        $special ??= [
            0x02D8 => 0x18, 0x02C7 => 0x19, 0x02C6 => 0x1A, 0x02D9 => 0x1B, 0x02DD => 0x1C, 0x02DB => 0x1D, 0x02DA => 0x1E,
            0x02DC => 0x1F, 0x2022 => 0x80, 0x2020 => 0x81, 0x2021 => 0x82, 0x2026 => 0x83, 0x2014 => 0x84, 0x2013 => 0x85,
            0x0192 => 0x86, 0x2044 => 0x87, 0x2039 => 0x88, 0x203A => 0x89, 0x2212 => 0x8A, 0x2030 => 0x8B, 0x201E => 0x8C,
            0x201C => 0x8D, 0x201D => 0x8E, 0x2018 => 0x8F, 0x2019 => 0x90, 0x201A => 0x91, 0x2122 => 0x92, 0xFB01 => 0x93,
            0xFB02 => 0x94, 0x0141 => 0x95, 0x0152 => 0x96, 0x0160 => 0x97, 0x0178 => 0x98, 0x017D => 0x99, 0x0131 => 0x9A,
            0x0142 => 0x9B, 0x0153 => 0x9C, 0x0161 => 0x9D, 0x017E => 0x9E, 0x20AC => 0xA0,
        ];
        $out = '';
        foreach (preg_split('//u', $password, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            if ($cp < 0x18 || ($cp >= 0x20 && $cp <= 0x7E)) {
                $out .= chr($cp);
            } elseif ($cp >= 0xA1 && $cp <= 0xFF && $cp !== 0xAD) {
                $out .= chr($cp);
            } elseif (isset($special[$cp])) {
                $out .= chr($special[$cp]);
            } else {
                throw new \InvalidArgumentException(sprintf(
                    'The password contains the character U+%04X, which cannot be represented in PDFDocEncoding as RC4 / AES-128 '
                    . 'encryption requires (ISO 32000-1 7.6.3.3); readers could not open the file. Use the "aes256" method for '
                    . 'Unicode passwords.',
                    $cp
                ));
            }
        }
        return $out;
    }

    private function initLegacy(string $userPassword, string $ownerPassword, string $id0): void
    {
        $idBytes = $id0;
        $userPassword = self::legacyPasswordBytes($userPassword);
        $ownerPassword = self::legacyPasswordBytes($ownerPassword);
        $userPad = substr($userPassword . self::PAD, 0, 32);
        $ownerPad = substr($ownerPassword . self::PAD, 0, 32);

        // Algorithm 3: /O
        $h = md5($ownerPad, true);
        for ($i = 0; $i < 50; $i++) {
            $h = md5($h, true);
        }
        $ownerKey = substr($h, 0, 16);
        $o = self::rc4($ownerKey, $userPad);
        for ($i = 1; $i <= 19; $i++) {
            $o = self::rc4(self::xorKey($ownerKey, $i), $o);
        }
        $this->o = $o;

        // Algorithm 2: file encryption key
        $material = $userPad . $o . pack('V', $this->p & 0xFFFFFFFF) . $idBytes;
        if ($this->revision >= 4 && !$this->encryptMetadata) {
            $material .= "\xFF\xFF\xFF\xFF";
        }
        $h = md5($material, true);
        for ($i = 0; $i < 50; $i++) {
            $h = md5(substr($h, 0, 16), true);
        }
        $this->fileKey = substr($h, 0, 16);

        // Algorithm 5: /U
        $u = self::rc4($this->fileKey, md5(self::PAD . $idBytes, true));
        for ($i = 1; $i <= 19; $i++) {
            $u = self::rc4(self::xorKey($this->fileKey, $i), $u);
        }
        $this->u = $u . random_bytes(16);
    }

    private function initAes256(string $userPassword, string $ownerPassword): void
    {
        $userPassword = substr($userPassword, 0, 127);
        $ownerPassword = substr($ownerPassword, 0, 127);
        $this->fileKey = random_bytes(32);
        $zeroIv = str_repeat("\0", 16);

        $uValSalt = random_bytes(8);
        $uKeySalt = random_bytes(8);
        $this->u = self::hash2B($userPassword, $uValSalt, '') . $uValSalt . $uKeySalt;
        $this->ue = $this->rawCbc($this->fileKey, self::hash2B($userPassword, $uKeySalt, ''), $zeroIv);

        $oValSalt = random_bytes(8);
        $oKeySalt = random_bytes(8);
        $this->o = self::hash2B($ownerPassword, $oValSalt, $this->u) . $oValSalt . $oKeySalt;
        $this->oe = $this->rawCbc($this->fileKey, self::hash2B($ownerPassword, $oKeySalt, $this->u), $zeroIv);

        $perms = pack('V', $this->p & 0xFFFFFFFF) . "\xFF\xFF\xFF\xFF" . ($this->encryptMetadata ? 'T' : 'F') . 'adb' . random_bytes(4);
        $this->perms = (string) openssl_encrypt($perms, 'aes-256-ecb', $this->fileKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    }

    /** ISO 32000-2 algorithm 2.B (revision 6 hash). */
    private static function hash2B(string $password, string $salt, string $udata): string
    {
        $k = hash('sha256', $password . $salt . $udata, true);
        $round = 0;
        do {
            $k1 = str_repeat($password . $k . $udata, 64);
            $e = (string) openssl_encrypt($k1, 'aes-128-cbc', substr($k, 0, 16), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($k, 16, 16));
            $sum = 0;
            for ($i = 0; $i < 16; $i++) {
                $sum += ord($e[$i]);
            }
            $k = hash(['sha256', 'sha384', 'sha512'][$sum % 3], $e, true);
            $round++;
        } while ($round < 64 || ord($e[strlen($e) - 1]) > $round - 32);
        return substr($k, 0, 32);
    }

    private function rawCbc(string $data, string $key, string $iv): string
    {
        return (string) openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    }

    /** AES-CBC with random IV prepended and PKCS#7 padding. */
    private function aesCbc(string $data, string $key): string
    {
        $iv = random_bytes(16);
        $cipher = strlen($key) === 32 ? 'aes-256-cbc' : 'aes-128-cbc';
        return $iv . (string) openssl_encrypt($data, $cipher, $key, OPENSSL_RAW_DATA, $iv);
    }

    private static function xorKey(string $key, int $n): string
    {
        return $key ^ str_repeat(chr($n), strlen($key));
    }

    /** Plain RC4 (OpenSSL 3 ships it only in the legacy provider, so it is implemented here). */
    private static function rc4(string $key, string $data): string
    {
        $len = strlen($data);
        if ($len === 0) {
            return '';
        }
        $s = range(0, 255);
        $kl = strlen($key);
        $j = 0;
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $kl])) & 255;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }
        static $chr = null;
        $chr ??= array_map('chr', range(0, 255));
        $i = 0;
        $j = 0;
        $out = '';
        for ($off = 0; $off < $len; $off += 32768) {
            $chunk = substr($data, $off, 32768);
            $cl = strlen($chunk);
            $stream = [];
            for ($n = 0; $n < $cl; $n++) {
                $i = ($i + 1) & 255;
                $si = $s[$i];
                $j = ($j + $si) & 255;
                $sj = $s[$j];
                $s[$i] = $sj;
                $s[$j] = $si;
                $stream[] = $chr[$s[($si + $sj) & 255]];
            }
            $out .= $chunk ^ implode('', $stream);
        }
        return $out;
    }
}
