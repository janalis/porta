<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * Ratchets the key-hint dialect ({@see \Porta\Ui\ShortcutFormatter}) over the
 * English catalog — `Ctrl+S`, never `^S` or `ctrl+s`.
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
    private const string CATALOG = __DIR__.'/../../../translations/messages.en.xlf';

    /** @return list<string> every <source> text of the English catalog */
    private function sources(): array
    {
        $xml = simplexml_load_file(self::CATALOG);
        self::assertNotFalse($xml, 'messages.en.xlf is not valid XML');
        $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');

        $sources = [];
        $units = $xml->xpath('//x:trans-unit');
        foreach ($units ? $units : [] as $unit) {
            $unit->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
            $node = $unit->xpath('x:source');
            $node = $node ? $node : [];
            if ([] !== $node) {
                $sources[] = (string) $node[0];
            }
        }
        self::assertNotEmpty($sources, 'the catalog scan found nothing to guard');

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
