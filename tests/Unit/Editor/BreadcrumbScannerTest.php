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
        return array_map(static fn (array $c): string => $c['label'], $trail);
    }

    public function testNamespaceClassMethodPath(): void
    {
        $src = "<?php\nnamespace A\\B;\nclass C\n{\n    public function d(): void\n    {\n        \$x = 1;\n    }\n}\n";
        $scopes = BreadcrumbScanner::scan($src);

        // Caret on the `$x` line (0-based line 6) is inside all three scopes.
        $trail = BreadcrumbScanner::select($scopes, 6);
        $this->assertSame(['A\\B', 'C', 'd'], $this->labels($trail));
        // Jump lines are the 0-based declaration lines: namespace@1, class@2, method@4.
        $this->assertSame([1, 2, 4], array_map(static fn (array $c): int => $c['line'], $trail));
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

    public function testPlainTextYieldsNoScopes(): void
    {
        $this->assertSame([], BreadcrumbScanner::scan("just some words\nno php here\n"));
        $this->assertSame([], BreadcrumbScanner::scan(''));
    }
}
