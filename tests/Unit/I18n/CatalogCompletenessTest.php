<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\I18n\LenientXliffLoader;
use Porta\I18n\Translator;
use Porta\Tests\Support\Catalogs;

/**
 * Guards the shipped XLIFF catalogs — core's and every plugin's own, one set
 * at a time: every English source id must exist in all six target catalogs
 * of its set (no missing keys), each file must be well-formed XLIFF with a
 * source and target per unit, the target-language attribute must match, and
 * every file must LOAD — see {@see testEveryCatalogSurvivesTheLoaderThatShipsIt()},
 * which is the one of these the others cannot stand in for.
 */
final class CatalogCompletenessTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function catalogSets(): iterable
    {
        foreach (Catalogs::sets() as $name => $dir) {
            yield $name => [$dir];
        }
    }

    /**
     * Every catalog must load through the loader the application uses.
     *
     * The other tests here parse the files with simplexml, which answers "is
     * this well-formed XML" — and that is NOT the question. XLIFF has an XSD,
     * Symfony's loader validates against it, and a file can be perfectly
     * well-formed while failing that: two `trans-unit` elements sharing an
     * `id` is well-formed XML and an XLIFF schema violation.
     *
     * What makes it worth a test of its own is the failure mode.
     * {@see LenientXliffLoader} degrades an invalid catalog to an EMPTY one on
     * purpose — English is the right thing to show when a translation file is
     * broken — so the whole locale silently reverts to English with nothing
     * logged and nothing thrown. Every gate here passed while that was true;
     * it was caught by a widget test that happened to measure a French label.
     *
     * Asserting the unit count, not merely that it loaded: an empty catalogue
     * IS what a broken file degrades to, so "it loaded" is satisfied by the
     * defect.
     */
    #[DataProvider('catalogSets')]
    public function testEveryCatalogSurvivesTheLoaderThatShipsIt(string $dir): void
    {
        $loader = new LenientXliffLoader();
        $english = \count($loader->load($dir.'/messages.en.xlf', 'en')->all('messages'));
        $this->assertSame(\count(Catalogs::sources($dir.'/messages.en.xlf')), $english, 'the English catalog loaded every unit it carries');
        $this->assertGreaterThan(0, $english, 'a catalog set carries at least one unit');

        foreach (self::targets() as $locale) {
            $loaded = \count($loader->load($dir.'/messages.'.$locale.'.xlf', $locale)->all('messages'));
            $this->assertSame($english, $loaded, \sprintf('messages.%s.xlf loads %d of %d units — a schema error degrades it to English in silence', $locale, $loaded, $english));
        }
    }

    #[DataProvider('catalogSets')]
    public function testEveryEnglishSourceExistsInAllSixTargetCatalogs(string $dir): void
    {
        $english = Catalogs::sources($dir.'/messages.en.xlf');
        $this->assertNotEmpty($english, 'the English catalog carries the canonical source list');

        foreach (self::targets() as $locale) {
            $have = Catalogs::sources($dir.'/messages.'.$locale.'.xlf');
            $missing = \array_values(\array_diff($english, $have));
            $this->assertSame([], $missing, "messages.{$locale}.xlf is missing sources: ".\implode(' | ', $missing));
        }
    }

    #[DataProvider('catalogSets')]
    public function testEveryUnitHasANonEmptyTargetInEveryCatalog(string $dir): void
    {
        foreach (Translator::LOCALES as $locale) {
            $xml = \simplexml_load_file($dir.'/messages.'.$locale.'.xlf');
            $this->assertNotFalse($xml);
            $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');

            $units = $xml->xpath('//x:trans-unit');
            foreach ($units ? $units : [] as $unit) {
                $unit->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
                $target = $unit->xpath('x:target');
                $target = $target ? $target : [];
                $this->assertNotSame([], $target, "a trans-unit in messages.{$locale}.xlf has no <target>");
                $this->assertNotSame('', (string) $target[0], "a trans-unit in messages.{$locale}.xlf has an empty <target>");
            }
        }
    }

    #[DataProvider('catalogSets')]
    public function testTargetLanguageAttributeMatchesTheFile(string $dir): void
    {
        foreach (Translator::LOCALES as $locale) {
            $xml = \simplexml_load_file($dir.'/messages.'.$locale.'.xlf');
            $this->assertNotFalse($xml);
            $files = $xml->xpath('//*[local-name()="file"]');
            $file = ($files ? $files : [])[0] ?? null;
            $this->assertInstanceOf(\SimpleXMLElement::class, $file);
            $this->assertSame($locale, (string) $file['target-language']);
        }
    }

    /**
     * No source may live in two sets. A plugin unit repeating one of core's
     * would be dead weight at best — core's catalog loads last and wins — and
     * two plugins sharing one would each carry a copy for a translator to
     * keep in step: a string with two users belongs in core.
     */
    public function testNoSourceIsCataloguedInTwoSets(): void
    {
        $owners = [];
        foreach (\array_keys(Catalogs::sets()) as $set) {
            foreach (Catalogs::englishSources($set) as $source) {
                $owners[$source][] = $set;
            }
        }
        $shared = \array_filter($owners, static fn (array $sets): bool => \count($sets) > 1);

        $this->assertSame([], \array_map(static fn (array $sets): string => \implode(', ', $sets), $shared), 'a source catalogued twice: a string two sets use belongs in core alone');
    }

    /** @return list<string> every locale but English */
    private static function targets(): array
    {
        return \array_values(\array_diff(Translator::LOCALES, ['en']));
    }
}
