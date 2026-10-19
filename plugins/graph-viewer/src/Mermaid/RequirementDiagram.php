<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Named declarations and deferred relationships/styles retain their original source locations. */
final class RequirementDiagram
{
    public const array TYPES = ['requirement', 'functionalrequirement', 'interfacerequirement', 'performancerequirement', 'physicalrequirement', 'designconstraint', 'element'];

    /** @var array<array-key, array{type: string, fields: array<string, string>, origin: int, offset: int}> */
    public array $nodes = [];
    /** @var list<array{from: string, to: string, kind: string, origin: int}> */
    public array $relations = [];
    /** @var array<string, array{label: string, origin: int}> */
    public array $metadata = [];
    public string $direction = 'TB';
    public int $origin = 0;
    public readonly MermaidNodeStyles $styles;

    public function __construct()
    {
        $this->styles = new MermaidNodeStyles('requirement');
    }
}
