<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\Catalogs;

/**
 * Ratchets the key-hint dialect ({@see \Porta\Ui\ShortcutFormatter}) over the
 * English catalogs, core's and every plugin's — `Ctrl+S`, never `^S` or `ctrl+s`.
 *
 * It scans the catalog rather than src/, and that is what makes it work. The
 * codebase is full of legitimate lowercase `alt+m` strings: they are
 * keybinding TOKENS (ActionCatalog's keymap, .porta.json, every widget's
 * getDefaultKeybindings) and a grep over src/ drowns in them — ~140 hits, all
 * but a couple of them correct. Tokens are never passed to trans(), so they
 * are never a <source>; every user-visible hint is. The false positives
 * vanish by construction.
 *
 * Its limit is the mirror of that: it guards TRANSLATED strings only. A hint
 * built from bare literals is invisible to it, which is why the few
 * deliberately-untranslated pure-token hints are already canonical. A ratchet,
 * not a proof.
 */
final class HintDialectTest extends TestCase
{
    /** @return list<string> every <source> text of every set's English catalog */
    private function sources(): array
    {
        $sources = [];
        foreach (\array_keys(Catalogs::sets()) as $set) {
            $sources = [...$sources, ...Catalogs::englishSources($set)];
        }
        $this->assertNotEmpty($sources, 'the catalog scan found nothing to guard');

        return $sources;
    }

    public function testNoUserVisibleStringSpellsAModifierWithASymbol(): void
    {
        // ^Q survived the dialect sweep in the status bar for a whole release,
        // 19 lines above the legend that sweep fixed.
        foreach ($this->sources() as $source) {
            $this->assertDoesNotMatchRegularExpression('/\^[A-Z]|⌥|⇧/u', $source, $source);
        }
    }

    public function testNoUserVisibleStringSpellsAModifierInLowercase(): void
    {
        // Deliberately case-SENSITIVE: `Ctrl+Space` is the dialect, `alt+m` is
        // a raw keybinding token that leaked into a label. An /i flag here
        // would match both and the test could never pass.
        foreach ($this->sources() as $source) {
            $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z])(alt|ctrl|shift)\+/', $source, $source);
        }
    }
}
