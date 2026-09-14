<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Porta\I18n\LenientXliffLoader;
use Porta\I18n\Translator;

/**
 * Guards the shipped XLIFF catalogs: every English source id must exist in all
 * six target catalogs (no missing keys), each file must be well-formed XLIFF with
 * a source and target per unit, the target-language attribute must match, and
 * every file must LOAD — see {@see testEveryCatalogSurvivesTheLoaderThatShipsIt()},
 * which is the one of these the others cannot stand in for.
 */
final class CatalogCompletenessTest extends TestCase
{
    private const string DIR = __DIR__.'/../../../translations';

    /** @return list<string> the <source> texts of a catalog, in document order */
    private function sources(string $locale): array
    {
        $xml = \simplexml_load_file(self::DIR.'/messages.'.$locale.'.xlf');
        self::assertNotFalse($xml, "messages.{$locale}.xlf is not valid XML");
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
    public function testEveryCatalogSurvivesTheLoaderThatShipsIt(): void
    {
        $loader = new LenientXliffLoader();
        $english = \count($loader->load(self::DIR.'/messages.en.xlf', 'en')->all('messages'));
        $this->assertGreaterThan(500, $english, 'the English catalog loaded, and is the size a catalog should be');

        foreach (['fr', 'de', 'es', 'it', 'ja', 'he'] as $locale) {
            $loaded = \count($loader->load(self::DIR.'/messages.'.$locale.'.xlf', $locale)->all('messages'));
            $this->assertSame($english, $loaded, \sprintf('messages.%s.xlf loads %d of %d units — a schema error degrades it to English in silence', $locale, $loaded, $english));
        }
    }

    public function testEveryEnglishSourceExistsInAllSixTargetCatalogs(): void
    {
        $english = $this->sources('en');
        $this->assertNotEmpty($english, 'the English catalog carries the canonical source list');

        foreach (['fr', 'de', 'es', 'it', 'ja', 'he'] as $locale) {
            $have = $this->sources($locale);
            $missing = \array_values(\array_diff($english, $have));
            $this->assertSame([], $missing, "messages.{$locale}.xlf is missing sources: ".\implode(' | ', $missing));
        }
    }

    public function testEveryUnitHasANonEmptyTargetInEveryCatalog(): void
    {
        foreach (Translator::LOCALES as $locale) {
            $xml = \simplexml_load_file(self::DIR.'/messages.'.$locale.'.xlf');
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

    public function testTargetLanguageAttributeMatchesTheFile(): void
    {
        foreach (Translator::LOCALES as $locale) {
            $xml = \simplexml_load_file(self::DIR.'/messages.'.$locale.'.xlf');
            $this->assertNotFalse($xml);
            $files = $xml->xpath('//*[local-name()="file"]');
            $file = ($files ? $files : [])[0] ?? null;
            $this->assertNotNull($file);
            $this->assertSame($locale, (string) $file['target-language']);
        }
    }
}
