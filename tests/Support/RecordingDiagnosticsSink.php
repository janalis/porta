<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\DiagnosticsSink;

/** Records every published diagnostics batch. */
final class RecordingDiagnosticsSink implements DiagnosticsSink
{
    /** @var list<array{uri: string, byLine: array<int, list<array{severity: DiagnosticSeverity, message: string, col: int, endCol: int, code?: int|string, source?: string, tags?: list<int>}>>, owner: string}> */
    public array $published = [];

    #[\Override]
    public function applyDiagnostics(string $uri, array $byLine, string $owner): void
    {
        $this->published[] = ['uri' => $uri, 'byLine' => $byLine, 'owner' => $owner];
    }
}
