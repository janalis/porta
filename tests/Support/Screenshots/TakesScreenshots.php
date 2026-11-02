<?php

declare(strict_types=1);

namespace Porta\Tests\Support\Screenshots;

use Porta\Ide;
use Porta\Tests\Support\Services;
use Porta\Workbench\Notifier;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Take a docs screenshot from inside an E2e step.
 *
 * Every suite run draws it and checks it is a well-formed SVG, so a scenario
 * that stops reaching its screen fails the PR rather than the docs deploy. Only
 * with `PORTA_SCREENSHOTS=<dir>` set is it also written, as `<dir>/<name>.svg`
 * — which is what `npm run docs:screenshots` does before the docs build. The
 * images are never committed: they are always the IDE as it is now.
 *
 */
trait TakesScreenshots
{
    /** Satisfied by the host TestCase. */
    abstract public static function assertNotFalse(mixed $condition, string $message = ''): void;

    /** Satisfied by the host TestCase. */
    abstract public static function assertContains(mixed $needle, iterable $haystack, string $message = ''): void;

    /** $name is what the docs reference: `/screenshots/<name>.svg`. */
    private function screenshot(string $name, Tui $tui, VirtualTerminal $terminal, Ide $ide): void
    {
        self::assertContains($name, Screenshots::names(), 'a screenshot is listed in Screenshots, where the docs gate can see it');
        // A toast is the moment after an action, not the screen a page describes.
        Services::resolve($ide, Notifier::class)->dismissAll();
        // Kept, not dropped: the pictures a frame shows were sent before it.
        $before = $terminal->consumeOutput();
        // A forced render resets the writer: the next frame is a full one.
        $tui->requestRender(force: true);
        $tui->processRender();
        $columns = \max(1, $terminal->getColumns());
        $rows = \max(1, $terminal->getRows());
        $svg = ScreenSvg::fromFrame($terminal->consumeOutput(), $columns, $rows, ShopProject::NAME.' — Porta', $before);
        self::assertNotFalse(\simplexml_load_string($svg), "the {$name} screenshot is well-formed SVG");

        $dir = \getenv('PORTA_SCREENSHOTS');
        if (!\is_string($dir) || '' === $dir) {
            return;
        }
        // Filesystem, not mkdir(): paratest workers create it at once.
        new Filesystem()->dumpFile($dir.'/'.$name.'.svg', $svg);
    }
}
