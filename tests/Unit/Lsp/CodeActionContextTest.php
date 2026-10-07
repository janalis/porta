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

    /**
     * A server answers with every action at the range: one that names the
     * problems it fixes is a fix for those alone. phpactor offers its method
     * docblock's "Add missing @param tags" under an undefined variable.
     */
    public function testAFixForAnotherProblemIsNotThisProblemsFix(): void
    {
        $about = CodeActionContext::lspDiagnostic(16, ['severity' => DiagnosticSeverity::Error, 'message' => 'Undefined variable "$missing"', 'col' => 24, 'endCol' => 32]);
        $elsewhere = [
            'title' => 'Add missing @param tags',
            'kind' => 'quickfix.add_missing_params',
            'diagnostics' => [['message' => 'Missing @param', 'range' => ['start' => ['line' => 6, 'character' => 35]]]],
        ];
        $sameWordsOtherLine = [
            'title' => 'Other line',
            'kind' => 'quickfix',
            'diagnostics' => [['message' => 'Undefined variable "$missing"', 'range' => ['start' => ['line' => 3, 'character' => 0]]]],
        ];
        $ours = ['title' => 'Define $missing', 'kind' => 'quickfix', 'diagnostics' => [['message' => 'Undefined variable "$missing"', 'range' => ['start' => ['line' => 16, 'character' => 24]]]]];
        $unnamed = ['title' => 'Unnamed', 'kind' => 'quickfix', 'diagnostics' => []];

        $this->assertNull(CodeActionContext::firstQuickFix([$elsewhere, $sameWordsOtherLine], $about), 'no fix for THIS problem');
        $this->assertSame($ours, CodeActionContext::firstQuickFix([$elsewhere, $ours], $about));
        $this->assertSame($unnamed, CodeActionContext::firstQuickFix([$elsewhere, $unnamed], $about), 'one that names none: older servers');
        $this->assertSame($elsewhere, CodeActionContext::firstQuickFix([$elsewhere]), 'not asked about a problem: any fix');
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
