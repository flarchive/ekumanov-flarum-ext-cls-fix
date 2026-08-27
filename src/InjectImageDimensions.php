<?php

namespace Ekumanov\ClsFix;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Psr\Http\Message\ServerRequestInterface;
use s9e\TextFormatter\Renderer;

/**
 * Render-time hook: for any <IMG> without explicit width/height, inject cached
 * dimensions from the image_dimensions table. The downstream XSL template then
 * emits a placeholder with a correct aspect-ratio (zero CLS on page load).
 *
 * If no cached dims exist, the XSL template marks the wrapper with
 * data-cls-needs-dims="1" so the client JS reports them after the image loads.
 */
class InjectImageDimensions
{
    public function __construct(private readonly ImageDimensions $dimensions) {}

    public function __invoke(Renderer $renderer, mixed $context, string $xml, ?ServerRequestInterface $request = null): string
    {
        // Fast path: plain-text posts are serialized as <t>...</t> and never
        // contain IMG tags. Avoid the XML parse entirely in that case.
        if (! str_contains($xml, '<IMG')) {
            return $xml;
        }

        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $loaded || $dom->documentElement === null) {
            return $xml;
        }

        $xpath = new DOMXPath($dom);
        $candidates = $xpath->query('//IMG[not(@width) or not(@height) or @width="0" or @height="0"]');
        if ($candidates === false || $candidates->length === 0) {
            return $xml;
        }

        $urls = [];
        /** @var DOMElement $img */
        foreach ($candidates as $img) {
            $src = $img->getAttribute('src');
            if ($src !== '') {
                $urls[] = $src;
            }
        }

        if (empty($urls)) {
            return $xml;
        }

        $dims = $this->dimensions->getMany($urls);
        if (empty($dims)) {
            return $xml;
        }

        $changed = false;
        foreach ($candidates as $img) {
            $src = $img->getAttribute('src');
            if (! isset($dims[$src])) {
                continue;
            }
            $img->setAttribute('width', (string) $dims[$src]['width']);
            $img->setAttribute('height', (string) $dims[$src]['height']);
            $changed = true;
        }

        if (! $changed) {
            return $xml;
        }

        // saveXML($node) omits the XML declaration.
        $out = $dom->saveXML($dom->documentElement);
        return $out === false ? $xml : $out;
    }
}
