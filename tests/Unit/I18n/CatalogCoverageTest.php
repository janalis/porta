<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Porta\Search\Everywhere\SearchCategory;
use Porta\Ui\ScopeKind;
use Symfony\Component\Finder\Finder;

/**
 * The missing direction of {@see CatalogCompletenessTest}: that test guards
 * en -> six targets, but a trans('New string') literal absent from
 * messages.en.xlf failed SILENTLY to the identity fallback — nothing noticed
 * a UI string that never reached the catalogs. This gate collects every
 * statically-knowable UI string (literal ->trans() ids across src/ and the
 * bundled plugins, plus the two label tables translated at render time with
 * dynamic ids, which is exactly what defeats an extractor) and requires each
 * to exist as an en <source>.
 */
final class CatalogCoverageTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    /**
     * Ids the gate has found used in code but missing from messages.en.xlf —
     * standing debt, pinned in BOTH directions: a NEW untranslated literal
     * fails (it will be absent from this list), and translating one fails
     * until its entry here is removed, so the list can only shrink. Empty
     * since the first run's 65 findings were translated (63 real gaps, plus
     * two find-bar hints a scanner bug had mis-decoded — they were in the
     * catalog all along).
     */
    private const array KNOWN_GAPS = [];

    public function testEveryUiStringLiteralReachesTheEnglishCatalogOrTheShrinkList(): void
    {
        $ids = array_values(array_unique([...$this->transLiterals(), ...$this->labelLiterals(), ...$this->categoryLiterals()]));
        $missing = array_values(array_diff($ids, $this->englishSources()));
        sort($missing);

        $gaps = self::KNOWN_GAPS;
        sort($gaps);
        $this->assertSame(
            $gaps,
            $missing,
            'untranslated UI literals changed: a new one must be added to messages.en.xlf (and the six targets), a translated one must leave KNOWN_GAPS',
        );
    }

    /**
     * And the other direction: every catalogued string must appear SOMEWHERE
     * in the tree.
     *
     * The gate above asks whether each used literal is catalogued, which
     * cannot see an entry nothing uses. Reword a string and the old unit stays
     * behind — in all SEVEN files, translated, looking to a translator exactly
     * like a live one. Fourteen had accumulated that way, 98 units in total:
     * the editor's empty-state hint from before it read the keymap, both
     * find-bar hints from before they listed the regex and whole-word chords,
     * the settings footer from before it gained its find chord, and the Go to
     * File picker's title from when it carried a trailing colon.
     *
     * None of those is quoted here on purpose. This file is IN the corpus, so
     * a docblock naming a dead string verbatim makes it used again and the
     * gate green — which is exactly how the first version of this test passed
     * with the string it was written to catch planted back in the catalog.
     *
     * Deliberately a SUBSTRING search over the whole tree — tests and docs
     * included — rather than the precise scanner below. Precision is the wrong
     * instinct here: this direction only has to prove a string is used at all,
     * and a false positive DELETES a live translation. The loose read has no
     * false positives; the strict scanner has 143, because it cannot see menu
     * labels, action-catalog entries or plugin verbs.
     *
     * PHP escapes are decoded first. Without that, the plugin-loader warning
     * about a vendored package reads as dead: the source writes `Porta\\'s` and
     * the catalog stores `Porta\'s`. It was the one live string in the first
     * run\'s list, and it would have been deleted from seven files.
     */
    public function testEveryCataloguedStringIsUsedSomewhereInTheTree(): void
    {
        $corpus = $this->corpus();

        $unused = [];
        foreach ($this->englishSources() as $source) {
            // A pipe plural is one unit; either half is a legitimate spelling
            // for code to carry, and the catalog stores them joined.
            foreach ([$source, ...explode('|', $source)] as $needle) {
                if ('' !== $needle && str_contains($corpus, $needle)) {
                    continue 2;
                }
            }
            $unused[] = $source;
        }
        sort($unused);

        $this->assertSame([], $unused, "messages.en.xlf carries strings that appear nowhere in the tree — a reword left the old unit behind in all seven catalogs:\n  ".implode("\n  ", $unused));
    }

    /**
     * Every file a UI string could be written in, concatenated, with PHP\'s
     * string escapes decoded so the text compares against what the catalog
     * stores.
     */
    private function corpus(): string
    {
        $tree = new Finder()
            ->files()
            ->in(self::ROOT)
            ->name('/\\.(php|json|md)$/')
            ->exclude(['vendor', 'translations', 'coverage', 'node_modules'])
            ->ignoreDotFiles(false)
            ->ignoreVCS(true);

        $corpus = '';
        foreach ($tree as $file) {
            $corpus .= str_replace(["\\'", '\\"', '\\\\'], ["'", '"', '\\'], (string) file_get_contents($file->getPathname()))."\n";
        }

        return $corpus;
    }

    /**
     * Every literal string in the FIRST argument of a ->trans() call in src/
     * and the bundled plugins' src/. Dynamic ids cannot be collected
     * statically; the two big dynamic-id tables are covered by
     * {@see labelLiterals()} and the enum labels by {@see enumLabelLiterals()}.
     *
     * The whole argument, not just its first token, because a choice between
     * two ids is written as a ternary — `trans($single ? 'Overwrite' :
     * 'Overwrite all')` — and a scanner that required the argument to BE a
     * quoted string saw neither of them. Six such pairs were invisible to this
     * gate, all of them translated by luck rather than by the rule.
     *
     * Only the first argument, and only at its own bracket depth: the params
     * array is full of `'%file%'` placeholder keys that are not ids, and a
     * nested call's arguments belong to that call. And in a ternary, only the
     * BRANCHES: a condition like `'indentSize' === $key` decides with a string
     * that is an array key, not an id, and taking the whole argument reported
     * two of those as untranslated.
     *
     * @return list<string>
     */
    private function transLiterals(): array
    {
        $ids = [];
        foreach ($this->phpFiles() as $path) {
            $tokens = token_get_all((string) file_get_contents($path));
            $count = \count($tokens);
            for ($i = 1; $i < $count; ++$i) {
                $token = $tokens[$i];
                if (!(\is_array($token) && \T_STRING === $token[0] && 'trans' === $token[1])) {
                    continue;
                }
                $previous = $tokens[$i - 1];
                if (!(\is_array($previous) && \in_array($previous[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], strict: true))) {
                    continue;
                }
                $j = $this->nextMeaningful($tokens, $i + 1);
                if ('(' !== $tokens[$j]) {
                    continue;
                }

                foreach ($this->firstArgumentStrings($tokens, $j + 1) as $id) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * The quoted strings the first argument can EVALUATE to: every one at the
     * argument's own bracket depth, or — when it is a ternary — only those
     * after the `?`.
     *
     * The condition of a ternary is not an id. `'i' === $scope ? … : …` and
     * `'indentSize' === $key ? … : …` are both real, and both were reported as
     * untranslated by the first draft of this.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<string>
     */
    private function firstArgumentStrings(array $lexemes, int $start): array
    {
        $strings = [];
        $branchesFrom = null;
        $depth = 0;
        for ($i = $start, $count = \count($lexemes); $i < $count; ++$i) {
            $lexeme = $lexemes[$i];
            if (\is_array($lexeme)) {
                if (0 === $depth && \T_CONSTANT_ENCAPSED_STRING === $lexeme[0]) {
                    $strings[] = [$i, $this->unquote($lexeme[1])];
                }

                continue;
            }
            if (\in_array($lexeme, ['(', '[', '{'], strict: true)) {
                ++$depth;

                continue;
            }
            if (')' === $lexeme && 0 === $depth) {
                break; // the call closed: this was its only argument
            }
            if (\in_array($lexeme, [')', ']', '}'], strict: true)) {
                --$depth;

                continue;
            }
            if (',' === $lexeme && 0 === $depth) {
                break; // the params array starts here and holds no ids
            }
            if ('?' === $lexeme && 0 === $depth && null === $branchesFrom) {
                $branchesFrom = $i;
            }
        }

        return array_values(array_map(
            static fn (array $found): string => (string) $found[1],
            array_filter($strings, static fn (array $found): bool => null === $branchesFrom || $found[0] > $branchesFrom),
        ));
    }

    /**
     * The 'label' => '...' entries of the two catalogs-as-data translated at
     * render time with dynamic ids: the action catalog and the settings
     * descriptor table.
     *
     * @return list<string>
     */
    private function labelLiterals(): array
    {
        $labels = [];
        foreach (['/src/Config/ActionCatalog.php', '/src/Settings/SettingsController.php'] as $file) {
            $source = (string) file_get_contents(self::ROOT.$file);
            $matches = [];
            preg_match_all("/'label' => '((?:[^'\\\\]|\\\\.)*)'/", $source, $matches);
            foreach ($matches[1] as $label) {
                $labels[] = str_replace(['\\\\', "\\'"], ['\\', "'"], $label);
            }
        }

        return $labels;
    }

    /**
     * Enum labels reaching the screen through a dynamic argument —
     * `->trans($category->title())`, `->trans($kind->label())` — which no
     * scanner of literals can see.
     *
     * Both enums, because the pattern is the point: `SearchCategory` was
     * listed here and `ScopeKind` was not, so adding a sixth search scope would
     * have shipped its English label into six locales with every gate green.
     * A new enum whose cases reach `trans()` belongs here too.
     *
     * @return list<string>
     */
    private function categoryLiterals(): array
    {
        return [
            ...array_map(static fn (SearchCategory $c): string => $c->title(), SearchCategory::cases()),
            ...array_map(static fn (ScopeKind $k): string => $k->label(), ScopeKind::cases()),
        ];
    }

    /** @return list<string> the en catalog's <source> texts */
    private function englishSources(): array
    {
        $xml = simplexml_load_file(self::ROOT.'/translations/messages.en.xlf');
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

        return $sources;
    }

    /** @return list<string> every .php file under src/ and plugins/<name>/src/ */
    private function phpFiles(): array
    {
        $pluginSrc = glob(self::ROOT.'/plugins/*/src');
        $roots = [self::ROOT.'/src', ...(\is_array($pluginSrc) ? $pluginSrc : [])];
        $files = [];
        foreach ($roots as $root) {
            $tree = new Finder()
                ->files()
                ->in($root)
                ->name('/\.php$/')
                ->ignoreDotFiles(false)
                ->ignoreVCS(false);
            foreach ($tree as $file) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Decode a T_CONSTANT_ENCAPSED_STRING by its own quoting rules: inside
     * single quotes only \\ and \' are escapes (a '\b' is a literal backslash
     * and b — the find bar's hint really contains those two characters), where
     * stripcslashes would invent a backspace.
     */
    private function unquote(string $literal): string
    {
        $body = substr($literal, offset: 1, length: -1);

        return "'" === $literal[0] ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body) : stripcslashes($body);
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function nextMeaningful(array $tokens, int $index): int
    {
        $count = \count($tokens);
        while ($index < $count && \is_array($tokens[$index]) && \T_WHITESPACE === $tokens[$index][0]) {
            ++$index;
        }

        return $index;
    }
}
