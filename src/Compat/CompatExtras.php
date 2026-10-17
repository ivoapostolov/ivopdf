<?php

declare(strict_types=1);

namespace IvoPdf\Compat;

use IvoPdf\Layout\LayoutBox;
use IvoPdf\Layout\LayoutEngine;
use IvoPdf\Layout\Paginator;
use IvoPdf\Paged\Footnotes;
use IvoPdf\Paged\PageLayoutPlan;

/**
 * Small hooks used by the dompdf / mPDF compatibility layer (mixed into IvoPdf):
 *  - a per-page overlay callback (dompdf Canvas::page_text() / page_script() drawing)
 *  - a one-time warning for `<script type="text/php">` blocks, which are never executed
 */
trait CompatExtras
{
    /** @var (\Closure(int, int, float, float): string)|null (pageNumber, pageCount, pageWidth, pageHeight) => overlay HTML */
    private ?\Closure $pageOverlay = null;

    /**
     * Registers a callback that returns extra HTML (absolutely positioned elements inside a page-sized box) for every page.
     *
     * @param (\Closure(int, int, float, float): string)|null $callback
     */
    public function setPageOverlay(?\Closure $callback): self
    {
        $this->pageOverlay = $callback;
        $this->renderedPdf = null;
        return $this;
    }

    /** CSS GCPM footnotes of the current pass (null when the document has none) */
    private ?Footnotes $footnotes = null;
    /** @var array{call: array<string, string>, marker: array<string, string>, area: array<string, string>, callContent: ?string, markerContent: ?string, perPage: bool} */
    private array $footnoteCss = ['call' => [], 'marker' => [], 'area' => [], 'callContent' => null, 'markerContent' => null, 'perPage' => false];
    /** @var array{push: array<int, bool>, floor: array<int, float>, ratchet: bool, freeze: bool, changed: bool, last: bool, memo: array<string, float>} state shared by the passes of one render() */
    private array $footnoteState = ['push' => [], 'floor' => [], 'ratchet' => false, 'freeze' => false, 'changed' => false, 'last' => false, 'memo' => []];
    /** @var array<int, int> note index => page assumed by this pass (result of the previous one) */
    private array $footnoteMem = [];
    /** @var array<int, int>|null note index => page found by this pass */
    private ?array $footnoteNext = null;

    /** Replaces `float: footnote` elements by calls (called right after the DOM is built). */
    private function footnoteExtract(LayoutBox $root): void
    {
        $this->footnotes = Footnotes::extract($root, $this->footnoteMem, $this->footnoteCss, $this->footnoteState);
        $this->footnoteNext = null;
    }

    /** Lets the page plan reserve room at the bottom of every page that carries notes. */
    private function footnoteAttach(PageLayoutPlan $plan, LayoutEngine $engine): void
    {
        $fn = $this->footnotes;
        if ($fn !== null) {
            $plan->setExtraBottom(static fn(int $pageIndex, float $width, float $pageHeight): float => $fn->reserve($pageIndex, $engine, $width, $pageHeight));
        }
    }

    /** Before pagination: calls whose note must move to the next page (footnote-policy) start a page. */
    private function footnotePrepare(LayoutBox $root): void
    {
        $this->footnotes?->prepare($root);
    }

    /** After pagination: pages of the calls (compared with the assumed ones by IvoPdf::render()). */
    private function footnoteAfterPaginate(LayoutBox $root, Paginator $paginator): void
    {
        $this->footnoteNext = $this->footnotes?->afterPaginate($root, static fn(float $y): int => $paginator->pageOfY($y));
    }

    /** Warns (once per render) about embedded PHP: IvoPDF never executes code found in documents. */
    private function compatScan(string $html): string
    {
        [$html, $this->footnoteCss] = Footnotes::scanCss($html);
        if (stripos($html, 'text/php') !== false && preg_match('~<script\b[^>]*\btype\s*=\s*["\']?text/php~i', $html) === 1) {
            $this->options->addWarning('<script type="text/php"> is not supported and was ignored (IvoPDF never executes PHP code from documents). Use Dompdf\Dompdf::getCanvas()->page_text() / page_script(callable) or setFooterHtml() with {PAGE_NUM} / {PAGE_COUNT} instead.');
        }
        return $html;
    }

    /** @return list<LayoutBox> */
    private function compatOverlayLayers(LayoutEngine $engine, array $geo, int $pageNumber, int $pageCount): array
    {
        $layers = [];
        if ($this->footnotes !== null) {
            $width = max(50.0, (float) $geo['w'] - $geo['m']['left'] - $geo['m']['right']);
            $bottom = (float) $geo['h'] - ((float) $geo['eBottom'] - (float) ($geo['fn'] ?? 0.0));
            $area = $this->footnotes->layer($pageNumber - 1, $engine, (float) $geo['m']['left'], $width, $bottom, (float) ($geo['fn'] ?? 0.0));
            if ($area !== null) {
                $layers[] = $area;
            }
            foreach ($this->footnotes->takeWarnings() as $w) {
                $this->options->addWarning($w);
            }
        }
        if ($this->pageOverlay !== null) {
            $html = ($this->pageOverlay)($pageNumber, $pageCount, (float) $geo['w'], (float) $geo['h']);
            if (trim($html) !== '') {
                $layers[] = $this->layoutHtmlBox($html, $engine, 0.0, 0.0, (float) $geo['w']);
            }
        }
        return $layers;
    }
}
