<?php

declare(strict_types=1);

namespace Porta\PhpUnit\Tests;

use PHPUnit\Framework\TestCase;
use Porta\PhpUnit\JunitReport;
use Porta\PhpUnit\TestOutcome;
use Porta\Tests\Support\TempProject;

/**
 * The JUnit log PHPUnit 13 writes, read back into outcomes. The report below
 * is what `--log-junit` printed for a real class of five methods, its paths
 * moved under a root that cannot exist.
 */
final class JunitReportTest extends TestCase
{
    private const string ROOT = '/porta-no-such-root';

    private static function report(string $file = self::ROOT.'/tests/DemoTest.php'): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="App\\Tests\\DemoTest" file="{$file}" tests="6">
                <testcase name="testPasses" file="{$file}" line="7" class="App\\Tests\\DemoTest" classname="App.Tests.DemoTest" assertions="1" time="0.0001"/>
                <testcase name="testFails" file="{$file}" line="8" class="App\\Tests\\DemoTest" classname="App.Tests.DemoTest" assertions="1" time="0.002">
                  <failure type="PHPUnit\\Framework\\ExpectationFailedException">App\\Tests\\DemoTest::testFails
            Failed asserting that 2 is identical to 1.

            /porta-no-such-root/src/Helper.php:30
            {$file}:9</failure>
                </testcase>
                <testcase name="attributed" file="{$file}" line="12" class="App\\Tests\\DemoTest" classname="App.Tests.DemoTest" assertions="0" time="0.0001">
                  <skipped/>
                </testcase>
                <testsuite name="App\\Tests\\DemoTest::testProvided" tests="2">
                  <testcase name="testProvided with data set &quot;one&quot;" file="{$file}" line="15" class="App\\Tests\\DemoTest" classname="App.Tests.DemoTest" assertions="1" time="0.0001"/>
                  <testcase name="testProvided with data set &quot;two&quot;" file="{$file}" line="15" class="App\\Tests\\DemoTest" classname="App.Tests.DemoTest" assertions="1" time="0.0001">
                    <failure type="PHPUnit\\Framework\\ExpectationFailedException">App\\Tests\\DemoTest::testProvided with data set "two"
            Failed asserting that 2 is identical to 1.

            {$file}:15</failure>
                  </testcase>
                </testsuite>
                <testcase name="testErrors" file="{$file}" line="16" class="App\\Tests\\DemoTest" classname="App.Tests.DemoTest" assertions="0" time="0.0001">
                  <error type="RuntimeException">App\\Tests\\DemoTest::testErrors
            RuntimeException: boom

            /porta-no-such-root/vendor/lib.php:4</error>
                </testcase>
              </testsuite>
            </testsuites>
            XML;
    }

    public function testEveryCaseIsReadWhereverItSits(): void
    {
        $outcomes = JunitReport::parse(self::report(), self::ROOT);

        $this->assertSame(
            [
                ['testPasses',   'testPasses',                       TestOutcome::PASSED,  6,  6,  ''],
                ['testFails',    'testFails',                        TestOutcome::FAILED,  7,  8,  'Failed asserting that 2 is identical to 1.'],
                ['attributed',   'attributed',                       TestOutcome::SKIPPED, 11, 11, ''],
                ['testProvided', 'testProvided with data set "one"', TestOutcome::PASSED,  14, 14, ''],
                ['testProvided', 'testProvided with data set "two"', TestOutcome::FAILED,  14, 14, 'Failed asserting that 2 is identical to 1.'],
                ['testErrors',   'testErrors',                       TestOutcome::ERRORED, 15, 15, 'RuntimeException: boom'],
            ],
            \array_map(static fn (TestOutcome $o): array => [$o->method, $o->name, $o->status, $o->line, $o->at, $o->message], $outcomes),
            'a data set keeps its method; a failure points at its frame in the test file, not a helper\'s; an error with no such frame points at the declaration',
        );
        $this->assertSame('App\Tests\DemoTest', $outcomes[0]->class);
        $this->assertSame(self::ROOT.'/tests/DemoTest.php', $outcomes[0]->file, 'a root with no real path leaves the files as written');
        $this->assertSame('App\Tests\DemoTest::testProvided with data set "two"', $outcomes[4]->label());
        $this->assertSame([false, true, false, false, true, true], \array_map(static fn (TestOutcome $o): bool => $o->broken(), $outcomes));
    }

    public function testFilesComeBackUnderTheRootAsTheIdeSpellsIt(): void
    {
        $project = new TempProject(['real/tests/DemoTest.php' => '<?php']);
        \symlink($project->path('real'), $project->path('link'));
        $real = (string) \realpath($project->path('real'));

        $outcomes = JunitReport::parse(self::report($real.'/tests/DemoTest.php'), $project->path('link'));

        $this->assertSame($project->path('link').'/tests/DemoTest.php', $outcomes[0]->file);
        $this->assertSame(8, $outcomes[1]->at, 'the trace is matched in PHPUnit\'s own spelling');
    }

    /** @param list<string> $cases <testcase> elements */
    private static function suite(array $cases): string
    {
        return '<?xml version="1.0"?><testsuites><testsuite name="x">'.\implode('', $cases).'</testsuite></testsuites>';
    }

    public function testTheEdgesOfWhatThePlaceIsReadFrom(): void
    {
        $odd = self::ROOT.'/tests (1)/A+Test.php'; // regex metacharacters, quoted
        $outcomes = JunitReport::parse(self::suite([
            '<testcase name="testFirst" file="'
                .$odd
                .'" line="1" class="ATest"><failure>'
                ."\n  ATest::testFirst\n  Failed asserting that false is true.  \n\n"
                .self::ROOT
                .'/tests 1/AATest.php:3'
                ."\n"
                .$odd
                .':5</failure></testcase>',
            '<testcase name="testNoLine" file="'.self::ROOT.'/tests/BTest.php" class="BTest"/>',
            '<testcase name="testOutside" file="/porta-elsewhere/tests/CTest.php" line="3" class="CTest"/>',
            '<testcase name="testSibling" file="'.self::ROOT.'-2/tests/DTest.php" line="3" class="DTest"/>',
        ]), self::ROOT);

        $this->assertSame([0, 4], [$outcomes[0]->line, $outcomes[0]->at], 'line 1 is line 0; the frame is found by its path as written, not one a pattern of it would match');
        $this->assertSame('Failed asserting that false is true.', $outcomes[0]->message, 'the detail and its line are trimmed');
        $this->assertSame(0, $outcomes[1]->line, 'a case with no line starts at the top');
        $this->assertSame('/porta-elsewhere/tests/CTest.php', $outcomes[2]->file, 'a file outside the root is left as written');
        $this->assertSame(self::ROOT.'-2/tests/DTest.php', $outcomes[3]->file, 'so is one in a directory that only starts like it');
    }

    public function testFilesOutsideARealRootStayAsWritten(): void
    {
        $project = new TempProject(['tests/ATest.php' => '<?php']);
        $real = (string) \realpath($project->path());
        $outcomes = JunitReport::parse(
            self::suite([
                '<testcase name="a" file="'.$real.'/tests/ATest.php" line="2" class="A"/>',
                '<testcase name="b" file="'.$real.'-other/tests/BTest.php" line="2" class="B"/>',
            ]),
            $project->path(),
        );

        $this->assertSame($project->path('tests/ATest.php'), $outcomes[0]->file);
        $this->assertSame($real.'-other/tests/BTest.php', $outcomes[1]->file, 'a sibling that only starts like the root is not under it');
    }

    public function testARefusalLeavesLibxmlAsItWas(): void
    {
        foreach ([false, true] as $mode) {
            \libxml_use_internal_errors($mode);
            $refused = false;
            try {
                JunitReport::parse('<testsuites><unclosed>', self::ROOT);
            } catch (\RuntimeException) {
                $refused = true;
            }

            $this->assertTrue($refused, 'a broken document is refused');
            // Read before the mode goes back: switching it off empties the buffer.
            $this->assertSame([], \libxml_get_errors(), 'the parse leaves no errors behind for a caller that collects them');
            $this->assertSame($mode, \libxml_use_internal_errors(false), 'and the caller\'s error mode is put back');
        }
    }

    public function testANonReportIsRefused(): void
    {
        foreach (['', 'PHPUnit 13.4 by Sebastian Bergmann', '<?xml version="1.0"?><checkstyle/>'] as $text) {
            try {
                JunitReport::parse($text, self::ROOT);
                $this->fail('accepted '.$text);
            } catch (\RuntimeException $e) {
                $this->assertSame('Not a JUnit report', $e->getMessage());
            }
        }
        $this->assertSame([], JunitReport::parse('<testsuites/>', self::ROOT), 'a run of no tests is a report');
    }
}
