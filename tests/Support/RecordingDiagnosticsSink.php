<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\DiagnosticsSink;

/** Records every published diagnostics batch. */
final class RecordingDiagnosticsSink implements DiagnosticsSink
{
    /** @var list<array{uri: string, byLine: array<int, list<array{severity: int, message: string, col: int, endCol: int}>>}> */
    public array $published = [];

    public function applyDiagnostics(string $uri, array $byLine): void
    {
        $this->published[] = ['uri' => $uri, 'byLine' => $byLine];
    }
}
