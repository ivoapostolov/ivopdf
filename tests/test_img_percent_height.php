<?php

declare(strict_types=1);

/**
 * Percentage heights of images: `height: 100%` (also min- / max-height) resolves against the containing block's
 * definite height, so `object-fit` has a box to fit into; against an indefinite height it is auto (as in Edge).
 */

require_once __DIR__ . '/../autoload.php';

use IvoPdf\IvoPdf;
use IvoPdf\Layout\LayoutBox;

$passed = 0;
$failed = 0;
$phpIssues = [];
set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$phpIssues): bool {
    $phpIssues[] = "{$msg} ({$file}:{$line})";
    return true;
});

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** A tiny RGB PNG (no GD needed) of the given size, as a data URI. */
function png(int $w, int $h): string
{
    $chunk = static fn(string $t, string $d): string => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    $raw = str_repeat("\x00" . str_repeat("\x1e\x78\xc8", $w), $h);
    $data = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
        . $chunk('IDAT', (string) gzcompress($raw)) . $chunk('IEND', '');
    return 'data:image/png;base64,' . base64_encode($data);
}

/** @return array{0: float, 1: float, 2: float, 3: float}|null x, y, width, height of the img box */
function imgBox(string $body): ?array
{
    $p = new IvoPdf(['paperSize' => [300, 200], 'margins' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0]]);
    $p->loadHtml('<html><body style="margin:0">' . $body . '</body></html>');
    $p->render();
    $find = static function (LayoutBox $b) use (&$find): ?LayoutBox {
        if ($b->tagName === 'img') {
            return $b;
        }
        foreach ($b->children as $c) {
            if (($r = $find($c)) !== null) {
                return $r;
            }
        }
        return null;
    };
    $img = $find($p->getLayoutTree());
    return $img === null ? null : [$img->x, $img->y, $img->width, $img->height];
}

function near(?array $box, array $want): bool
{
    if ($box === null) {
        return false;
    }
    foreach ($want as $i => $v) {
        if ($v !== null && abs($box[$i] - $v) > 0.2) {
            return false;
        }
    }
    return true;
}

$sq = png(20, 20);
$big = png(200, 200); // natural 150pt, larger than the max-height cap
$frame = 'position:absolute; left:20px; top:20px; width:288px; height:122px; overflow:hidden;';
$cases = [
    // [label, html, [x, y, w, h] expected (pt), values from Edge]
    ['width/height 100% + contain in a flex frame', "<div style=\"{$frame} display:flex\"><img src=\"{$sq}\" style=\"width:100%; height:100%; object-fit:contain\"></div>", [15.0, 15.0, 216.0, 91.5]],
    ['width/height 100% + contain in a block frame', "<div style=\"{$frame}\"><img src=\"{$sq}\" style=\"width:100%; height:100%; object-fit:contain\"></div>", [15.0, 15.0, 216.0, 91.5]],
    ['only height: 100% keeps the aspect ratio', "<div style=\"{$frame}\"><img src=\"{$sq}\" style=\"height:100%\"></div>", [15.0, 15.0, 91.5, 91.5]],
    ['static parent with a fixed height', "<div style=\"width:288px; height:122px\"><img src=\"{$sq}\" style=\"width:100%; height:100%; object-fit:cover\"></div>", [0.0, 0.0, 216.0, 91.5]],
    ['parent without a definite height: height % is auto', "<div style=\"width:288px\"><img src=\"{$sq}\" style=\"width:100%; height:100%\"></div>", [0.0, 0.0, 216.0, 216.0]],
    ['max-height: 50% of a definite height', "<div style=\"{$frame}\"><img src=\"{$big}\" style=\"max-height:50%\"></div>", [15.0, 15.0, 45.75, 45.75]],
    ['explicit px sizes (unchanged)', "<div style=\"{$frame}\"><img src=\"{$sq}\" style=\"width:288px; height:122px; object-fit:contain\"></div>", [15.0, 15.0, 216.0, 91.5]],
];
foreach ($cases as [$label, $html, $want]) {
    $box = imgBox($html);
    check($label, near($box, $want), $box === null ? 'no img box' : vsprintf('x=%.2f y=%.2f w=%.2f h=%.2f', $box));
}

echo "\n";
check('no PHP warnings / notices', $phpIssues === [], implode(' | ', array_slice($phpIssues, 0, 3)));
echo "=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
