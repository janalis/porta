<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\SurfaceKeymaps;
use Porta\Widget\EditorKeymap;

/**
 * The editor's key table, pinned against a golden file.
 *
 * It has to be pinned by SOMETHING: nothing else asserts these values exactly,
 * so an ArrayItemRemoval or a dropped alternate binding is invisible to the
 * rest of the suite. It used to be pinned by a copy of the table inside this
 * test — a hundred lines restated, which the duplication gate found and which
 * was never really an assertion: it said the table equals this transcription
 * of the table, so a wrong edit made twice passed.
 *
 * The golden is stronger as well as shorter. The old copy spelled the values
 * as `Key::UP`, the same constants the source uses, so a change to what
 * `Key::UP` IS could not fail it; the file holds the resolved spec (`"up"`),
 * which is what the parser actually receives.
 */
final class EditorKeymapTest extends TestCase
{
    private const string GOLDEN = __DIR__.'/../../Support/golden/editor-keymap.json';

    public function testDefaultsMapsEveryActionToItsExactKeyList(): void
    {
        $actual = EditorKeymap::defaults();

        if (\getenv('UPDATE_EDITOR_KEYMAP_GOLDEN')) {
            \file_put_contents(self::GOLDEN, \json_encode($actual, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            $this->markTestSkipped('Golden regenerated.');
        }

        $this->assertFileExists(self::GOLDEN, 'run once with UPDATE_EDITOR_KEYMAP_GOLDEN=1 to seed');
        /** @var array<string, list<string>> $expected */
        $expected = \json_decode((string) \file_get_contents(self::GOLDEN), associative: true, flags: \JSON_THROW_ON_ERROR);

        // Action by action, so a failure names the verb rather than printing
        // two hundred lines of array diff.
        foreach ($expected as $action => $keys) {
            $this->assertArrayHasKey($action, $actual, "action {$action} is no longer bound — if intended, rerun with UPDATE_EDITOR_KEYMAP_GOLDEN=1");
            $this->assertSame($keys, $actual[$action], "action {$action} binds different keys — if intended, rerun with UPDATE_EDITOR_KEYMAP_GOLDEN=1");
        }
        $this->assertSame(\array_keys($expected), \array_keys($actual), 'the action set changed — if intended, rerun with UPDATE_EDITOR_KEYMAP_GOLDEN=1');
    }

    /**
     * A surface that keeps the IDE's own verbs working while it has the keys
     * (saving, tab management, Go to File) binds them to the editor's keys: a
     * copy that drifted would leave Ctrl+F4 closing a tab everywhere but
     * there. Only these: a find bar's Alt+C or a list's Home reuse an editor
     * id for their own meaning, and are no copy.
     */
    public function testASurfaceThatKeepsTheIdesVerbsBindsTheEditorsKeys(): void
    {
        $editor = \array_intersect_key(EditorKeymap::defaults(), \array_flip(['save', 'save_all', 'next_buffer', 'prev_buffer', 'recent_files', 'close_buffer', 'goto_file']));
        $checked = 0;
        foreach (SurfaceKeymaps::all() as $class => $keys) {
            $shared = \array_intersect_key($keys, $editor);
            $this->assertSame(\array_replace($shared, \array_intersect_key($editor, $shared)), $shared, "{$class} binds an IDE verb to other keys than the editor does");
            $checked += \count($shared);
        }
        $this->assertGreaterThan(0, $checked, 'no surface keeps an IDE verb, so this compared nothing');
    }
}
