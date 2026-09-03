<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;

/**
 * `docs/keybindings.md` is what the in-app help (`F1`) renders, so a binding
 * missing from it is a feature nobody can find, and a binding that MOVED
 * leaves the doc actively lying.
 *
 * Both happened. Reclaiming `F2` for Next Problem and `Ctrl+Alt+O` for
 * Optimize Imports left two rows still promising the old meanings, and eight
 * IDE-scope bindings had never been written down at all — the doc had drifted
 * for want of anything checking it.
 *
 * Only the CATALOG's keys are gated. Editor micro-bindings (`page_up` →
 * PageUp, arrow keys) are deliberately not enumerated there, and demanding
 * they be would make the doc a worse document.
 */
final class KeybindingsDocTest extends TestCase
{
    public function testEveryKeyTheCatalogBindsAppearsInTheDoc(): void
    {
        $doc = self::normalise((string) file_get_contents(__DIR__.'/../../../docs/keybindings.md'));

        $missing = [];
        foreach (ActionCatalog::ACTIONS as $id => $spec) {
            foreach ($spec['keys'] ?? [] as $key) {
                if (str_contains($doc, self::normalise($key))) {
                    continue;
                }
                $missing[] = "{$id} → {$key}";
            }
        }

        $this->assertSame([], $missing, "docs/keybindings.md does not mention:\n  ".implode("\n  ", $missing));
    }

    /**
     * One spelling for both sides: the doc writes arrows and PageUp where the
     * catalog writes words, escapes its backticks for markdown, and cares about
     * case where the catalog does not.
     */
    private static function normalise(string $text): string
    {
        return str_replace(
            ['\\`', 'page_down', 'page_up', 'left', 'right', 'pagedown', 'pageup', '←', '→'],
            ['`', 'pgdn', 'pgup', 'arrowl', 'arrowr', 'pgdn', 'pgup', 'arrowl', 'arrowr'],
            strtolower($text),
        );
    }
}
