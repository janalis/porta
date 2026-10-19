<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Help\Fence\FenceElement;
use Porta\Help\Fence\FenceResult;
use Porta\Support\AnsiText;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/** Generated links expose semantic image regions and provider controls to the page's existing navigation. */
final class FenceLinks
{
    /** @var array<array-key, int> */
    private array $groups = [];
    /** @var array<array-key, true> */
    private array $mapped = [];
    /** @var array<array-key, int> */
    private array $actions = [];

    public function __construct(
        private readonly ?FenceResult $result,
        private readonly int $offset,
        private readonly int $first,
        private readonly int $last,
        private readonly int $width,
        private readonly StylePen $pen,
        LayoutState $state,
    ) {
        foreach ($result->elements ?? [] as $element) {
            $this->groups[$element->id] = $state->action();
        }
        foreach ($result->actions ?? [] as $action) {
            $this->actions[$action->id] = $state->action();
        }
    }

    /** @param list<PageLine> $rows
     * @return list<PageLine>
     */
    public function image(array $rows): array
    {
        $height = \count($rows);
        foreach ($rows as $index => $row) {
            $links = [];
            foreach (\array_reverse($this->result->elements ?? []) as $element) {
                foreach ($element->spans(($index + 0.5) / $height, \max(1, $row->width - 2)) as [$left, $right]) {
                    $links[] = [$left + 2, $right + 2, $this->target($element), $this->groups[$element->id]];
                }
            }
            $rows[$index] = new PageLine($row->text, $row->width, $row->origin, [...$links, ...$row->links], $row->cells, $row->anchors);
        }

        return $rows;
    }

    /** @param list<PageLine> $rows
     * @return list<PageLine>
     */
    public function terminal(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $links = $row->links;
            $plain = AnsiUtils::stripAnsiCodes($row->text);
            foreach ($this->result->elements ?? [] as $element) {
                $label = AnsiText::oneLine(\explode("\n", AnsiUtils::stripAnsiCodes($element->label))[0]);
                $position = '' === $label ? false : \strpos($plain, $label);
                if ($row->origin !== $this->origin($element) || false === $position) {
                    continue;
                }
                $left = AnsiUtils::visibleWidth(\substr($plain, offset: 0, length: $position));
                $links[] = [$left, $left + AnsiUtils::visibleWidth($label), $this->target($element), $this->groups[$element->id]];
                $this->mapped[$element->id] = true;
            }
            $rows[$index] = new PageLine($row->text, $row->width, $row->origin, $links, $row->cells, $row->anchors);
        }

        return $rows;
    }

    /** @return list<PageLine> */
    public function legend(): array
    {
        $lines = [];
        foreach ($this->result->elements ?? [] as $element) {
            if (\array_key_exists($element->id, $this->mapped)) {
                continue;
            }
            $plain = AnsiText::oneLine(AnsiUtils::stripAnsiCodes($element->label));
            foreach (AnsiText::wrap('' === $plain ? '""' : $plain, $this->width) as $text) {
                $width = AnsiUtils::visibleWidth($text);
                $lines[] = new PageLine(
                    $this->pen->paint($text, new InlineStyle(InlineStyle::LINK)),
                    $width,
                    $this->origin($element),
                    [[0, $width, $this->target($element), $this->groups[$element->id]]],
                )->after(PageLine::spaces(2));
            }
        }

        return $lines;
    }

    /** @return list<PageLine> */
    public function controls(): array
    {
        $lines = [];
        $row = PageLine::blank($this->first);
        foreach ($this->result->actions ?? [] as $action) {
            $label = AnsiText::truncate($action->label, $this->width);
            $width = AnsiUtils::visibleWidth($label);
            if ($row->width > 0 && ($row->width + 3 + $width) > $this->width) {
                $lines[] = $row->after(PageLine::spaces(2));
                $row = PageLine::blank($this->first);
            }
            if ($row->width > 0) {
                $row = $row->then(new PageLine(' · ', 3, $this->first));
            }
            $row = $row->then(new PageLine(
                $this->pen->paint($label, new InlineStyle(InlineStyle::LINK)),
                $width,
                $this->first,
                [[0, $width, 'fence-action:'.$this->offset.':'.$action->id, $this->actions[$action->id]]],
            ));
        }
        if ($row->width > 0) {
            $lines[] = $row->after(PageLine::spaces(2));
        }

        return $lines;
    }

    private function origin(FenceElement $element): int
    {
        return $this->first + \min($this->last, $element->origin);
    }

    private function target(FenceElement $element): string
    {
        return 'fence-element:'.$this->offset.':'.$element->id;
    }
}
