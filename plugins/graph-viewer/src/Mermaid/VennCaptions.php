<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;

/** Text clearance scales circle geometry while preserving area ratios and readable glyph sizes. */
final readonly class VennCaptions
{
    /** @param list<VennCaptionGroup> $groups */
    public function __construct(
        public array $groups,
        public float $scale,
    ) {}

    /** @param array<array-key, GraphNode> $circles
     * @return \Generator<int, null, mixed, self>
     */
    public static function prepare(VennDiagram $source, array $circles, VennStyles $styles, VennTheme $theme): \Generator
    {
        $order = \array_flip(\array_map(\strval(...), \array_keys($circles)));
        $groups = [];
        foreach ($source->subsets as $subset) {
            $ids = $subset['sets'];
            $key = VennDiagram::key($ids);
            $groups[$key] ??= ['ids' => $ids, 'entries' => []];
            $visible = 1 === \count($ids) || null !== $subset['label'];
            $groups[$key]['entries']['caption'] = [
                'name' => \implode(' & ', $ids),
                'label' => $subset['label'] ?? \implode(' & ', $ids),
                // A set's own label darkens its circle color; overlaps take the set text color.
                'paint' => $styles->resolve($ids, new VennPaint(color: 1 === \count($ids) ? $theme->label($order[$ids[0]] ?? 0) : $theme->text())),
                'statement' => $subset['statement'],
                'visible' => $visible,
            ];
            yield null;
        }
        foreach ($source->texts as $text) {
            $ids = $text['sets'];
            if (\array_diff($ids, \array_keys($circles))) {
                continue;
            }
            $key = VennDiagram::key($ids);
            $groups[$key] ??= ['ids' => $ids, 'entries' => []];
            $groups[$key]['entries'][] = [
                'name' => $text['id'],
                'label' => $text['label'] ?? $text['id'],
                'paint' => $styles->resolve([$text['id']], new VennPaint(color: $theme->text())),
                'statement' => $text['statement'],
                'visible' => true,
            ];
            yield null;
        }
        $result = [];
        $scale = 1.0;
        foreach ($groups as $group) {
            $center = VennRegion::center($circles, $group['ids']);
            yield from $center;
            $point = $center->getReturn();
            $items = [];
            $width = 4.0;
            $height = 0.0;
            foreach ($group['entries'] as $entry) {
                $wrap = MermaidWrap::text(MermaidText::decode($entry['label']), width: 24);
                yield from $wrap;
                $label = $wrap->getReturn();
                [$itemWidth, $itemHeight] = MermaidText::box($label);
                $itemHeight = \max(2.0, $itemHeight - 2);
                if (!$entry['visible']) {
                    $itemWidth = 4.0;
                    $itemHeight = 2.0;
                }
                $items[] = new VennCaption($entry['name'], $label, $itemWidth, $itemHeight, $entry['paint'], $entry['statement'], $entry['visible']);
                if ($entry['visible']) {
                    $width = \max($width, $itemWidth);
                    $height += $itemHeight;
                }
                yield null;
            }
            $height = \max(2.0, $height);
            if ($point['margin'] > 0) {
                $scale = \max($scale, \hypot($width / 2, $height / 2) / $point['margin']);
            }
            $result[] = new VennCaptionGroup($point, $items, $width, $height);
        }

        return new self($result, \min(12.0, $scale * 1.15));
    }
}
