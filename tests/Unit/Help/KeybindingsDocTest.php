<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;
use Porta\Overlay\State\FindInFilesModal;
use Porta\Widget\EditorKeymap;
use Porta\Widget\FindBarWidget;
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
     * A MODAL's own keys are the only way to use it, so an undocumented one is
     * a feature nobody can reach.
     *
     * This is a THIRD binding channel, after the catalog and the editor's
     * keymap: the find bar and the find-in-files modal each carry a private
     * map of their own, matched with the editor's parser but merged with
     * nothing. That makes the case for documenting them stronger than the menu
     * argument above, not weaker — a menu item at least advertises the verb
     * exists, while `Alt+T` inside the find modal is advertised by nothing at
     * all. The modal draws its option toggles as bare glyphs (`.*`, `Aa`,
     * `\b`), clickable and silent about their chords.
     *
     * Measured when this was written: the two surfaces bind 28 keys, 18 of
     * them their own rather than a modal convention, and the doc's Search
     * section named 9 of those.
     *
     * And it reads the doc's **Search section**, not the whole file, which is
     * the difference between "this chord appears somewhere" and "this chord is
     * documented HERE". Whole-file matching reported three missing keys and
     * called the rest documented — including the modal's `Alt+↑`/`Alt+↓` query
     * recall, which the doc mentions only as the editor's previous/next
     * declaration. A gate satisfied by a row that says something else about
     * the same key is worse than no gate, because it reads as a guarantee.
     * That is round 8's substring lesson one level up: the wrong SCOPE rather
     * than the wrong boundary.
     */
    public function testEveryKeyAModalBindsForItselfAppearsInTheDoc(): void
    {
        $doc = self::expandPairs(self::normalise(self::section('Search')));

        $missing = [];
        foreach (self::modalKeys() as $surface => $keys) {
            foreach ($keys as $id => $key) {
                if (self::mentions($doc, $key)) {
                    continue;
                }
                $missing[] = "{$surface}: {$id} → {$key}";
            }
        }

        $this->assertSame([], $missing, "a modal binds these and docs/keybindings.md does not mention them:\n  ".implode("\n  ", $missing));
    }

    /**
     * One `## `-delimited section of the doc, by heading.
     *
     * @return string its body, heading excluded
     */
    private static function section(string $heading): string
    {
        $doc = (string) file_get_contents(__DIR__.'/../../../docs/keybindings.md');
        $parts = preg_split('/^## /m', $doc);
        self::assertIsArray($parts, 'the doc splits into sections');
        foreach ($parts as $part) {
            if (str_starts_with($part, $heading."\n")) {
                return $part;
            }
        }

        self::fail("docs/keybindings.md has no `## {$heading}` section");
    }

    /**
     * Every key the two find surfaces bind for themselves, minus the six that
     * every modal in the world uses the same way.
     *
     * The exclusions are deliberately narrow. `Esc`, `Enter`, `Tab`,
     * `Backspace` and the arrows are conventions a reader brings with them;
     * `Delete` is NOT on that list even though it looks like it belongs there,
     * because in the find modal it excludes a match from a bulk replace, which
     * is a verb and not a convention.
     *
     * @return array<string, array<string, string>>
     */
    private static function modalKeys(): array
    {
        $universal = ['escape', 'enter', 'tab', 'backspace', 'up', 'down'];

        $surfaces = [];
        foreach (['find in files' => self::findModalKeys(), 'find bar' => self::findBarKeys()] as $surface => $map) {
            foreach ($map as $id => $keys) {
                foreach ($keys as $key) {
                    if (\in_array(mb_strtolower($key), $universal, strict: true)) {
                        continue;
                    }
                    $surfaces[$surface][$id] = $key;
                }
            }
        }

        return $surfaces;
    }

    /** @return array<string, list<string>> */
    private static function findModalKeys(): array
    {
        // @mago-expect lint:inline-variable-return -- getConstant() returns
        // mixed, and the @var on the assignment is what shapes the rows.
        /** @var array<string, list<string>> $keys */
        $keys = new \ReflectionClass(FindInFilesModal::class)->getConstant('KEYS');

        return $keys;
    }

    /**
     * The find bar's are a protected METHOD of the framework's keybinding
     * trait rather than a constant, so they come out through reflection the
     * same way — the point being that the two surfaces disagree about where
     * they keep this, and the gate must not care.
     *
     * @return array<string, list<string>>
     */
    private static function findBarKeys(): array
    {
        $method = new \ReflectionMethod(FindBarWidget::class, 'getDefaultKeybindings');

        // @mago-expect lint:inline-variable-return -- invoke() returns mixed,
        // and the @var on the assignment is what shapes the rows.
        /** @var array<string, list<string>> $keys */
        $keys = $method->invoke(null);

        return $keys;
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
