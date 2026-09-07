<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;
use Porta\Widget\EditorKeymap;
use Porta\Workbench\Menu\MenuModel;

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
 * Editor micro-bindings (`page_up` → PageUp, arrow keys) are deliberately NOT
 * enumerated: twenty-two of them are bound and undocumented on purpose, and
 * demanding they be written down would make the doc a worse document.
 *
 * But that exclusion was doing more work than it was asked to. The editor binds
 * through {@see EditorKeymap::defaults()}, a channel this gate could not see at
 * all — so it covered whichever verbs happened to declare their keys in the
 * CATALOG, and eight verbs sitting on a MENU had no doc row. A menu entry is
 * the IDE advertising a verb; the doc is where you look up its key. Advertised
 * and undocumented is the combination that has no defence, and it is what the
 * second test below gates.
 */
final class KeybindingsDocTest extends TestCase
{
    public function testEveryKeyTheCatalogBindsAppearsInTheDoc(): void
    {
        $doc = self::expandPairs(self::normalise((string) file_get_contents(__DIR__.'/../../../docs/keybindings.md')));

        $missing = [];
        foreach (ActionCatalog::ACTIONS as $id => $spec) {
            foreach ($spec['keys'] ?? [] as $key) {
                if (self::mentions($doc, $key)) {
                    continue;
                }
                $missing[] = "{$id} → {$key}";
            }
        }

        $this->assertSame([], $missing, "docs/keybindings.md does not mention:\n  ".implode("\n  ", $missing));
    }

    public function testEveryEDITORVerbOnAMenuAppearsInTheDocToo(): void
    {
        $doc = self::expandPairs(self::normalise((string) file_get_contents(__DIR__.'/../../../docs/keybindings.md')));

        $missing = [];
        foreach (self::editorKeysOnAMenu() as $id => $keys) {
            foreach ($keys as $key) {
                if (self::mentions($doc, $key)) {
                    continue;
                }
                $missing[] = "{$id} → {$key}";
            }
        }

        $this->assertSame([], $missing, "a menu advertises these and docs/keybindings.md does not mention them:\n  ".implode("\n  ", $missing));
    }

    /**
     * The editor's own bindings for the verbs a menu offers.
     *
     * Two sources, because the two channels are real: a command's keys live in
     * {@see ActionCatalog} when the IDE binds it and in
     * {@see EditorKeymap::defaults()} when the editor widget does — the
     * framework merges widget defaults under the global map, so both bind and
     * only one was ever gated here.
     *
     * The menu's specs are read by reflection: they are private because nothing
     * in `src` needs them, and this is the one question asked from outside —
     * "which verbs does the UI advertise". A `MenuModel` accessor added for a
     * test would be test-only code in src.
     *
     * @return array<string, list<string>>
     */
    private static function editorKeysOnAMenu(): array
    {
        /** @var array<string, list<string>> $defaults */
        $defaults = EditorKeymap::defaults();
        $specs = (string) json_encode(new \ReflectionClass(MenuModel::class)->getConstant('DEFS'));

        $onAMenu = [];
        foreach ($defaults as $id => $keys) {
            // An id the catalog already binds is the first test's business.
            if (null !== (ActionCatalog::ACTIONS[$id]['keys'] ?? null)) {
                continue;
            }
            if (str_contains($specs, '"e:'.$id.'"') || str_contains($specs, '"i:'.$id.'"')) {
                $onAMenu[$id] = $keys;
            }
        }

        return $onAMenu;
    }

    /**
     * Whether the doc mentions this key as a CHORD of its own.
     *
     * Not `str_contains`: `ctrl+shift+u` is a prefix of `ctrl+shift+up`, so
     * Toggle Case read as documented because the doc mentions Move Statement
     * Up. Eight keys passed that way, and the trap generalises — `f1` sits
     * inside `f10`, `f11` and `f12`. The boundary is "not preceded by a chord
     * character or a `+`, and not followed by one", which is what makes the
     * match a whole chord rather than the head of a longer one.
     */
    private static function mentions(string $normalisedDoc, string $key): bool
    {
        return 1 === preg_match('~(?<![a-z0-9+])'.preg_quote(self::normalise($key), delimiter: '~').'(?![a-z0-9])~', $normalisedDoc);
    }

    /**
     * The doc's `Alt+Shift+↑/↓` shorthand, expanded so the second half is a
     * chord of its own.
     *
     * Writing one row for a pair is good documentation and the gate has to read
     * it, not forbid it: without this, Move Line DOWN reads as undocumented
     * while Move Line UP passes, and the fix would be to make the doc worse.
     */
    private static function expandPairs(string $normalised): string
    {
        return (string) preg_replace_callback(
            '~((?:[a-z]+\+)+)([a-z0-9]+)/([a-z0-9]+)~',
            static fn (array $m): string => $m[0].' '.$m[1].$m[3],
            $normalised,
        );
    }

    /**
     * One spelling for both sides: the doc writes arrows and PageUp where the
     * catalog writes words, escapes its backticks for markdown, and cares about
     * case where the catalog does not.
     */
    private static function normalise(string $text): string
    {
        return str_replace(
            // The vertical arrows map to the WORDS rather than to a marker of
            // their own: 'pgup' contains 'up', so rewriting the word would
            // corrupt the page keys handled just before it.
            ['\\`', 'page_down', 'page_up', 'left', 'right', 'pagedown', 'pageup', '←', '→', '↑', '↓'],
            ['`', 'pgdn', 'pgup', 'arrowl', 'arrowr', 'pgdn', 'pgup', 'arrowl', 'arrowr', 'up', 'down'],
            strtolower($text),
        );
    }
}
