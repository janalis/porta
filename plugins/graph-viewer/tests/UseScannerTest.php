<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\UseScanner;

final class UseScannerTest extends TestCase
{
    public function testPlainNamespaceAndImports(): void
    {
        $scanned = UseScanner::scan(<<<'PHP'
            <?php
            namespace App\Service;

            use App\Repository\UserRepository;
            use Psr\Log\LoggerInterface;

            final class UserService {}
            PHP);

        $this->assertSame('App\Service', $scanned['namespace']);
        $this->assertSame(['App\Repository\UserRepository', 'Psr\Log\LoggerInterface'], $scanned['uses']);
    }

    public function testAliasesRecordTheTargetNotTheAlias(): void
    {
        $scanned = UseScanner::scan(<<<'PHP'
            <?php
            namespace App;

            use App\Very\Long\Name as Short;
            use App\Other as O, App\Kept;
            PHP);

        $this->assertSame(['App\Very\Long\Name', 'App\Other', 'App\Kept'], $scanned['uses']);
    }

    public function testGroupedImportsExpandThePrefix(): void
    {
        $scanned = UseScanner::scan(<<<'PHP'
            <?php
            namespace App;

            use App\Domain\{User, Order\Item, Billing as B};
            PHP);

        $this->assertSame(['App\Domain\User', 'App\Domain\Order\Item', 'App\Domain\Billing'], $scanned['uses']);
    }

    public function testFunctionAndConstImportsAreSkippedStatementWide(): void
    {
        $scanned = UseScanner::scan(<<<'PHP'
            <?php
            namespace App;

            use function App\Support\slugify, App\Support\other;
            use const App\Support\VERSION;
            use App\Kept;
            PHP);

        $this->assertSame(['App\Kept'], $scanned['uses']);
    }

    public function testTraitUseAndClosureUseNeverMatch(): void
    {
        $scanned = UseScanner::scan(<<<'PHP'
            <?php
            namespace App;

            use App\RealImport;

            final class X
            {
                use SomeTrait;

                public function f(): \Closure
                {
                    $y = 1;

                    return function () use ($y): int {
                        return $y;
                    };
                }
            }
            PHP);

        $this->assertSame(['App\RealImport'], $scanned['uses']);
    }

    public function testAGroupEndingWithoutAnAliasFlushesAtTheBrace(): void
    {
        $scanned = UseScanner::scan("<?php namespace App; use App\\Grouped\\{One, Two};");

        $this->assertSame(['App\Grouped\One', 'App\Grouped\Two'], $scanned['uses']);
    }

    public function testATopLevelClosureUseIsNotAnImport(): void
    {
        $scanned = UseScanner::scan("<?php namespace App; use App\\Kept; \$x = 1; \$f = function () use (\$x): int { return \$x; };");

        $this->assertSame(['App\Kept'], $scanned['uses']);
    }

    public function testNoNamespaceAndMalformedSourceAreTolerated(): void
    {
        $this->assertSame(['A\B'], UseScanner::scan("<?php use A\\B;")['uses']);
        $this->assertNull(UseScanner::scan("<?php use A\\B;")['namespace']);

        $broken = UseScanner::scan("<?php namespace App; use A\\B; final class {{{ ");
        $this->assertSame('App', $broken['namespace']);
        $this->assertSame(['A\B'], $broken['uses']);
    }

    public function testBracedNamespaceRecordsTheNameButNotItsImports(): void
    {
        // The documented limitation: depth-1 imports are not collected.
        $scanned = UseScanner::scan(<<<'PHP'
            <?php
            namespace App\Braced {
                use App\Inside;
            }
            PHP);

        $this->assertSame('App\Braced', $scanned['namespace']);
        $this->assertSame([], $scanned['uses']);
    }

    public function testGroupedFunctionImportsAreAlsoSkippedStatementWide(): void
    {
        $scanned = UseScanner::scan(<<<'PHP'
            <?php
            namespace App;

            use function App\Support\{slugify, other};
            use App\Kept;
            PHP);

        $this->assertSame(['App\Kept'], $scanned['uses']);
    }

    public function testStringInterpolationBracesDoNotConfuseDepth(): void
    {
        $scanned = UseScanner::scan(<<<'PHP'
            <?php
            namespace App;

            use App\Kept;

            $x = "prefix {$var} and ${other} suffix";
            use App\AlsoKept;
            PHP);

        $this->assertSame(['App\Kept', 'App\AlsoKept'], $scanned['uses']);
    }
}
