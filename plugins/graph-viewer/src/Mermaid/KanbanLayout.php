<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\Theme\JsObject;
use Porta\GraphViewer\Mermaid\Theme\Khroma;
use Porta\GraphViewer\Mermaid\Theme\ThemeProgram;

/** Equal-width columns with aligned headers, independently stacked cards and inert metadata. */
final readonly class KanbanLayout
{
    public function __construct(
        private ?MermaidPalette $palette = null,
        private ?MermaidOptions $options = null,
    ) {}

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(KanbanDiagram $source): \Generator
    {
        $palette = $this->palette ?? MermaidPalette::defaults('kanban');
        $tickets = ($this->options ?? MermaidOptions::defaults())->text('kanban', 'ticketBaseUrl');
        $measure = $this->measure($source);
        yield from $measure;
        [$labels, $header] = $measure->getReturn();
        $runs = [];
        $nodes = [];
        $elements = [];
        $legend = [];
        $shapes = [];
        $cursor = 2.0;
        $bottom = 2.0;
        $columns = 0;
        foreach ($source->nodes as $id => $item) {
            $group = $item['group'];
            $height = $group ? $header : $labels[$id]['height'];
            $left = 2.0 + ($item['column'] * 50);
            if ($group) {
                $cursor = 2.0;
                ++$columns;
            }
            $x = $left + 23;
            $y = $cursor + ($height / 2);
            $nodes[$id] = new GraphNode(
                $id,
                'kanban-'.$id,
                $labels[$id]['plain'],
                $x,
                $y,
                $group ? 46.0 : 42.0,
                $height,
                // Pinned kanban styles: titles are plain column labels, cards the background color.
                $group ? 'none' : '@nodeBorder',
                $group ? 'none' : '@background',
                dashed: false,
                drawLabel: $labels[$id]['draw'],
                textColor: '@textColor',
            );
            // Pinned cards link `ticketBaseUrl` with `#TICKET#` replaced by their ticket.
            $ticket = $item['metadata']['ticket'] ?? '';
            $shapes[$id] = $group ? 'rounded' : KanbanCards::shape($item['metadata']['priority'] ?? '', $ticket, $item['metadata']['assigned'] ?? '', $tickets);
            $elements[$id] = $item['origin'];
            $runs['node:'.$id] = $labels[$id]['runs'];
            $link = '' === $ticket || '' === $tickets ? '' : ' → '.\str_replace('#TICKET#', $ticket, $tickets);
            $legend[] = ['text' => $labels[$id]['plain'].$link, 'origin' => $item['origin']];
            $cursor += $height + 3;
            $bottom = \max($bottom, $cursor);
            yield null;
        }
        for ($column = 0; $column < $columns; ++$column) {
            $id = \count($nodes);
            $section = self::section($palette, $column);
            $nodes[$id] = new GraphNode($id, 'column-'.$column, '', 25.0 + ($column * 50), 2 + (($bottom - 2) / 2), 48.0, $bottom - 2, $section, $section, dashed: false, background: true);
            $shapes[$id] = 'rounded';
            yield null;
        }
        $origins = MermaidOrigins::rows($bottom + 4, $source->origin);
        foreach ($source->nodes as $id => $item) {
            $node = $nodes[$id];
            for ($row = (int) \floor(($node->y - ($node->h / 2)) / 2); $row <= (int) \ceil(($node->y + ($node->h / 2)) / 2); ++$row) {
                $origins[$row] = $item['origin'];
                yield null;
            }
        }

        return new MermaidScene(new GraphLayout(\max(52.0, ($columns * 50.0) + 2), $bottom + 2, $nodes, edges: [], clusters: []), $origins, $legend, $elements, $shapes, runs: $runs);
    }

    /** Column N is section N + 1: `cScale` N + 2, lightened by 10 (darkened in dark mode). */
    private static function section(MermaidPalette $palette, int $column): string
    {
        $variables = $palette->appearance->variables;
        // @mago-expect analysis:mixed-assignment -- Theme values are JavaScript values, narrowed below.
        $scale = JsObject::get($variables, 'cScale'.($column + 2));
        $amount = ThemeProgram::truthy(JsObject::get($variables, 'darkMode')) ? -10 : 10;
        try {
            $color = \is_string($scale) ? Khroma::lighten($scale, $amount) : null;
        } catch (\InvalidArgumentException) {
            $color = null;
        }

        return MermaidColor::css($color ?? '#f2f4f8') ?? '#f2f4f8';
    }

    /** @return \Generator<int, null, mixed, array{list<array{plain: string, draw: string, runs: list<list<array{string, GraphFont}>>, height: float}>, float}> */
    private function measure(KanbanDiagram $source): \Generator
    {
        $labels = [];
        $header = 8.0;
        foreach ($source->nodes as $item) {
            $plain = $item['label'];
            foreach (['assigned', 'ticket', 'priority', 'icon'] as $key) {
                if ('' !== ($item['metadata'][$key] ?? '')) {
                    $plain .= "\n".$key.': '.$item['metadata'][$key];
                }
                yield null;
            }
            // Pictures draw the title alone, wrapped between words at 36 columns; the ticket and
            // assignee share one row beneath it.
            $markdown = $item['markdown']->wrap(wrappingWidth: 270.0);
            $draw = $markdown->plain();
            $row = '' !== ($item['metadata']['ticket'] ?? '') || '' !== ($item['metadata']['assigned'] ?? '');
            $height = MermaidText::box($draw)[1] + ($row ? 2.0 : 0.0);
            $labels[] = ['plain' => $plain, 'draw' => $draw, 'runs' => $markdown->lines, 'height' => $height];
            if ($item['group']) {
                $header = \max($header, $height);
            }
            yield null;
        }

        return [$labels, $header];
    }
}
