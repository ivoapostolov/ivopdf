<?php

declare(strict_types=1);

namespace IvoPdf\Compat;

/** mPDF `\Mpdf\Output\Destination` constants (aliased as that class by mpdf_aliases.php). */
final class Destination
{
    public const INLINE = 'I';
    public const DOWNLOAD = 'D';
    public const FILE = 'F';
    public const STRING_RETURN = 'S';
}
