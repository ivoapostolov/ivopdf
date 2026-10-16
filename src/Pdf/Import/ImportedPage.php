<?php

declare(strict_types=1);

namespace IvoPdf\Pdf\Import;

/**
 * One source page converted to a Form XObject whose /Matrix maps the (rotated) visible page box onto the unit
 * square, so it is drawn with "W 0 0 H x y cm /Name Do" like an image.
 */
final class ImportedPage
{
    public function __construct(
        public readonly ImportedPool $pool,
        public readonly int $rootId,
        /** Visible page width in points (after /Rotate) */
        public readonly float $width,
        /** Visible page height in points (after /Rotate) */
        public readonly float $height
    ) {
    }

    /** 1-based page number inside the source document */
    public int $sourcePage = 0;

    /**
     * Annotations of the source page converted by AnnotationImporter (geometry in visible-page space, objects as
     * pool placeholders). Link / markup / widget records, see AnnotationImporter for the layout.
     *
     * @var list<array<string, mixed>>
     */
    public array $annots = [];

    /** @var array<string, string> AcroForm /DR font resources of the source (name => serialized value with pool placeholders) */
    public array $acroFonts = [];
    /** Default appearance string of the source AcroForm, decoded */
    public ?string $acroDa = null;
    public bool $needAppearances = false;
}
