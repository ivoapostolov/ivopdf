<?php

declare(strict_types=1);

namespace IvoPdf\Layout;

use DOMElement;

/**
 * Parsed HTML form control (input / textarea / select / button): the data a form box needs for layout,
 * static drawing and the AcroForm field it becomes in the PDF.
 */
final class FormControl
{
    public const TEXT = 'text';
    public const PASSWORD = 'password';
    public const TEXTAREA = 'textarea';
    public const CHECKBOX = 'checkbox';
    public const RADIO = 'radio';
    public const SELECT = 'select';
    public const SUBMIT = 'submit';
    public const RESET = 'reset';
    public const BUTTON = 'button';

    /** One of the kind constants above. */
    public string $kind = self::TEXT;
    /** Original `type` attribute (lower-case): email, number, date ... */
    public string $inputType = 'text';
    public string $name = '';
    public string $value = '';
    public string $placeholder = '';
    public string $title = '';
    public string $caption = '';
    public bool $checked = false;
    public bool $required = false;
    public bool $readonly = false;
    public bool $disabled = false;
    public bool $multiple = false;
    public int $maxLength = 0;
    public int $size = 0;
    public int $rows = 2;
    public int $cols = 20;

    /** @var list<array{value: string, label: string, selected: bool}> */
    public array $options = [];

    /** Identity of the owning <form> ('' when outside any form); radio groups and names are scoped by it. */
    public string $formKey = '';
    public string $formAction = '';
    public string $formMethod = 'get';

    public function isButton(): bool
    {
        return in_array($this->kind, [self::SUBMIT, self::RESET, self::BUTTON], true);
    }

    public function isCheckable(): bool
    {
        return $this->kind === self::CHECKBOX || $this->kind === self::RADIO;
    }

    public function isListBox(): bool
    {
        return $this->kind === self::SELECT && ($this->multiple || $this->size > 1);
    }

    public function isMultiline(): bool
    {
        return $this->kind === self::TEXTAREA;
    }

    /**
     * The text shown inside the control (value, selected option label or button caption).
     */
    public function displayText(): string
    {
        switch ($this->kind) {
            case self::PASSWORD:
                return str_repeat("\u{2022}", mb_strlen($this->value));
            case self::SELECT:
                $labels = [];
                foreach ($this->options as $o) {
                    if ($o['selected']) {
                        $labels[] = $o['label'];
                    }
                }
                return implode(', ', $labels);
            case self::SUBMIT:
            case self::RESET:
            case self::BUTTON:
                return $this->caption;
            case self::CHECKBOX:
            case self::RADIO:
                return '';
            default:
                return $this->value;
        }
    }

    /**
     * Builds the control description from a form element, or null when the element is not a supported control.
     */
    public static function fromElement(DOMElement $el): ?self
    {
        $tag = strtolower($el->tagName);
        $c = new self();
        $c->name = trim($el->getAttribute('name'));
        $c->title = trim($el->getAttribute('title'));
        $c->placeholder = trim($el->getAttribute('placeholder'));
        $c->required = $el->hasAttribute('required');
        $c->readonly = $el->hasAttribute('readonly');
        $c->disabled = $el->hasAttribute('disabled');
        $c->maxLength = max(0, (int) $el->getAttribute('maxlength'));
        $c->size = max(0, (int) $el->getAttribute('size'));
        $c->value = $el->getAttribute('value');

        $form = self::findForm($el);
        if ($form !== null) {
            $c->formKey = (string) ($form->getNodePath() ?? 'form');
            $c->formAction = trim($form->getAttribute('action'));
            $c->formMethod = strtolower(trim($form->getAttribute('method'))) === 'post' ? 'post' : 'get';
        }
        if ($el->hasAttribute('formaction') && $tag !== 'textarea') {
            $c->formAction = trim($el->getAttribute('formaction'));
        }

        switch ($tag) {
            case 'input':
                $type = strtolower(trim($el->getAttribute('type')));
                $c->inputType = $type === '' ? 'text' : $type;
                switch ($type) {
                    case 'hidden':
                        return null;
                    case 'checkbox':
                    case 'radio':
                        $c->kind = $type;
                        $c->checked = $el->hasAttribute('checked');
                        break;
                    case 'submit':
                    case 'reset':
                    case 'button':
                    case 'image':
                        $c->kind = $type === 'image' ? self::SUBMIT : $type;
                        $c->caption = $c->value !== '' ? $c->value : ($type === 'image' ? $el->getAttribute('alt') : '');
                        if ($c->caption === '' && $type === 'submit') {
                            $c->caption = 'Submit';
                        } elseif ($c->caption === '' && $type === 'reset') {
                            $c->caption = 'Reset';
                        }
                        break;
                    case 'password':
                        $c->kind = self::PASSWORD;
                        break;
                    default:
                        $c->kind = self::TEXT;
                }
                break;
            case 'textarea':
                $c->kind = self::TEXTAREA;
                $text = (string) $el->textContent;
                $text = str_starts_with($text, "\n") ? substr($text, 1) : $text;
                $c->value = str_replace("\r\n", "\n", $text);
                $c->rows = max(1, (int) $el->getAttribute('rows') ?: 2);
                $c->cols = max(1, (int) $el->getAttribute('cols') ?: 20);
                break;
            case 'button':
                $type = strtolower(trim($el->getAttribute('type')));
                $c->kind = $type === 'reset' ? self::RESET : ($type === 'button' ? self::BUTTON : self::SUBMIT);
                $c->caption = self::normalize((string) $el->textContent);
                break;
            case 'select':
                $c->kind = self::SELECT;
                $c->multiple = $el->hasAttribute('multiple');
                $c->value = '';
                self::collectOptions($el, $c);
                if (!$c->multiple && $c->options !== []) {
                    $first = null;
                    foreach ($c->options as $i => $o) {
                        if ($o['selected']) {
                            $first ??= $i;
                            if ($i !== $first) {
                                $c->options[$i]['selected'] = false;
                            }
                        }
                    }
                    if ($first === null) {
                        $c->options[0]['selected'] = true;
                    }
                }
                break;
            default:
                return null;
        }
        return $c;
    }

    private static function collectOptions(DOMElement $parent, self $c): void
    {
        foreach ($parent->childNodes as $n) {
            if (!$n instanceof DOMElement) {
                continue;
            }
            $t = strtolower($n->tagName);
            if ($t === 'optgroup') {
                self::collectOptions($n, $c);
            } elseif ($t === 'option') {
                $text = self::normalize((string) $n->textContent);
                $c->options[] = [
                    'value' => $n->hasAttribute('value') ? $n->getAttribute('value') : $text,
                    'label' => $n->hasAttribute('label') ? $n->getAttribute('label') : $text,
                    'selected' => $n->hasAttribute('selected'),
                ];
            }
        }
    }

    private static function findForm(DOMElement $el): ?DOMElement
    {
        for ($p = $el->parentNode; $p !== null; $p = $p->parentNode) {
            if ($p instanceof DOMElement && strtolower($p->tagName) === 'form') {
                return $p;
            }
        }
        return null;
    }

    private static function normalize(string $s): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $s));
    }
}
