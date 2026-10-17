<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

use Closure;
use IvoPdf\Layout\FormControl;

/**
 * Serialises collected FormWidget objects into AcroForm field / widget annotation objects with appearance streams.
 *
 * Usage (from PdfDocument::compile()): build() allocates its own objects through the supplied callbacks and returns
 * the top-level field object ids (for /AcroForm /Fields), the widget object ids per page (for page /Annots),
 * and the raw /DR font dictionary entries to merge into the single /AcroForm.
 */
final class FormFieldWriter
{
    private const FF_READONLY = 1;
    private const FF_REQUIRED = 2;
    private const FF_MULTILINE = 4096;
    private const FF_PASSWORD = 8192;
    private const FF_NO_TOGGLE_OFF = 16384;
    private const FF_RADIO = 32768;
    private const FF_PUSHBUTTON = 65536;
    private const FF_COMBO = 131072;
    private const FF_MULTISELECT = 2097152;

    /** @var array<int, string> */
    private array $objects = [];

    /**
     * @param list<FormWidget> $widgets
     * @param list<int> $pageObjIds page index => page object id
     * @param array<string, int> $fontRefs embedded font resource name => font object id
     * @param Closure(): int $alloc allocates a new object id
     * @param Closure(string, string): string $makeStream builds a stream object body from ($data, $extraDictEntries)
     * @return array{
     *   fields: list<int>,
     *   annots: array<int, list<int>>,
     *   objects: array<int, string>,
     *   fonts: array<string, int>,
     *   needAppearances: bool
     * }
     */
    public function build(array $widgets, array $pageObjIds, array $fontRefs, Closure $alloc, Closure $makeStream): array
    {
        $this->objects = [];
        $fields = [];
        $annots = [];
        $fonts = [];
        $usedNames = [];
        $needAppearances = true;

        $helv = $alloc();
        $this->objects[$helv] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $zadb = $alloc();
        $this->objects[$zadb] = '<< /Type /Font /Subtype /Type1 /BaseFont /ZapfDingbats >>';
        $fonts['Helv'] = $helv;
        $fonts['ZaDb'] = $zadb;

        // Group radio buttons by (form, name)
        $radioGroups = [];
        foreach ($widgets as $i => $wd) {
            if ($wd->control->kind === FormControl::RADIO) {
                $radioGroups[$wd->control->formKey . "\0" . $wd->control->name][] = $i;
            }
        }
        $doneGroups = [];

        foreach ($widgets as $i => $wd) {
            $c = $wd->control;
            if (!isset($pageObjIds[$wd->page])) {
                continue;
            }
            if ($wd->needsEmbeddedFont && $wd->apFontId !== '' && isset($fontRefs[$wd->apFontId])) {
                $fonts[$wd->apFontId] = $fontRefs[$wd->apFontId];
                $needAppearances = false; // regenerated appearances could not encode the subset font reliably
            }

            if ($c->kind === FormControl::RADIO) {
                $gk = $c->formKey . "\0" . $c->name;
                if (isset($doneGroups[$gk])) {
                    continue;
                }
                $doneGroups[$gk] = true;
                $parentId = $this->buildRadioGroup(
                    $widgets,
                    $radioGroups[$gk],
                    $pageObjIds,
                    $alloc,
                    $makeStream,
                    $fontRefs,
                    $this->uniqueName($c->name, 'radio', $usedNames)
                );
                $fields[] = $parentId['parent'];
                foreach ($parentId['kids'] as $page => $ids) {
                    foreach ($ids as $id) {
                        $annots[$page][] = $id;
                    }
                }
                continue;
            }

            $id = $alloc();
            $name = $this->uniqueName($c->name, $c->kind, $usedNames);
            $this->objects[$id] = $this->mergedWidget($wd, $name, $pageObjIds[$wd->page], $alloc, $makeStream, $fontRefs);
            $fields[] = $id;
            $annots[$wd->page][] = $id;
        }

        return [
            'fields' => $fields,
            'annots' => $annots,
            'objects' => $this->objects,
            'fonts' => $fonts,
            'needAppearances' => $needAppearances,
        ];
    }

    /**
     * @param list<FormWidget> $widgets
     * @param list<int> $indices
     * @param list<int> $pageObjIds
     * @param array<string, int> $fontRefs
     * @return array{parent: int, kids: array<int, list<int>>}
     */
    private function buildRadioGroup(array $widgets, array $indices, array $pageObjIds, Closure $alloc, Closure $makeStream, array $fontRefs, string $name): array
    {
        $parentId = $alloc();
        $kidIds = [];
        $byPage = [];
        $checkedState = null;
        $flags = self::FF_RADIO | self::FF_NO_TOGGLE_OFF;
        $usedStates = [];
        $first = $widgets[$indices[0]]->control;

        foreach ($indices as $n => $idx) {
            $wd = $widgets[$idx];
            $c = $wd->control;
            if (!isset($pageObjIds[$wd->page])) {
                continue;
            }
            $state = $c->value !== '' ? $c->value : 'Choice' . ($n + 1);
            $base = $state;
            for ($k = 2; isset($usedStates[$state]); $k++) {
                $state = $base . '_' . $k;
            }
            $usedStates[$state] = true;
            $stateName = $this->pdfName($state);
            if ($c->checked) {
                $checkedState = $stateName;
            }
            if ($c->readonly || $c->disabled) {
                $flags |= self::FF_READONLY;
            }
            if ($c->required) {
                $flags |= self::FF_REQUIRED;
            }

            $onId = $alloc();
            $this->objects[$onId] = $makeStream($wd->normalAp, $this->xobjectDict($wd, $fontRefs));
            $offId = $alloc();
            $this->objects[$offId] = $makeStream($wd->offAp, $this->xobjectDict($wd, $fontRefs));

            $kid = $alloc();
            $this->objects[$kid] = sprintf(
                '<< /Type /Annot /Subtype /Widget /Rect [%s] /F 4 /P %d 0 R /Parent %d 0 R /AS /%s /AP << /N << /%s %d 0 R /Off %d 0 R >> >> /MK << /CA (l) >> /DA (/ZaDb 0 Tf 0 g) >>',
                $this->rect($wd),
                $pageObjIds[$wd->page],
                $parentId,
                $c->checked ? $stateName : 'Off',
                $stateName,
                $onId,
                $offId
            );
            $kidIds[] = $kid;
            $byPage[$wd->page][] = $kid;
        }

        $tu = $first->title !== '' ? ' /TU ' . $this->pdfText($first->title) : '';
        $this->objects[$parentId] = sprintf(
            '<< /FT /Btn /Ff %d /T %s%s /V /%s /Kids [%s] /DA (/ZaDb 0 Tf 0 g) >>',
            $flags,
            $this->pdfText($name),
            $tu,
            $checkedState ?? 'Off',
            implode(' ', array_map(static fn(int $id): string => "{$id} 0 R", $kidIds))
        );
        return ['parent' => $parentId, 'kids' => $byPage];
    }

    /**
     * Field + widget in one dictionary (text, check box, choice, push button).
     *
     * @param array<string, int> $fontRefs
     */
    private function mergedWidget(FormWidget $wd, string $name, int $pageObjId, Closure $alloc, Closure $makeStream, array $fontRefs): string
    {
        $c = $wd->control;
        $flags = 0;
        if ($c->readonly || $c->disabled) {
            $flags |= self::FF_READONLY;
        }
        if ($c->required) {
            $flags |= self::FF_REQUIRED;
        }

        $tip = $c->title !== '' ? $c->title : $c->placeholder;
        $parts = [
            '/Type /Annot', '/Subtype /Widget',
            '/Rect [' . $this->rect($wd) . ']',
            '/F 4',
            '/P ' . $pageObjId . ' 0 R',
            '/T ' . $this->pdfText($name),
        ];
        if ($tip !== '') {
            $parts[] = '/TU ' . $this->pdfText($tip);
        }

        $fontName = $wd->needsEmbeddedFont && $wd->apFontId !== '' && isset($fontRefs[$wd->apFontId]) ? $wd->apFontId : 'Helv';
        $da = sprintf('/%s %.2F Tf %s', $fontName, $wd->fontSize, $wd->colorOps);

        switch ($c->kind) {
            case FormControl::CHECKBOX:
                $state = $c->value !== '' ? $this->pdfName($c->value) : 'Yes';
                if ($state === 'Off') {
                    $state = 'Yes';
                }
                $onId = $alloc();
                $this->objects[$onId] = $makeStream($wd->normalAp, $this->xobjectDict($wd, $fontRefs));
                $offId = $alloc();
                $this->objects[$offId] = $makeStream($wd->offAp, $this->xobjectDict($wd, $fontRefs));
                $parts[] = '/FT /Btn';
                $parts[] = '/Ff ' . $flags;
                $parts[] = '/V /' . ($c->checked ? $state : 'Off');
                $parts[] = '/AS /' . ($c->checked ? $state : 'Off');
                $parts[] = "/AP << /N << /{$state} {$onId} 0 R /Off {$offId} 0 R >> >>";
                $parts[] = '/MK << /CA (4) >>';
                $parts[] = '/DA (/ZaDb 0 Tf 0 g)';
                break;

            case FormControl::SUBMIT:
            case FormControl::RESET:
            case FormControl::BUTTON:
                $apId = $alloc();
                $this->objects[$apId] = $makeStream($wd->normalAp, $this->xobjectDict($wd, $fontRefs));
                $parts[] = '/FT /Btn';
                $parts[] = '/Ff ' . ($flags | self::FF_PUSHBUTTON);
                $parts[] = "/AP << /N {$apId} 0 R >>";
                $parts[] = '/MK << /CA ' . $this->pdfText($c->caption) . ' >>';
                $parts[] = '/DA (' . $da . ')';
                $parts[] = '/H /P';
                $action = $this->buttonAction($c);
                if ($action !== '') {
                    $parts[] = '/A ' . $action;
                }
                break;

            case FormControl::SELECT:
                $apId = $alloc();
                $this->objects[$apId] = $makeStream($wd->normalAp, $this->xobjectDict($wd, $fontRefs));
                $opt = [];
                $sel = [];
                $idx = [];
                foreach ($c->options as $i => $o) {
                    $opt[] = $o['value'] === $o['label']
                        ? $this->pdfText($o['value'])
                        : '[' . $this->pdfText($o['value']) . ' ' . $this->pdfText($o['label']) . ']';
                    if ($o['selected']) {
                        $sel[] = $this->pdfText($o['value']);
                        $idx[] = $i;
                    }
                }
                if (!$c->isListBox()) {
                    $flags |= self::FF_COMBO;
                }
                if ($c->multiple) {
                    $flags |= self::FF_MULTISELECT;
                }
                $parts[] = '/FT /Ch';
                $parts[] = '/Ff ' . $flags;
                $parts[] = '/Opt [' . implode(' ', $opt) . ']';
                if ($sel !== []) {
                    $parts[] = '/V ' . (count($sel) === 1 && !$c->multiple ? $sel[0] : '[' . implode(' ', $sel) . ']');
                    $parts[] = '/I [' . implode(' ', $idx) . ']';
                }
                $parts[] = "/AP << /N {$apId} 0 R >>";
                $parts[] = '/DA (' . $da . ')';
                $parts[] = '/Q ' . $wd->align;
                break;

            default: // text, password, textarea
                $apId = $alloc();
                $this->objects[$apId] = $makeStream($wd->normalAp, $this->xobjectDict($wd, $fontRefs));
                if ($c->kind === FormControl::TEXTAREA) {
                    $flags |= self::FF_MULTILINE;
                } elseif ($c->kind === FormControl::PASSWORD) {
                    $flags |= self::FF_PASSWORD;
                }
                $parts[] = '/FT /Tx';
                $parts[] = '/Ff ' . $flags;
                $parts[] = '/V ' . $this->pdfText(str_replace("\n", "\r", $c->value));
                if ($c->maxLength > 0) {
                    $parts[] = '/MaxLen ' . $c->maxLength;
                }
                $parts[] = "/AP << /N {$apId} 0 R >>";
                $parts[] = '/DA (' . $da . ')';
                $parts[] = '/Q ' . $wd->align;
                if ($wd->background !== '') {
                    $parts[] = '/MK << /BG [' . $wd->background . '] >>';
                }
        }

        return '<< ' . implode(' ', $parts) . ' >>';
    }

    private function buttonAction(FormControl $c): string
    {
        if ($c->kind === FormControl::RESET) {
            return '<< /Type /Action /S /ResetForm >>';
        }
        if ($c->kind === FormControl::SUBMIT && preg_match('#^https?://#i', $c->formAction) === 1) {
            $flags = 4 | ($c->formMethod === 'get' ? 8 : 0); // ExportFormat (HTML) [+ GetMethod]
            return sprintf(
                '<< /Type /Action /S /SubmitForm /F << /FS /URL /F %s >> /Flags %d >>',
                $this->pdfString($c->formAction),
                $flags
            );
        }
        return '';
    }

    /**
     * Form XObject dictionary entries (without /Length) for a widget appearance.
     *
     * @param array<string, int> $fontRefs
     */
    private function xobjectDict(FormWidget $wd, array $fontRefs): string
    {
        $res = '';
        if ($wd->apFontId !== '' && isset($fontRefs[$wd->apFontId])) {
            $res = sprintf('/Font << /%s %d 0 R >>', $wd->apFontId, $fontRefs[$wd->apFontId]);
        }
        return sprintf('/Type /XObject /Subtype /Form /BBox [0 0 %.2F %.2F] /Resources << %s >>', $wd->width, $wd->height, $res);
    }

    private function rect(FormWidget $wd): string
    {
        return sprintf('%.2F %.2F %.2F %.2F', $wd->rect[0], $wd->rect[1], $wd->rect[2], $wd->rect[3]);
    }

    /**
     * Returns a document-unique, dot-free field name (duplicates get _2, _3 ...).
     *
     * @param array<string, true> $used
     */
    private function uniqueName(string $name, string $kind, array &$used): string
    {
        $base = trim(str_replace('.', '_', $name));
        if ($base === '') {
            $base = match ($kind) {
                FormControl::CHECKBOX => 'checkbox',
                FormControl::RADIO => 'radio',
                FormControl::SELECT => 'select',
                FormControl::TEXTAREA => 'textarea',
                FormControl::SUBMIT, FormControl::RESET, FormControl::BUTTON => 'button',
                default => 'text',
            };
        }
        $candidate = $base;
        for ($k = 2; isset($used[$candidate]); $k++) {
            $candidate = $base . '_' . $k;
        }
        $used[$candidate] = true;
        return $candidate;
    }

    /** PDF name token (without the leading slash). */
    private function pdfName(string $s): string
    {
        return (string) preg_replace_callback('/[^A-Za-z0-9_.\-]/', static fn(array $m): string => sprintf('#%02X', ord($m[0])), $s);
    }

    /** Literal string in PDFDocEncoding/ASCII. */
    private function pdfString(string $s): string
    {
        return '(' . strtr($s, ['\\' => '\\\\', '(' => '\(', ')' => '\)', "\r" => '\r', "\n" => '\n']) . ')';
    }

    /** PDF text string: literal when plain ASCII, otherwise UTF-16BE with BOM as hex. */
    private function pdfText(string $s): string
    {
        if (preg_match('/^[\x20-\x7E\r\n\t]*$/', $s) === 1) {
            return $this->pdfString($s);
        }
        $utf16 = (string) @mb_convert_encoding($s, 'UTF-16BE', 'UTF-8');
        return '<FEFF' . strtoupper(bin2hex($utf16)) . '>';
    }
}
