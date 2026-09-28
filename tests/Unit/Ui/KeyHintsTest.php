<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\KeyHints;
use Symfony\Component\Tui\Input\Keybindings;

final class KeyHintsTest extends TestCase
{
    /** The first map that binds the action answers — the editor's before the IDE's — with its first key, formatted. */
    public function testTheFirstMapThatBindsTheActionAnswers(): void
    {
        $editor = new Keybindings(['save' => ['ctrl+s', 'f10']]);
        $ide = new Keybindings(['save' => ['ctrl+alt+s'], 'ide_quit' => ['ctrl+q']]);

        $this->assertSame('Ctrl+S', KeyHints::key('save', $editor, $ide));
        $this->assertSame('Ctrl+Q', KeyHints::key('ide_quit', $editor, $ide));
        $this->assertSame('', KeyHints::key('nothing_binds_this', $editor, $ide));
        $this->assertSame('', KeyHints::key('save'));
    }

    /** Each bound action's hint, in the order given, joined; an unbound one is left out, separator and all. */
    public function testALegendLeavesOutWhatNothingBinds(): void
    {
        $keys = new Keybindings(['save' => ['ctrl+s'], 'ide_quit' => ['ctrl+q']]);
        $label = static fn (string $message, string $key): string => \str_replace('%key%', $key, $message);

        $this->assertSame(
            'Ctrl+S save · Ctrl+Q quit',
            KeyHints::legend(['save' => '%key% save', 'unbound' => '%key% nope', 'ide_quit' => '%key% quit'], $label, ' · ', $keys),
        );
        $this->assertSame('', KeyHints::legend(['unbound' => '%key% nope'], $label, ' · ', $keys));
    }
}
