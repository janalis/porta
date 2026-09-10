<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Config\LanguageCatalog;

/**
 * The settings table in `docs/configuration.md` is the whole settings list.
 *
 * It was not. Eight of the fourteen keys a `.porta.json` accepts had no row —
 * `breadcrumbs`, `indentGuides`, `lineNumbers`, `methodSeparators`,
 * `searchIncludeVendor`, `showWhitespace`, `stickyScroll`, `tabBarPosition` —
 * and six of those appeared nowhere in `docs/` at all. The reference a user
 * consults before editing the file described under half of it.
 *
 * Both directions, because each catches a different way of being wrong: a new
 * setting shipping undocumented, and a row outliving the key it describes. The
 * second is not hypothetical either — the table carried `relativeLineNumbers`
 * as though current long after {@see IdeConfig::SUPERSEDED_BY} retired it in
 * favour of `lineNumbers`, so the one key it named that nothing reads was
 * documented while its replacement was not.
 */
final class ConfigurationDocTest extends TestCase
{
    private const string DOC = __DIR__.'/../../../docs/configuration.md';

    /**
     * Legacy keys the table may name, with the reason.
     *
     * @var array<string, string>
     */
    private const array MAY_LINGER = [
        'relativeLineNumbers' => 'superseded by lineNumbers, still read so an old .porta.json keeps working',
    ];

    public function testEverySettingKeyHasARowInTheTable(): void
    {
        $missing = [];
        foreach (array_keys(IdeConfig::DEFAULT_SETTINGS) as $key) {
            if (str_contains($this->table(), '| `'.$key.'` |')) {
                continue;
            }

            $missing[] = $key;
        }
        sort($missing);

        $this->assertSame([], $missing, 'a .porta.json accepts these and docs/configuration.md does not describe them');
    }

    public function testEveryRowInTheTableNamesAKeyThatStillExists(): void
    {
        $stale = [];
        $matches = [];
        preg_match_all('/^\| `([a-zA-Z]+)` \|/m', $this->table(), $matches);
        foreach ($matches[1] as $key) {
            if (\array_key_exists($key, IdeConfig::DEFAULT_SETTINGS) || \array_key_exists($key, self::MAY_LINGER)) {
                continue;
            }
            $stale[] = $key;
        }
        sort($stale);

        $this->assertSame([], $stale, 'the table describes settings nothing reads: remove the row, or list the key in MAY_LINGER with its reason');
    }

    /** A legacy key the table keeps must still be one the code knows about. */
    public function testALingeringKeyIsStillRecognisedByTheConfig(): void
    {
        foreach (array_keys(self::MAY_LINGER) as $key) {
            $this->assertArrayHasKey(
                $key,
                IdeConfig::SUPERSEDED_BY,
                $key.' is documented as a legacy key but the config no longer supersedes it — drop the row and the entry',
            );
        }
    }

    /**
     * Every language `languages.<id>` accepts is listed under that heading.
     *
     * It listed eleven of the thirteen: `xml` and `dotenv` were missing, both
     * real entries in {@see LanguageCatalog::LANGUAGES} — `xml` with its own
     * extension mapping, `dotenv` matched by filename.
     */
    public function testEveryLanguageIdIsListed(): void
    {
        $section = $this->languagesSection();

        $missing = [];
        foreach (array_keys(LanguageCatalog::LANGUAGES) as $id) {
            if (str_contains($section, '`'.$id.'`')) {
                continue;
            }

            $missing[] = $id;
        }
        sort($missing);

        $this->assertSame([], $missing, 'languages.<id> accepts these and the page does not list them');
    }

    /** The `languages.<id>` heading down to the next one. */
    private function languagesSection(): string
    {
        $doc = (string) file_get_contents(self::DOC);
        $start = strpos($doc, needle: '### `languages.<id>`');
        self::assertNotFalse($start, 'the languages section is where this test expects it');
        $end = strpos($doc, needle: "\n## ", offset: $start);

        return substr($doc, $start, false === $end ? null : $end - $start);
    }

    /**
     * The `settings` table alone: the page carries other tables (languages,
     * plugin scopes) whose rows are not setting keys.
     */
    private function table(): string
    {
        $doc = (string) file_get_contents(self::DOC);
        $start = strpos($doc, needle: '| Key | Default | Effect |');
        self::assertNotFalse($start, 'the settings table is where this test expects it');
        $end = strpos($doc, needle: "\n\n", offset: $start);

        return substr($doc, $start, false === $end ? null : $end - $start);
    }
}
