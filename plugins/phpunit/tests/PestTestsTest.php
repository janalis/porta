<?php

declare(strict_types=1);

namespace Porta\PhpUnit\Tests;

use PHPUnit\Framework\TestCase;
use Porta\PhpUnit\PestTests;

/** A Pest file's tests, by the names Pest 5.3 reported for this very file. */
final class PestTestsTest extends TestCase
{
    public const string FILE = <<<'PHP'
        <?php

        test('adds numbers', function () {
            expect(1 + 1)->toBe(2);
        });

        it('fails (on purpose)', function () {
            expect(1)->toBe(2);
        });

        describe('outer', function () {
            describe("inner's", function () {
                \test('deep', fn () => expect(1)->toBe(1));
            });
            it("says \"hi\"", fn () => expect($this->it('not a test'))->toBe(1));
        });

        it('has data', function (int $n) {
            expect($n)->toBeLessThan(2);
        })->with([1, 5]);

        test('is skipped')->skip();
        it($name, fn () => 1);
        function it_helper(): void {}

        PHP;

    public function testEachTestByItsNameAndTheLinesItsCallSpans(): void
    {
        $this->assertSame(
            [
                ['name' => 'adds numbers', 'line' => 2, 'end' => 4],
                ['name' => 'it fails (on purpose)', 'line' => 6, 'end' => 8],
                ['name' => "`outer` → `inner's` → deep", 'line' => 12, 'end' => 12],
                ['name' => '`outer` → it says "hi"', 'line' => 14, 'end' => 14],
                ['name' => 'it has data', 'line' => 17, 'end' => 19],
                ['name' => 'is skipped', 'line' => 21, 'end' => 21],
            ],
            PestTests::in(self::FILE),
            'a method named it, a description that is no plain string, and a function are not tests',
        );
    }

    public function testALineIsLookedUpByName(): void
    {
        $this->assertSame(14, PestTests::lineOf(self::FILE, '`outer` → it says "hi"'));
        $this->assertNull(PestTests::lineOf(self::FILE, 'says "hi"'));
        $this->assertSame([], PestTests::in("<?php\nfinal class FooTest {}\n"));
    }
}
