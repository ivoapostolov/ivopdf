<?php

declare(strict_types=1);

namespace IvoPdf\Compat;

/** mPDF `\Mpdf\HTMLParserMode` constants (aliased as that class by mpdf_aliases.php). */
final class HTMLParserMode
{
    public const DEFAULT_MODE = 0;
    public const HEADER_CSS = 1;
    public const HTML_BODY = 2;
    public const HTML_PARSE_NO_WRITE = 3;
    public const HTML_HEADER_BUFFER = 3;
}
