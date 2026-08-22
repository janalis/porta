<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Lsp\Uri;

final class UriTest extends TestCase
{
    public function testToPathDecodesFileUri(): void
    {
        $this->assertSame('/a/b c.php', Uri::toPath('file:///a/b%20c.php'));
    }

    public function testToPathRejectsNonFileScheme(): void
    {
        $this->assertNull(Uri::toPath('untitled:scratch'));
    }

    public function testToPathRejectsEmptyPath(): void
    {
        $this->assertNull(Uri::toPath('file://'));
    }

    public function testFromPathEncodesSpecialCharactersPerSegment(): void
    {
        // A path that does not exist on disk is returned verbatim (no realpath),
        // so we can assert the encoding deterministically.
        $uri = Uri::fromPath('/tmp/does not exist/a+b.php');

        $this->assertSame('file:///tmp/does%20not%20exist/a%2Bb.php', $uri);
        // Slashes between segments are preserved, not encoded.
        $this->assertStringContainsString('/does%20not%20exist/', $uri);
    }

    public function testTextDocumentWrapsTheUriUnderTheUriKey(): void
    {
        $this->assertSame(['uri' => 'file:///tmp/a.php'], Uri::textDocument('/tmp/a.php'));
    }

    public function testRoundTripThroughRealPath(): void
    {
        $file = tempnam(sys_get_temp_dir(), prefix: 'uri');
        $this->assertNotFalse($file);
        try {
            $this->assertSame(realpath($file), Uri::toPath(Uri::fromPath($file)));
        } finally {
            is_file($file) && unlink($file);
        }
    }
}
