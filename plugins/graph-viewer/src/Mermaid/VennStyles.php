<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Exact target lists merge in source order, while each property retains its own diagnostic origin. */
final class VennStyles
{
    /** @var array<string, array<string, string>> */
    private array $values = [];
    /** @var array<string, array<string, MermaidStatement>> */
    private array $origins = [];

    public function __construct(VennDiagram $source)
    {
        foreach ($source->styles as $entry) {
            $key = VennDiagram::key($entry['targets']);
            foreach ($entry['styles'] as $property => $value) {
                $this->values[$key][$property] = $value;
                $this->origins[$key][$property] = $entry['statement'];
            }
        }
    }

    /** @param non-empty-list<string> $ids */
    public function resolve(array $ids, VennPaint $paint): VennPaint
    {
        $key = VennDiagram::key(VennDiagram::identifiers($ids));
        foreach ($this->values[$key] ?? [] as $property => $value) {
            $statement = $this->origins[$key][$property];
            $paint = $paint->property(\strtolower($property), $value, $statement);
        }

        return $paint;
    }
}
