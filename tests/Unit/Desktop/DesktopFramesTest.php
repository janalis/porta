<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Desktop;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Desktop\DesktopFrames;

/** What counts as the window asking to open a path: the whole request, and nothing around it. */
final class DesktopFramesTest extends TestCase
{
    public function testAnOpenNamesItsPath(): void
    {
        $this->assertSame('/work/shop', DesktopFrames::openPath('open;'.\base64_encode('/work/shop')."\x1b\\"));
    }

    /** @return iterable<string, array{string}> */
    public static function notOpens(): iterable
    {
        $path = \base64_encode('/work/shop');
        yield 'something before it' => ["xopen;{$path}\x1b\\"];
        yield 'something after it' => ["open;{$path}\x1b\\x"];
        yield 'a line break after it' => ["open;{$path}\x1b\\\n"];
        yield 'not base64' => ["open;not base64!\x1b\\"];
        yield 'no terminator' => ["open;{$path}"];
        yield 'nothing named' => ["open;\x1b\\"];
        yield 'another request' => ["close\x1b\\"];
    }

    #[DataProvider('notOpens')]
    public function testAnythingElseNamesNoPath(string $request): void
    {
        $this->assertNull(DesktopFrames::openPath($request));
    }
}
