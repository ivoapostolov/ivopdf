<?php

declare(strict_types=1);

namespace IvoPdf\Pdf\Import;

/**
 * A PDF dictionary (key without slash => value).
 */
class PdfDict
{
    /** @param array<string, mixed> $items */
    public function __construct(public array $items = [])
    {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function get(string $key): mixed
    {
        return $this->items[$key] ?? null;
    }

    /** Name value of a key (e.g. /Type), or null. */
    public function name(string $key): ?string
    {
        $v = $this->items[$key] ?? null;
        return $v instanceof PdfName ? $v->name : null;
    }
}
