<?php

declare(strict_types=1);

namespace IvoPdf\Pdf;

/**
 * Registry of transparency-group Form XObjects: the painted content of an element with `opacity` is captured
 * as a group so that overlapping children blend as one unit (true CSS group opacity).
 */
final class TransparencyGroups
{
    /** @var array<string, string> resource name => content stream */
    private array $groups = [];

    /**
     * Registers a content stream and returns its XObject resource name (e.g. 'Fm1').
     */
    public function register(string $content): string
    {
        $name = 'Fm' . (count($this->groups) + 1);
        $this->groups[$name] = $content;
        return $name;
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }

    /** @return list<string> the registered content streams */
    public function contents(): array
    {
        return array_values($this->groups);
    }

    /**
     * Reserves one object id per group.
     *
     * @param callable(): int $alloc
     * @return array<string, int> resource name => object id
     */
    public function reserve(callable $alloc): array
    {
        $ids = [];
        foreach ($this->groups as $name => $_content) {
            $ids[$name] = $alloc();
        }
        return $ids;
    }

    /**
     * Writes the Form XObject bodies once the shared resources object id is known.
     *
     * @param array<string, int> $ids
     * @param callable(int, string): void $set
     */
    public function fill(array $ids, int $resourcesObjId, callable $set, bool $compress): void
    {
        foreach ($ids as $name => $objId) {
            $data = ColorOps::rewriteRgb($this->groups[$name]);
            $filter = '';
            if ($compress && $data !== '') {
                $data = (string) gzcompress($data, 6);
                $filter = ' /Filter /FlateDecode';
            }
            $set($objId, sprintf(
                "<< /Type /XObject /Subtype /Form /FormType 1 /BBox [-10000 -10000 30000 30000] /Group << /S /Transparency /I true /CS /DeviceRGB >> /Resources %d 0 R /Length %d%s >>\nstream\n%s\nendstream",
                $resourcesObjId,
                strlen($data),
                $filter,
                $data
            ));
        }
    }
}
