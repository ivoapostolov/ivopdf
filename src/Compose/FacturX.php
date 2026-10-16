<?php

declare(strict_types=1);

namespace IvoPdf\Compose;

/**
 * Factur-X / ZUGFeRD helpers: profile validation and the XMP extension schema (fx:*) that PDF/A-3 requires.
 */
final class FacturX
{
    public const NAMESPACE_URI = 'urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#';

    /** @var array<string, string> lower-case alias => official fx:ConformanceLevel value */
    private const PROFILES = [
        'minimum' => 'MINIMUM',
        'basic wl' => 'BASIC WL',
        'basicwl' => 'BASIC WL',
        'basic' => 'BASIC',
        'en 16931' => 'EN 16931',
        'en16931' => 'EN 16931',
        'comfort' => 'EN 16931',
        'extended' => 'EXTENDED',
        'xrechnung' => 'XRECHNUNG',
    ];

    public static function normalizeProfile(string $profile): string
    {
        $key = strtolower(trim($profile));
        if (!isset(self::PROFILES[$key])) {
            throw new \InvalidArgumentException("Unknown Factur-X profile '{$profile}' (use MINIMUM, BASIC WL, BASIC, EN 16931, EXTENDED or XRECHNUNG)");
        }
        return self::PROFILES[$key];
    }

    /**
     * Returns the <rdf:Description> blocks for the PDF/A XMP packet: the fx: properties and the
     * pdfaExtension schema declaration required by PDF/A-3 for the custom namespace.
     */
    public static function xmpExtension(string $profile = 'EN 16931', string $fileName = 'factur-x.xml', string $version = '1.0'): string
    {
        $level = self::normalizeProfile($profile);
        $x = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $prop = static fn(string $name, string $desc): string =>
            '<rdf:li rdf:parseType="Resource"><pdfaProperty:name>' . $name . '</pdfaProperty:name>'
            . '<pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category>'
            . '<pdfaProperty:description>' . $desc . '</pdfaProperty:description></rdf:li>';

        $schema = '<rdf:Description rdf:about="" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/"'
            . ' xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#" xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#">'
            . '<pdfaExtension:schemas><rdf:Bag><rdf:li rdf:parseType="Resource">'
            . '<pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema>'
            . '<pdfaSchema:namespaceURI>' . self::NAMESPACE_URI . '</pdfaSchema:namespaceURI>'
            . '<pdfaSchema:prefix>fx</pdfaSchema:prefix>'
            . '<pdfaSchema:property><rdf:Seq>'
            . $prop('DocumentFileName', 'name of the embedded XML invoice file')
            . $prop('DocumentType', 'INVOICE')
            . $prop('Version', 'The actual version of the Factur-X XML schema')
            . $prop('ConformanceLevel', 'The conformance level of the embedded Factur-X data')
            . '</rdf:Seq></pdfaSchema:property></rdf:li></rdf:Bag></pdfaExtension:schemas></rdf:Description>';

        $values = '<rdf:Description rdf:about="" xmlns:fx="' . self::NAMESPACE_URI . '">'
            . '<fx:DocumentFileName>' . $x($fileName) . '</fx:DocumentFileName>'
            . '<fx:DocumentType>INVOICE</fx:DocumentType>'
            . '<fx:Version>' . $x($version) . '</fx:Version>'
            . '<fx:ConformanceLevel>' . $x($level) . '</fx:ConformanceLevel>'
            . '</rdf:Description>';

        return $schema . $values;
    }
}
