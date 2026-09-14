<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BreadcrumbScanner;

final class BreadcrumbScannerTest extends TestCase
{
    /**
     * @param list<array{label: string, line: int}> $trail
     *
     * @return list<string>
     */
    private function labels(array $trail): array
    {
        return \array_map(static fn (array $c): string => $c['label'], $trail);
    }

    public function testNamespaceClassMethodPath(): void
    {
        $src = "<?php\nnamespace A\\B;\nclass C\n{\n    public function d(): void\n    {\n        \$x = 1;\n    }\n}\n";
        $scopes = BreadcrumbScanner::scan($src);

        // Caret on the `$x` line (0-based line 6) is inside all three scopes.
        $trail = BreadcrumbScanner::select($scopes, 6);
        $this->assertSame(['A\\B', 'C', 'd'], $this->labels($trail));
        // Jump lines are the 0-based declaration lines: namespace@1, class@2, method@4.
        $this->assertSame([1, 2, 4], \array_map(static fn (array $c): int => $c['line'], $trail));
    }

    public function testTopLevelBeforeAnyScopeIsEmpty(): void
    {
        $src = "<?php\n\$x = 1;\nnamespace A;\nclass C {}\n";
        // Line 1 (the `$x` assignment) precedes the namespace → no crumbs.
        $this->assertSame([], BreadcrumbScanner::select(BreadcrumbScanner::scan($src), 1));
    }

    public function testAnonymousFunctionsAndArrowFnsDoNotAddCrumbs(): void
    {
        $src = "<?php\nnamespace N;\nclass C\n{\n    public function m(): void\n    {\n        \$f = function (\$a) { return \$a; };\n        \$g = fn (\$b) => \$b;\n        echo 1;\n    }\n}\n";
        $scopes = BreadcrumbScanner::scan($src);

        // The `echo` line is still just N › C › m — closures/fn did not nest or leak.
        $this->assertSame(['N', 'C', 'm'], $this->labels(BreadcrumbScanner::select($scopes, 8)));
    }

    public function testTraitInterfaceEnumRecognised(): void
    {
        $trait = BreadcrumbScanner::scan("<?php\ntrait T\n{\n    public function a() {}\n}\n");
        $this->assertSame(['T', 'a'], $this->labels(BreadcrumbScanner::select($trait, 3)));

        $iface = BreadcrumbScanner::scan("<?php\ninterface I\n{\n    public function a(): int;\n}\n");
        // An interface method has no body, so the caret on its line is inside the interface only.
        $this->assertSame(['I'], $this->labels(BreadcrumbScanner::select($iface, 3)));

        $enum = BreadcrumbScanner::scan("<?php\nenum E: string\n{\n    case A = 'a';\n}\n");
        $this->assertSame(['E'], $this->labels(BreadcrumbScanner::select($enum, 3)));
    }

    public function testBracedNamespaceForm(): void
    {
        $src = "<?php\nnamespace A {\n    class C {\n        function m() {\n            \$x;\n        }\n    }\n}\n";
        $this->assertSame(['A', 'C', 'm'], $this->labels(BreadcrumbScanner::select(BreadcrumbScanner::scan($src), 4)));
    }

    public function testUnclosedScopeMidEditClosesAtEof(): void
    {
        // Missing the final braces (as while typing) — scopes still cover to EOF.
        $src = "<?php\nnamespace N;\nclass C\n{\n    public function m()\n    {\n        \$x;\n";
        $this->assertSame(['N', 'C', 'm'], $this->labels(BreadcrumbScanner::select(BreadcrumbScanner::scan($src), 6)));
    }

    public function testFooClassAndNewClassDoNotCreateCrumbs(): void
    {
        // `Foo::class` and `new class {}` must not be treated as class declarations.
        $src = "<?php\nnamespace N;\nfunction top(): void\n{\n    \$k = Foo::class;\n    \$o = new class {\n        public \$x = 1;\n    };\n    echo 1;\n}\n";
        $scopes = BreadcrumbScanner::scan($src);
        // The `echo` line is inside N › top only (the anonymous class adds no crumb).
        $this->assertSame(['N', 'top'], $this->labels(BreadcrumbScanner::select($scopes, 8)));
    }

    public function testUseFunctionImportIsNotAScope(): void
    {
        $src = "<?php\nnamespace N;\nuse function strlen;\nclass C\n{\n    public function m() { \$x; }\n}\n";
        // The imported `strlen` must not become a function crumb.
        $this->assertSame(['N', 'C', 'm'], $this->labels(BreadcrumbScanner::select(BreadcrumbScanner::scan($src), 5)));
    }

    public function testScanCarriesKind(): void
    {
        $scopes = BreadcrumbScanner::scan("<?php\nnamespace N;\nclass C\n{\n    public function m() {}\n}\n");
        $kinds = [];
        foreach ($scopes as $s) {
            $kinds[$s['label']] = $s['kind'];
        }
        $this->assertSame('namespace', $kinds['N']);
        $this->assertSame('type', $kinds['C']);
        $this->assertSame('function', $kinds['m']);
    }

    public function testStickyHeadersPinScrolledOffScopesExceptNamespace(): void
    {
        $src = "<?php\nnamespace App;\nclass Service\n{\n    public function handle(): void\n    {\n        \$x = 1;\n    }\n}\n";
        $scopes = BreadcrumbScanner::scan($src);

        // Viewport top at line 6 (inside handle): the class + method headers (lines
        // 2 and 4) have scrolled off; the namespace is excluded.
        $sticky = BreadcrumbScanner::stickyHeaders($scopes, 6, 5);
        $this->assertSame([['label' => 'Service', 'line' => 2], ['label' => 'handle', 'line' => 4]], $sticky);

        // Top at line 0: every header is still visible → nothing pinned.
        $this->assertSame([], BreadcrumbScanner::stickyHeaders($scopes, 0, 5));
        // A header exactly at the top line is not "scrolled off".
        $this->assertSame([], BreadcrumbScanner::stickyHeaders($scopes, 2, 5));
    }

    public function testStickyHeadersRespectTheCap(): void
    {
        $src = "<?php\nnamespace App;\nclass Service\n{\n    public function handle(): void\n    {\n        \$x = 1;\n    }\n}\n";
        $scopes = BreadcrumbScanner::scan($src);

        $this->assertSame([['label' => 'Service', 'line' => 2]], BreadcrumbScanner::stickyHeaders($scopes, 6, 1), 'capped to the outermost');
        $this->assertSame([], BreadcrumbScanner::stickyHeaders($scopes, 6, 0));
    }

    public function testDanglingNamespaceSeparatorMidEditKeepsAccumulatingIntoTheLabel(): void
    {
        // A lone trailing "\" (mid-typing a qualified namespace) never closes
        // via `;`, so the pending namespace label keeps absorbing tokens all
        // the way to the next `{` — including the class keyword and name.
        $src = "<?php\nnamespace A\\\nclass X {}\n";
        $scopes = BreadcrumbScanner::scan($src);

        $this->assertCount(1, $scopes);
        $this->assertSame('A\\X', $scopes[0]['label']);
        $this->assertSame('namespace', $scopes[0]['kind']);
    }

    public function testUnclosedSingleLineScopeEndsOnItsOwnLineNotLineTwo(): void
    {
        // No newline anywhere in the source: maxLine's default must itself be
        // 1, not rely on a token pushing it there.
        $scopes = BreadcrumbScanner::scan('<?php class A{');

        $this->assertSame(['label' => 'A', 'startLine' => 1, 'endLine' => 1, 'kind' => 'type'], $scopes[0]);
    }

    public function testCommentBetweenClassKeywordAndNameDoesNotBreakCapture(): void
    {
        $src = "<?php\nclass /* c */ Foo\n{\n    \$x = 1;\n}\n";
        $scopes = BreadcrumbScanner::scan($src);

        $this->assertSame(['Foo'], $this->labels(BreadcrumbScanner::select($scopes, 3)));
    }

    public function testDoubleColonClassConstantNeverArmsADeclarationAcrossTokens(): void
    {
        // Mid-edit, missing semicolon: `Foo::class` immediately followed by
        // another bare identifier and a brace must not be misread as a decl.
        $src = "<?php\n\$k = Foo::class\nBar {\n}\n";

        $this->assertSame([], BreadcrumbScanner::scan($src));
    }

    public function testTwoBareIdentifiersInARowNeverArmAStrayTypeDeclaration(): void
    {
        // Mid-edit, malformed: an ordinary identifier must never itself arm
        // a pending type declaration for whatever identifier follows it.
        $src = "<?php\nFoo Bar {\n}\n";

        $this->assertSame([], BreadcrumbScanner::scan($src));
    }

    public function testMultipleSemicolonNamespacesEachEndWhereTheNextOneStarts(): void
    {
        $src = "<?php\nnamespace A;\nclass X {}\nnamespace B;\nclass Y {}\n";
        $scopes = BreadcrumbScanner::scan($src);
        $namespaces = \array_values(\array_filter($scopes, static fn (array $s): bool => 'namespace' === $s['kind']));

        $this->assertSame(['label' => 'A', 'startLine' => 2, 'endLine' => 3, 'kind' => 'namespace'], $namespaces[0]);
        $this->assertSame('B', $namespaces[1]['label']);
        $this->assertSame(4, $namespaces[1]['startLine']);
    }

    public function testFullyQualifiedAndDanglingSeparatorNamespaceTokensAreCaptured(): void
    {
        // A lone trailing "\" terminated by a real `;` (unlike the mid-edit
        // dangling case above) must still extend the label via T_NS_SEPARATOR.
        $scopes = BreadcrumbScanner::scan("<?php\nnamespace A\\;\nclass C {}\n");
        $this->assertSame('A\\', $scopes[0]['label']);

        // A malformed but tokenizable leading-backslash (T_NAME_FULLY_QUALIFIED)
        // namespace name must also be captured, not silently dropped.
        $scopes = BreadcrumbScanner::scan("<?php\nnamespace \\A\\B;\nclass C {}\n");
        $this->assertSame('\\A\\B', $scopes[0]['label']);
    }

    public function testNonIdentifierTokenNeverJoinsAPendingNamespaceLabel(): void
    {
        // Mid-edit garbage (a bare number) between namespace fragments must not
        // be absorbed into the label — only string/separator/qualified tokens may.
        $scopes = BreadcrumbScanner::scan("<?php\nnamespace App 42;\nclass C\n{\n}\n");
        $this->assertSame('App', $scopes[0]['label']);
    }

    public function testMultiLineCommentExtendsMaxLineForEofClose(): void
    {
        // The comment spans lines 3-5 (plus a trailing blank line 6): an
        // unclosed scope must close at the comment's real last line, not the
        // line the comment token started on.
        $src = "<?php\nclass C {\n/* line3\nline4\nline5 */\n";
        $scopes = BreadcrumbScanner::scan($src);
        $this->assertSame(6, $scopes[0]['endLine']);
    }

    public function testTiesOnStartLineSortOutermostFirstByDescendingEndLine(): void
    {
        // Namespace and class share the same startLine (braced form, declared
        // on one line); the namespace's wider endLine must sort it first.
        $src = "<?php\nnamespace App { class C {\n}\n\$x = 1;\n}\n";
        $scopes = BreadcrumbScanner::scan($src);
        $this->assertSame(['App', 'C'], \array_column($scopes, 'label'));
    }

    public function testSelectSkipsNonEnclosingSiblingsToFindTheRealMatch(): void
    {
        // a() is a non-enclosing sibling that sorts before b(); it must be
        // skipped, not cause the whole scan of scopes to stop early.
        $src = "<?php\nfunction a() {\n}\nfunction b() {\n    \$x = 1;\n}\n";
        $scopes = BreadcrumbScanner::scan($src);
        $this->assertSame(['b'], $this->labels(BreadcrumbScanner::select($scopes, 4)));
    }

    public function testStickyHeadersLineConversionOffByOneBoundary(): void
    {
        // handle() closes on line 8 (1-based); a 0-based viewport top of 7 is
        // real line 8 (still inside, inclusive), top of 8 is real line 9 (past it).
        $src = "<?php\nnamespace App;\nclass Service\n{\n    public function handle(): void\n    {\n        \$x = 1;\n    }\n}\n";
        $scopes = BreadcrumbScanner::scan($src);
        $this->assertSame(['Service', 'handle'], \array_column(BreadcrumbScanner::stickyHeaders($scopes, 7, 5), 'label'));
        $this->assertSame(['Service'], \array_column(BreadcrumbScanner::stickyHeaders($scopes, 8, 5), 'label'));
    }

    public function testStickyHeadersRejectsNegativeCapWithoutSlicingFromTheEnd(): void
    {
        // A negative $max must floor to zero, not feed array_slice() a negative
        // length (which would count from the end and return leftover headers).
        $src = "<?php\nnamespace App;\nclass Service\n{\n    public function handle(): void\n    {\n        \$x = 1;\n    }\n}\n";
        $scopes = BreadcrumbScanner::scan($src);
        $this->assertSame([], BreadcrumbScanner::stickyHeaders($scopes, 6, -1));
    }

    public function testPlainTextYieldsNoScopes(): void
    {
        $this->assertSame([], BreadcrumbScanner::scan("just some words\nno php here\n"));
        $this->assertSame([], BreadcrumbScanner::scan(''));
    }

    public function testAFileOverTheByteCapYieldsNoScopesAtAll(): void
    {
        // Real scannable structure, padded past the cap with one long comment
        // line: if the cap check vanished (or flipped to >=), the scan would
        // find Demo and the assertions below would disagree.
        $head = "<?php\nclass Demo\n{\n    public function foo(): void {}\n}\n// ";
        $atCap = $head.\str_repeat('x', BreadcrumbScanner::MAX_SCAN_BYTES - \strlen($head));
        $this->assertSame(BreadcrumbScanner::MAX_SCAN_BYTES, \strlen($atCap));

        $this->assertSame(['Demo', 'foo'], \array_column(BreadcrumbScanner::scan($atCap), 'label'), 'exactly at the cap still scans');
        $this->assertSame([], BreadcrumbScanner::scan($atCap.'x'), 'one byte past the cap scans nothing');
    }
}
