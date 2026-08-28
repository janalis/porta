<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Event\SettingsRowsStale;
use Porta\I18n\Translator;
use Porta\Settings\SettingsPage;
use Porta\Settings\SettingsPages;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Settings\SettingsSearchIndex;
use Porta\Settings\SettingsSection;

/** Finding a setting without knowing which of thirty-odd pages holds it. */
final class SettingsSearchIndexTest extends TestCase
{
    public function testASettingIsFoundByItsOwnLabel(): void
    {
        $hits = $this->index()->search('sticky', 10);

        $this->assertSame(['stickyScroll'], array_column($hits, 'key'));
        $this->assertSame('Interface', $hits[0]->page, 'a hit says which page it lives on');
        $this->assertSame('interface', $hits[0]->section, 'and carries the nav id that gets you there');
    }

    /**
     * A match inside the LABEL earns the primary-segment bonus, so a row whose
     * own name contains the query beats one that merely lives on a page whose
     * name does.
     */
    public function testAMatchInTheLabelOutranksTheSameMatchInThePageName(): void
    {
        // 'style' is in one row's label and in no page name.
        $hits = $this->index()->search('style', 10);

        $this->assertSame('indentStyle', $hits[0]->key);
    }

    /**
     * The boundary bonus, in the case that needs it: the same word appears in
     * one page's NAME and in another page's row LABEL. The row wins, because a
     * user typing a word is looking for the setting called that, not for a
     * neighbour of a page called that.
     */
    public function testARowNamedForTheQueryBeatsARowMerelyLivingOnAPageNamedForIt(): void
    {
        $index = new SettingsSearchIndex(new SettingsPages([
            $this->section('theme', 'Theme', ['unrelated' => 'Something else']),
            $this->section('general', 'General', ['themeName' => 'Theme name']),
        ]), new Translator());

        $hits = $index->search('theme', 10);

        $this->assertSame('themeName', $hits[0]->key, 'the row called Theme name, not the row on the Theme page');
    }

    /** Typing a page's name surfaces that page's rows — the haystack holds it. */
    public function testAPageNameSurfacesThatPagesRows(): void
    {
        $hits = $this->index()->search('interface', 10);

        $this->assertNotSame([], $hits);
        foreach ($hits as $hit) {
            $this->assertSame('interface', $hit->section);
        }
    }

    public function testAPageQualifiedQueryNarrowsToThatPage(): void
    {
        $hits = $this->index()->search('php ind', 10);

        $this->assertNotSame([], $hits);
        foreach ($hits as $hit) {
            $this->assertSame('lang:php', $hit->section);
        }
    }

    public function testAnEmptyQueryReturnsTheFirstSettingsUpToTheLimit(): void
    {
        $this->assertCount(2, $this->index()->search('   ', 2));
    }

    public function testNoMatchIsAnEmptyResultRatherThanEverything(): void
    {
        $this->assertSame([], $this->index()->search('zzzznope', 10));
    }

    /**
     * The row SET moved — a plugin was enabled, say. Values are not indexed, so
     * nothing else needs invalidating.
     */
    public function testTheRowSetIsRebuiltAfterSettingsRowsStale(): void
    {
        $pages = $this->mutableSection();
        $index = new SettingsSearchIndex(new SettingsPages([$pages]), new Translator());
        $this->assertSame([], $index->search('late', 10));

        $pages->addRow('lateArrival', 'Late arrival');
        $this->assertSame([], $index->search('late', 10), 'still the built index');

        $index->onSettingsRowsStale(new SettingsRowsStale());

        $this->assertSame(['lateArrival'], array_column($index->search('late', 10), 'key'));
    }

    /**
     * Labels are translated, so a locale switch invalidates all of them — and
     * the index notices by reading the locale, not by being told.
     */
    public function testTheIndexIsRebuiltAfterALocaleChange(): void
    {
        $pages = $this->mutableSection();
        $translator = new Translator();
        $index = new SettingsSearchIndex(new SettingsPages([$pages]), $translator);
        $index->search('', 1); // build

        $pages->addRow('lateArrival', 'Late arrival');
        $this->assertSame([], $index->search('late', 10), 'still the built index');

        $translator->setLocale('fr');

        $this->assertSame(['lateArrival'], array_column($index->search('late', 10), 'key'));
    }

    private function index(): SettingsSearchIndex
    {
        return new SettingsSearchIndex(new SettingsPages([
            $this->section('interface', 'Interface', [
                'stickyScroll' => 'Sticky scroll',
                'interfaceHint' => 'Interface hint',
            ]),
            $this->section('lang:php', 'PHP', [
                'indentStyle' => 'Indent style',
                'indentSize' => 'Indent size',
            ]),
            $this->section('lang:css', 'CSS', ['indentStyle' => 'Indent style']),
        ]), new Translator());
    }

    /** @param array<string, string> $rows key => label */
    private function section(string $id, string $label, array $rows): SettingsSection
    {
        return new class($id, $label, $rows) implements SettingsSection {
            /** @param array<string, string> $rows */
            public function __construct(
                private readonly string $id,
                private readonly string $label,
                private readonly array $rows,
            ) {}

            public function handles(string $section): bool
            {
                return $this->id === $section;
            }

            public function pages(): array
            {
                return [new SettingsPage($this->id, $this->label)];
            }

            public function rows(string $section): array
            {
                $out = [];
                foreach ($this->rows as $key => $label) {
                    $out[] = new SettingsRow(key: $key, label: $label, kind: SettingsRowKind::Bool, value: true, restart: false);
                }

                return $out;
            }

            public function change(string $section, string $key, bool|int|string $value): bool
            {
                return true;
            }

            public function reset(string $section, string $key): void {}
        };
    }

    /**
     * A section whose row set can move between searches, as a plugin toggle
     * moves it.
     *
     * Anonymous rather than a second named class in this file: it is this
     * suite's fixture and nothing else's, and a named one would be the only
     * file in the tree declaring two classes.
     */
    private function mutableSection(): SettingsSection
    {
        return new class implements SettingsSection {
            /** @var array<string, string> */
            private array $rows = ['theme' => 'Theme'];

            public function addRow(string $key, string $label): void
            {
                $this->rows[$key] = $label;
            }

            public function handles(string $section): bool
            {
                return 'general' === $section;
            }

            public function pages(): array
            {
                return [new SettingsPage('general', 'General')];
            }

            public function rows(string $section): array
            {
                $out = [];
                foreach ($this->rows as $key => $label) {
                    $out[] = new SettingsRow(key: $key, label: $label, kind: SettingsRowKind::Bool, value: true, restart: false);
                }

                return $out;
            }

            public function change(string $section, string $key, bool|int|string $value): bool
            {
                return true;
            }

            public function reset(string $section, string $key): void {}
        };
    }
}
