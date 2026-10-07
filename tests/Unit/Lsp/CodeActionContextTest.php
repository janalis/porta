<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DiagnosticSeverity;
use Porta\Lsp\CodeActionContext;

final class CodeActionContextTest extends TestCase
{
    /** What Alt+Shift+Enter applies: the server's pick, else the first runnable quick fix. */
    public function testTheFirstFixIsThePreferredOneOrTheFirstRunnableQuickFix(): void
    {
        $refactor = ['title' => 'Extract method', 'kind' => 'refactor.extract'];
        $disabled = ['title' => 'Cannot', 'kind' => 'quickfix', 'disabled' => ['reason' => 'no']];
        $import = ['title' => 'Import class', 'kind' => 'quickfix.import_class'];
        $plain = ['title' => 'Kindless'];
        $preferred = ['title' => 'Add use', 'kind' => 'quickfix', 'isPreferred' => true];

        $this->assertSame($import, CodeActionContext::firstQuickFix([$refactor, $disabled, $import, $plain]), 'not a refactoring, not a disabled fix');
        $this->assertSame($plain, CodeActionContext::firstQuickFix([$refactor, $plain]), 'an action without a kind counts as a fix');
        $this->assertSame($preferred, CodeActionContext::firstQuickFix([$import, $preferred]), "the server's own pick first");
        $this->assertNull(
            CodeActionContext::firstQuickFix([$refactor, ['title' => 'Sort', 'kind' => 'source.organizeImports'], ['title' => 'q', 'kind' => 'quickfixer']]),
            'nothing that only looks like a fix',
        );
    }

    public function testADiagnosticGoesBackAsTheServerSentIt(): void
    {
        $this->assertSame(
            [
                'range' => ['start' => ['line' => 3, 'character' => 2], 'end' => ['line' => 3, 'character' => 3]],
                'severity' => 2,
                'message' => 'm',
                'code' => 'E1',
                'data' => ['rule' => 'x'],
            ],
            CodeActionContext::lspDiagnostic(3, ['severity' => DiagnosticSeverity::Warning, 'message' => 'm', 'col' => 2, 'endCol' => \PHP_INT_MAX, 'code' => 'E1', 'data' => ['rule' => 'x']]),
            'a range past its line goes back one unit wide; what the server gave rides along',
        );
    }
}
