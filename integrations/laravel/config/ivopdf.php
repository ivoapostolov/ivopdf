<?php

declare(strict_types=1);

/*
 * IvoPDF configuration (publish with: php artisan vendor:publish --tag=ivopdf-config).
 * Lengths such as margins are in PDF points (1 pt = 1/72 in).
 */
return [
    // Paper: A0-A6, B4, B5, Letter, Legal, Tabloid, Executive, Folio or [widthPt, heightPt].
    'paper' => 'A4',
    'orientation' => 'portrait', // portrait | landscape

    'default_font' => 'DejaVu Sans', // bundled DejaVu fonts cover Latin, Cyrillic and Greek
    'default_font_size' => 12,

    // null = default margins (36 pt). Or ['top' => 36, 'right' => 36, 'bottom' => 36, 'left' => 36].
    'margins' => null,

    // Relative image / CSS / font paths in your views resolve against this folder.
    'base_path' => public_path(),

    // Local folders the renderer may read from (nothing outside is ever readable).
    'allowed_paths' => [public_path(), resource_path()],

    // Rewrite asset('...') URLs of this application to local files under base_path
    // (avoids HTTP self-requests and works without enabling remote loading).
    'rewrite_asset_urls' => true,

    // Allow http(s) resources (SSRF-protected). Keep disabled unless needed.
    'remote_enabled' => false,

    'compress' => true,
    'subset_fonts' => true,
    'bookmarks' => true,
    'form_fields' => true,
    'lang' => '', // PDF language, e.g. 'bg'; empty = from <html lang>
    'creator' => 'IvoPDF',

    // PDF/A: null (off), '2b' or '3b'.
    'pdfa' => null,

    // Extra families registered at render time:
    // 'Inter' => ['regular' => resource_path('fonts/Inter.ttf'), 'bold' => resource_path('fonts/Inter-Bold.ttf')]
    'fonts' => [],

    // Registered families tried for glyphs missing from the requested font (e.g. 'Noto Sans CJK').
    'fallback_fonts' => [],

    // Raw IvoPdf\Options keys (dompdf/mPDF style names also accepted). Applied last, wins over the keys above.
    'options' => [],
];
