<?php

declare(strict_types=1);

namespace Porta\PhpUnit\Tests;

use PHPUnit\Framework\TestCase;
use Porta\PhpUnit\CloverReport;

/**
 * The Clover report PHPUnit 13 writes, read back into lines that ran. The
 * report is what `--coverage-clover` printed for a class of two methods, one
 * branch of which no test took.
 */
final class CloverReportTest extends TestCase
{
    public function testEachStatementLineSaysWhetherItRan(): void
    {
        $files = CloverReport::parse(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <coverage generated="1">
              <project timestamp="1" name="Clover Coverage">
                <package name="App">
                  <file name="/porta-no-such-root/src/Calc.php">
                    <class name="App\Calc" namespace="App"><metrics complexity="3"/></class>
                    <line num="5" type="method" name="add" visibility="public" complexity="1" crap="1" count="1"/>
                    <line num="7" type="stmt" count="1"/>
                    <line num="10" type="method" name="div" visibility="public" complexity="2" crap="2.15" count="1"/>
                    <line num="12" type="stmt" count="3"/>
                    <line num="13" type="stmt" count="0"/>
                    <line num="15" type="stmt" count="1"/>
                  </file>
                </package>
                <file name="/porta-no-such-root/src/Empty.php"/>
              </project>
            </coverage>
            XML);

        $this->assertSame(
            [
                '/porta-no-such-root/src/Calc.php' => [6 => true, 11 => true, 12 => false, 14 => true],
                '/porta-no-such-root/src/Empty.php' => [],
            ],
            $files,
            'a statement ran when counted at least once; a method line is a signature, not a statement; a file outside any package counts too',
        );
    }

    public function testANonReportIsRefused(): void
    {
        foreach (['', '<testsuites/>'] as $text) {
            try {
                CloverReport::parse($text);
                $this->fail('accepted '.$text);
            } catch (\RuntimeException $e) {
                $this->assertSame('Not a Clover report', $e->getMessage());
            }
        }
    }
}
