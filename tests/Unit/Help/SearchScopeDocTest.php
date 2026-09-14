<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;
use Porta\Ui\ScopeKind;

/**
 * Every built-in search scope has to be named in the docs.
 *
 * `docs/search-navigation.md` described `Alt+S` as "Whole Project or saved
 * scopes" and stopped there — so four of the five built-in kinds were
 * undocumented, `Module` among them. That is not a cosmetic gap: `Module`
 * falls back to the WHOLE PROJECT when nothing above the file holds a
 * `composer.json`, and a reader who has never been told the scope exists has
 * certainly never been told it does that.
 *
 * The sibling of {@see KeybindingsDocTest}, and for the same reason: nothing
 * else reads prose, so a vocabulary that grows in the enum and not in the doc
 * grows silently.
 */
final class SearchScopeDocTest extends TestCase
{
    private const string DOC = __DIR__.'/../../../docs/search-navigation.md';

    public function testEveryBuiltInScopeHasItsOwnRowInTheScopeTable(): void
    {
        // The TABLE, not the whole document. A first version asked whether the
        // label appeared anywhere in the prose, and renaming the table row
        // left it green — the word survives in the sentences around it. What
        // has to exist is the row that says what the scope SEARCHES.
        $rows = self::scopeTable();
        $this->assertCount(\count(ScopeKind::cases()), $rows, 'one row per built-in scope, no more and no fewer');

        $missing = [];
        foreach (ScopeKind::cases() as $kind) {
            if (\array_key_exists($kind->label(), $rows)) {
                continue;
            }

            $missing[] = $kind->label();
        }

        $this->assertSame([], $missing, 'search scopes the selector offers and the doc never describes');
        foreach ($rows as $label => $describes) {
            $this->assertNotSame('', \trim($describes), "the {$label} row names the scope and says nothing about it");
        }
    }

    /**
     * The `| Scope | Searches |` table, as label => description.
     *
     * @return array<string, string>
     */
    private static function scopeTable(): array
    {
        $lines = \explode("\n", (string) \file_get_contents(self::DOC));
        $rows = [];
        $inTable = false;
        foreach ($lines as $line) {
            if (\str_starts_with($line, '| Scope | Searches |')) {
                $inTable = true;
                continue;
            }
            if (!$inTable) {
                continue;
            }
            if (!\str_starts_with($line, '|')) {
                break;
            }
            $cells = \array_map(trim(...), \explode('|', \trim($line, characters: '|')));
            if (2 === \count($cells) && !\str_starts_with($cells[0], '---')) {
                $rows[$cells[0]] = $cells[1];
            }
        }

        return $rows;
    }

    /**
     * The fallback specifically, because it is the one that surprises: a scope
     * called Module that searches everything.
     */
    public function testTheModuleFallbackIsSpelledOut(): void
    {
        $doc = \mb_strtolower((string) \file_get_contents(self::DOC));

        $this->assertStringContainsString('composer.json', $doc, 'what makes a directory a module');
        $this->assertStringContainsString('or the whole project when there is none', $doc);
    }
}
