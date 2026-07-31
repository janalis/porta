<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Menu;

use PHPUnit\Framework\TestCase;
use Porta\Workbench\Menu\MenuFileContext;

/** The one-invocation scope: outside a call there is no context file. */
final class MenuFileContextTest extends TestCase
{
    public function testTheFileIsSetForTheCallAndCleared(): void
    {
        $context = new MenuFileContext();
        $this->assertNull($context->file(), 'nothing outside an invocation');

        $seen = null;
        $context->with('/p/a.php', static function () use ($context, &$seen): void {
            $seen = $context->file();
        });

        $this->assertSame('/p/a.php', $seen);
        $this->assertNull($context->file(), 'and gone again — a stale path would misdirect the NEXT command');
    }

    public function testAThrowingHandlerStillClearsIt(): void
    {
        // Every plugin handler is wrapped in a try/catch upstream, so a throw
        // here is ordinary, not exceptional: leaking the path on that route
        // would leave the next invocation acting on a file nobody clicked.
        $context = new MenuFileContext();

        $this->expectException(\RuntimeException::class);

        try {
            $context->with('/p/a.php', static fn () => throw new \RuntimeException('boom'));
        } finally {
            $this->assertNull($context->file());
        }
    }

    public function testNestingRestoresTheOuterFile(): void
    {
        // A handler that runs another command must not silently rewrite what
        // the outer one is about.
        $context = new MenuFileContext();
        $inner = null;
        $afterInner = null;

        $context->with('/p/outer.php', static function () use ($context, &$inner, &$afterInner): void {
            $context->with('/p/inner.php', static function () use ($context, &$inner): void {
                $inner = $context->file();
            });
            $afterInner = $context->file();
        });

        $this->assertSame('/p/inner.php', $inner);
        $this->assertSame('/p/outer.php', $afterInner);
    }
}
