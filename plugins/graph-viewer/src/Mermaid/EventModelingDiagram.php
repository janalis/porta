<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Source-order frames and opaque data examples remain independent from lane placement. */
final class EventModelingDiagram
{
    /** @var list<array{name: string, modelEntityType: string, entityIdentifier: string, sourceFrames: list<string>, dataReference: ?string, dataType: ?string, dataInlineValue: ?string, reset: bool, statement: MermaidStatement}> */
    public array $frames = [];
    /** @var list<array{name: string, statement: MermaidStatement}> */
    public array $modelEntities = [];
    /** @var list<array{name: string, dataType: ?string, dataBlockValue: string, statement: MermaidStatement}> */
    public array $dataEntities = [];
    /** @var list<array{sourceFrame: string, dataType: ?string, dataBlockValue: string, statement: MermaidStatement}> */
    public array $noteEntities = [];
    /** @var list<array{sourceFrame: string, givenStatements: list<array{entityIdentifier: string, role: string}>, whenStatements: list<array{entityIdentifier: string, role: string}>, thenStatements: list<array{entityIdentifier: string, role: string}>, statement: MermaidStatement}> */
    public array $gwtEntities = [];
    /** @var array<string, MermaidStatement> */
    public array $metadata = [];
    /** @var array<array-key, true> */
    public array $frameIds = [];
    public int $origin = 0;
}
