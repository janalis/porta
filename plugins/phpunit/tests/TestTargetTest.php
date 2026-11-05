<?php

declare(strict_types=1);

namespace Porta\PhpUnit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\PhpUnit\TestTarget;

/** "The test at the caret", read from a test file's text. */
final class TestTargetTest extends TestCase
{
    private const string FILE = <<<'PHP_WRAP'
        <?php

        namespace App\Tests;

        use PHPUnit\Framework\Attributes\Test;

        final class DemoTest extends TestCase
        {
            public function testPasses(): void
            {
                $this->assertTrue(true);
            }

            #[Test]
            #[\PHPUnit\Framework\Attributes\Group('slow')]
            public function attributed(): void
            {
            }

            /**
             * @test
             */
            public function documented(): void
            {
            }

            private function helper(): void
            {
            }

            // not a test
            public function notATest(): void
            {
            }
        }
        PHP_WRAP;

    /** @return iterable<string, array{int, ?string}> */
    public static function carets(): iterable
    {
        yield 'above every method' => [4, null];
        yield 'on a test\'s declaration' => [8, 'testPasses'];
        yield 'inside a test\'s body' => [10, 'testPasses'];
        yield 'past a test, before the next' => [12, 'testPasses'];
        yield 'an attribute marks a test' => [17, 'attributed'];
        yield 'a docblock tag marks a test' => [23, 'documented'];
        yield 'a helper is not a test' => [27, null];
        yield 'a comment above is no marker' => [32, null];
        yield 'past the end' => [99, null];
    }

    #[DataProvider('carets')]
    public function testTheMethodAroundTheCaret(int $line, ?string $method): void
    {
        $target = TestTarget::at(self::FILE, $line);

        $this->assertInstanceOf(TestTarget::class, $target);
        $this->assertSame('App\Tests\DemoTest', $target->class);
        $this->assertSame($method, $target->method);
    }

    public function testTheClassNeedsNoNamespaceButMustExist(): void
    {
        $this->assertSame('DemoTest', TestTarget::at("<?php\nabstract class DemoTest {}\n", 0)?->class);
        $this->assertSame('A\B\CTest', TestTarget::at("<?php\nnamespace A\\B;\n\nreadonly final class CTest {}\n", 3)?->class);
        $this->assertNull(TestTarget::at("<?php\nfunction helper() {}\n", 1));
    }

    public function testTheEdgesOfTheScan(): void
    {
        $this->assertSame('testLast', TestTarget::at("<?php\nclass ATest {\npublic function testLast() {}", 2)?->method, 'a caret past the end stops on the last line, which may be the method');
        $this->assertSame('check', TestTarget::at("#[Test]\npublic function check(): void {}\nclass ATest {}\n", 1)?->method, 'a marker on the first line counts');
        $this->assertSame('testTop', TestTarget::at("function testTop() {}\nclass ATest {}\n", 0)?->method, 'so does a method on it');
        $this->assertSame('marked', TestTarget::at("<?php\nclass ATest {\n#[Test]\npublic function marked() {}\n}\n", 3)?->method, 'a marker right above counts');
        $this->assertNull(TestTarget::at("<?php\nclass ATest {\n/**\n * Not a #[Test], a helper\n */\npublic function helper() {}\n}\n", 5)?->method, 'a marker inside prose is no marker');

        $commented = TestTarget::at("<?php\n// was in namespace Old\\Place; moved\nnamespace New\\Place;\n\n/**\n * The class Helper below is gone.\n */\nfinal class DemoTest {}\n", 7);
        $this->assertSame('New\\Place\\DemoTest', $commented?->class, 'a namespace or a class named in a comment is not the declaration');
    }

    public function testTheCaretOnAMethodsAttributesOrDocblockMeansThatMethod(): void
    {
        $text = "<?php\nclass ATest {\npublic function testA() {}\n\n/**\n * Checks B.\n */\n#[Test]\n#[DataProvider('rows')]\npublic function testB() {}\n/** a comment, then code */\n\$x = 1;\n}\n";

        foreach ([4 => 'testB', 5 => 'testB', 7 => 'testB', 8 => 'testB', 9 => 'testB', 3 => 'testA', 10 => 'testB', 11 => 'testB'] as $line => $method) {
            $this->assertSame($method, TestTarget::at($text, $line)?->method, 'line '.$line);
        }
        $this->assertSame('testB', TestTarget::at("<?php\nclass ATest {\n#[Test]\npublic function testB() {}", 2)?->method, 'an attribute on the last lines still reaches its method');
    }

    public function testTheClassIsTheOneAroundTheCaret(): void
    {
        $text = "<?php\nnamespace App;\n\nfinal class FakeClock {}\n\nfinal class ClockTest extends TestCase {\npublic function testA() {}\n}\n\nfinal class Helper {}\n";

        $this->assertSame('App\\ClockTest', TestTarget::at($text, 6)?->class, 'not the fixture declared before it');
        $this->assertSame('App\\FakeClock', TestTarget::at($text, 3)?->class, 'on a class, that class');
        $this->assertSame('App\\ClockTest', TestTarget::at($text, 1)?->class, 'above every class: the one named like a test');
        $this->assertSame('App\\Helper', TestTarget::at("<?php\nnamespace App;\nfinal class Helper {}\nfinal class Other {}\n", 0)?->class, 'and with none named so, the first');
    }

    public function testTheFilterRunsEachMethodWithEveryDataSet(): void
    {
        $filter = TestTarget::filter([
            ['App\DemoTest', 'testA'],
            ['App\DemoTest', 'testA'],
            ['App\Other',    'testB'],
        ]);

        $this->assertSame('/^(?:App\\\\DemoTest\:\:testA|App\\\\Other\:\:testB)(?:\s|$)/', $filter, 'a pair named twice is one alternative');
        $this->assertMatchesRegularExpression($filter, 'App\DemoTest::testA');
        $this->assertMatchesRegularExpression($filter, 'App\DemoTest::testA with data set "two"');
        $this->assertDoesNotMatchRegularExpression($filter, 'App\DemoTest::testAB');
        $this->assertDoesNotMatchRegularExpression($filter, 'Other\App\DemoTest::testA');
    }
}
