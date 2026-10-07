<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Editor\InlayArgument;

final class InlayArgumentTest extends TestCase
{
    #[DataProvider('arguments')]
    public function testALiteralIsWhatSaysNothingAboutItsPurpose(string $argument, bool $literal): void
    {
        $this->assertSame($literal, InlayArgument::isLiteral("f({$argument});", 2), $argument);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function arguments(): iterable
    {
        foreach (['42', '-1', '+3', '.5', '-.5', "'x'", '"x"', '<<<EOT', '[1]', 'array(1)', 'ARRAY (1)', 'null', 'NULL', 'true', 'False', '\\true', '  42'] as $literal) {
            yield $literal => [$literal, true];
        }
        foreach (['$x', 'foo()', 'nullable()', 'true_x', 'null\\x', 'new Foo', '-$x', 'PHP_EOL', 'Foo::BAR', 'fn () => 1'] as $named) {
            yield $named => [$named, false];
        }
    }

    public function testAColumnPastTheLineIsNoLiteral(): void
    {
        $this->assertFalse(InlayArgument::isLiteral('f(1)', 40));
    }
}
