<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BreadcrumbScanner;
use Porta\Lsp\LspMapper;

/**
 * A `documentSymbol` tree as breadcrumb scopes.
 *
 * The shape is deliberately the one {@see BreadcrumbScanner::scan()} produces,
 * so `select()` reads it unchanged — which is why the last test here runs the
 * real selector over the mapped output rather than trusting the shape by eye.
 */
final class SymbolScopesTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function symbol(string $name, int $kind, int $from, int $to, array $children = []): array
    {
        return [
            'name' => $name,
            'kind' => $kind,
            'range' => ['start' => ['line' => $from], 'end' => ['line' => $to]],
            // Deliberately different from `range`: the trail needs the span
            // that CONTAINS the caret, not the one that names the symbol.
            'selectionRange' => ['start' => ['line' => $from], 'end' => ['line' => $from]],
            'children' => $children,
        ];
    }

    /**
     * A malformed symbol is SKIPPED, not treated as the end of the list.
     *
     * The loop drops anything that is not an array — servers do send the odd
     * null or scalar in a symbol list — and turning that `continue` into a
     * `break` left every test here green, because each passes a list of
     * well-formed symbols. One bad entry early in a real response would have
     * silently truncated the breadcrumb trail from that point on, which reads
     * exactly like a file that has no more scopes.
     */
    public function testABadSymbolDoesNotTruncateTheOnesAfterIt(): void
    {
        $scopes = LspMapper::symbolScopes([null, 'not a symbol', self::symbol('Svc', 5, 4, 40)]);

        self::assertSame([['label' => 'Svc', 'startLine' => 5, 'endLine' => 41, 'kind' => 'type']], $scopes);
    }

    public function testTheFULLRangeIsKeptNotTheNameSSelectionRange(): void
    {
        // A trail asks which scopes contain the caret; a selectionRange is one
        // line long, so every crumb would vanish the moment you moved.
        $scopes = LspMapper::symbolScopes([self::symbol('Svc', 5, 4, 40)]);

        self::assertSame([['label' => 'Svc', 'startLine' => 5, 'endLine' => 41, 'kind' => 'type']], $scopes);
    }

    public function testLinesAreConvertedFromLspSZeroBaseToTheScopesOneBase(): void
    {
        $scopes = LspMapper::symbolScopes([self::symbol('f', 12, 0, 2)]);

        self::assertSame(1, $scopes[0]['startLine']);
        self::assertSame(3, $scopes[0]['endLine']);
    }

    public function testAParentAlwaysPrecedesItsChildren(): void
    {
        // The outermost-first order `select()` relies on comes from the walk
        // being depth-first, not from a sort.
        $scopes = LspMapper::symbolScopes([
            self::symbol('App', 3, 0, 50, [
                self::symbol('Svc', 5, 4, 40, [
                    self::symbol('run', 6, 10, 20),
                ]),
            ]),
        ]);

        self::assertSame(['App', 'Svc', 'run'], array_column($scopes, 'label'));
        self::assertSame(['namespace', 'type', 'function'], array_column($scopes, 'kind'));
    }

    public function testMembersThatAreNotContainersAreNotCrumbs(): void
    {
        // A property or a constant is a member, not something a caret is
        // INSIDE — listing them makes every line of a class body its own crumb.
        $scopes = LspMapper::symbolScopes([
            self::symbol('Svc', 5, 0, 20, [
                self::symbol('field', 7, 2, 2),
                self::symbol('SOME_CONST', 14, 3, 3),
                self::symbol('local', 13, 4, 4),
                self::symbol('run', 6, 5, 9),
            ]),
        ]);

        self::assertSame(['Svc', 'run'], array_column($scopes, 'label'));
    }

    public function testAnUncontainedSymbolStillHasItsChildrenWalked(): void
    {
        // Dropping the parent must not drop the subtree with it.
        $scopes = LspMapper::symbolScopes([
            self::symbol('field', 7, 0, 9, [self::symbol('run', 6, 2, 5)]),
        ]);

        self::assertSame(['run'], array_column($scopes, 'label'));
    }

    public function testASymbolWithNoUsableRangeIsSkipped(): void
    {
        // It cannot say whether it contains the caret, so it cannot be a crumb.
        $scopes = LspMapper::symbolScopes([
            ['name' => 'NoRange', 'kind' => 5],
            ['name' => '', 'kind' => 5, 'range' => ['start' => ['line' => 0], 'end' => ['line' => 1]]],
            'not an object',
        ]);

        self::assertSame([], $scopes);
    }

    public function testAONELINESymbolIsStillAScope(): void
    {
        // A one-line function starts and ends on the same line, so the range
        // check has to accept an empty span, not merely a growing one.
        $scopes = LspMapper::symbolScopes([self::symbol('f', 12, 7, 7)]);

        self::assertSame([['label' => 'f', 'startLine' => 8, 'endLine' => 8, 'kind' => 'function']], $scopes);
    }

    public function testARangeMissingItsSTARTIsSkipped(): void
    {
        // Half a range is not a range: without a start there is no line to
        // begin the scope at, and defaulting one would put the crumb at the top
        // of the file.
        $scopes = LspMapper::symbolScopes([[
            'name' => 'Svc',
            'kind' => 5,
            'range' => ['end' => ['line' => 9]],
        ]]);

        self::assertSame([], $scopes);
    }

    public function testARangeMissingItsENDIsSkippedEvenAtTheFirstLine(): void
    {
        // Line 0 is the case a default of zero would silently accept.
        $scopes = LspMapper::symbolScopes([[
            'name' => 'Svc',
            'kind' => 5,
            'range' => ['start' => ['line' => 0]],
        ]]);

        self::assertSame([], $scopes);
    }

    public function testANonStringNameIsNotAScope(): void
    {
        $scopes = LspMapper::symbolScopes([[
            'name' => ['unexpected'],
            'kind' => 5,
            'range' => ['start' => ['line' => 0], 'end' => ['line' => 3]],
        ]]);

        self::assertSame([], $scopes);
    }

    public function testTheOLDERSymbolInformationShapeIsReadToo(): void
    {
        $scopes = LspMapper::symbolScopes([[
            'name' => 'Legacy',
            'kind' => 5,
            'location' => ['range' => ['start' => ['line' => 2], 'end' => ['line' => 8]]],
        ]]);

        self::assertSame([['label' => 'Legacy', 'startLine' => 3, 'endLine' => 9, 'kind' => 'type']], $scopes);
    }

    public function testTheREALSelectorReadsTheMappedScopes(): void
    {
        // The whole point of matching the scanner's shape. Caret on 0-based
        // line 12 is inside App, Svc and run.
        $scopes = LspMapper::symbolScopes([
            self::symbol('App', 3, 0, 50, [
                self::symbol('Svc', 5, 4, 40, [
                    self::symbol('run', 6, 10, 20),
                ]),
            ]),
        ]);

        self::assertSame(
            [['label' => 'App', 'line' => 0], ['label' => 'Svc', 'line' => 4], ['label' => 'run', 'line' => 10]],
            BreadcrumbScanner::select($scopes, 12),
        );
    }
}
