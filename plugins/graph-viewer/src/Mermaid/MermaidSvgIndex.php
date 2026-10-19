<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\ImageViewer\SvgGeometry;
use Porta\Support\AnsiText;

/** Bounded, inert inspection of the same SVG that is rasterized for display. */
final class MermaidSvgIndex
{
    private const array HIDDEN = ['defs', 'clipPath', 'mask', 'symbol', 'marker', 'pattern', 'linearGradient', 'radialGradient', 'filter', 'style', 'script', 'title', 'desc', 'metadata'];

    private function __construct(
        private readonly string $rootId,
    ) {}

    /** @var array<string, list<array{float, float, float, float}>> */
    public array $ids = [];
    /** @var array<string, list<array{float, float, float, float}>> */
    public array $labels = [];

    /** @return \Generator<int, null, mixed, self|null> */
    public static function read(string $svg, float $width, float $height): \Generator
    {
        $root = $width > 0 && $height > 0 && \is_finite($width) && \is_finite($height) ? self::document($svg) : null;
        if (null === $root) {
            return null;
        }
        $index = new self($root->getAttribute('id'));
        yield from $index->walk($root, SvgGeometry::viewBoxMatrix($root, $width, $height), 0);

        return $index;
    }

    private static function document(string $svg): ?\DOMElement
    {
        if ('' === \trim($svg) || \strlen($svg) > 4_194_304 || 1 === \preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $svg)) {
            return null;
        }
        $document = new \DOMDocument();
        $previous = \libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg, \LIBXML_NONET);
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);
        $root = $loaded ? $document->documentElement : null;

        return null === $root || 'svg' !== $root->localName || $root->getElementsByTagName('*')->length > 32_768 ? null : $root;
    }

    /** @param array{float, float, float, float, float, float} $matrix
     * @return \Generator<int, null, mixed, array{float, float, float, float}|null>
     */
    private function walk(\DOMElement $element, array $matrix, int $depth): \Generator
    {
        if (
            $depth > 64
            || \in_array($element->localName, self::HIDDEN, strict: true)
            || 'none' === $element->getAttribute('display')
            || 1 === \preg_match('/(?:^|;)\s*display\s*:\s*none\b/i', $element->getAttribute('style'))
        ) {
            return null;
        }
        $matrix = SvgGeometry::multiply($matrix, SvgGeometry::transform($element->getAttribute('transform')));
        $local = SvgGeometry::box($element);
        $box = null === $local ? null : SvgGeometry::mapBox($matrix, $local);
        foreach ($element->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $work = $this->walk($child, $matrix, $depth + 1);
            yield from $work;
            $box = self::merge($box, $work->getReturn());
        }
        if (null !== $box && self::candidate($element) && \array_all($box, static fn (float $value): bool => \is_finite($value)) && $box[2] > $box[0] && $box[3] > $box[1]) {
            $this->remember($element, $box);
        }

        yield null;

        return $box;
    }

    private static function candidate(\DOMElement $element): bool
    {
        return $element->hasAttribute('data-id') || 1 === \preg_match('/(?:^|\s)(?:node|actor|entity|state|usecase|messageText|noteText|loopText|labelText)(?:\s|$)/', $element->getAttribute('class'));
    }

    /** @param array{float, float, float, float} $box */
    private function remember(\DOMElement $element, array $box): void
    {
        $id = $element->getAttribute('id');
        if ('' !== $this->rootId && \str_starts_with($id, $this->rootId.'-')) {
            $id = \substr($id, offset: \strlen($this->rootId) + 1);
        }
        $keys = [$element->getAttribute('data-id'), $element->getAttribute('data-usecase-id'), $id];
        $match = [];
        if (1 === \preg_match('/^(?:flowchart|classId|state|entity)-(.+)-\d+$/', $id, $match)) {
            $keys[] = $match[1];
        }
        foreach (\array_unique($keys) as $key) {
            if ('' === $key) {
                continue;
            }
            $this->ids[$key][] = $box;
        }
        $label = AnsiText::oneLine($element->textContent);
        if ('' !== $label) {
            $this->labels[$label][] = $box;
        }
    }

    /** @param array{float, float, float, float}|null $a
     * @param array{float, float, float, float}|null $b
     * @return array{float, float, float, float}|null
     */
    private static function merge(?array $a, ?array $b): ?array
    {
        if (null === $a || null === $b) {
            return $a ?? $b;
        }

        return [\min($a[0], $b[0]), \min($a[1], $b[1]), \max($a[2], $b[2]), \max($a[3], $b[3])];
    }
}
