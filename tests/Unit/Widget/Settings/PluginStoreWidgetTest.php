<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Event\SettingsSectionRequested;
use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\InstallScope;
use Porta\Plugin\Store\Art;
use Porta\Plugin\Store\PluginUpdate;
use Porta\Plugin\Store\StoreAction;
use Porta\Plugin\Store\StoreCategory;
use Porta\Plugin\Store\StoreEntry;
use Porta\Plugin\Store\StoreRow;
use Porta\Settings\InstalledPlugin;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Tests\Support\PixelGraphics;
use Porta\Tests\Support\PngFixture;
use Porta\Ui\ScreenDensity;
use Porta\Widget\Settings\PluginStoreWidget;
use Porta\Widget\Settings\StorePage;
use Porta\Widget\Settings\StoreShelf;
use Porta\Widget\Settings\StoreTab;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/** The store as the App Store: its four tabs, its grid of tiles and its pages, driven from the keyboard and the pointer. */
final class PluginStoreWidgetTest extends TestCase
{
    private const string CHANGELOG = 'https://raw.test/CHANGELOG.md';

    private const string README = 'https://raw.test/README.md';

    private const string SHOT = 'https://img.test/1.png';

    private const string SHOT_2 = 'https://img.test/2.png';

    private const string SHOT_3 = 'https://img.test/3.png';

    /** @var list<string> */
    private array $pressed = [];

    /** @var list<string> */
    private array $crumbs = [];

    /** @var list<string> each address the page asked for, `!` marking a picture */
    private array $asked = [];

    /** @var list<array{string, string}> */
    private array $changes = [];

    /** @var list<array{string, int, int, int, int}> each list the scope select asked for: its key, row, column, row above, width */
    private array $choices = [];

    private int $updateAll = 0;

    private int $exits = 0;

    private int $focusNext = 0;

    /** @var list<string> each link a page followed */
    private array $links = [];

    /** @var list<array{string, ?string}> */
    private array $sections = [];

    private ScreenDensity $density;

    private PluginStoreWidget $widget;

    protected function setUp(): void
    {
        $events = new EventDispatcher();
        $events->addListener(SettingsSectionRequested::class, function (SettingsSectionRequested $event): void {
            $this->sections[] = [$event->section, $event->row];
        });
        $this->density = new ScreenDensity();
        $this->widget = new PluginStoreWidget(new Translator('en'), density: $this->density, events: $events);
        $this->widget
            ->onPress(function (StoreEntry $entry): void {
                $this->pressed[] = $entry->package;
            })
            ->onCrumb(function (string $id): void {
                $this->crumbs[] = $id;
            })
            // @mago-expect lint:no-boolean-flag-parameter -- the widget's own callback shape: a text, or a picture.
            ->onAsset(function (string $url, bool $image): void {
                $this->asked[] = ($image ? '!' : '').$url;
            })
            ->onUpdateAll(function (): void {
                ++$this->updateAll;
            })
            ->onChange(function (string $key, string $value): void {
                $this->changes[] = [$key, $value];
            })
            ->onOpenChoice(function (SettingsRow $row, int $relRow, int $relCol, int $relAbove, int $width): void {
                $this->choices[] = [$row->key, $relRow, $relCol, $relAbove, $width];
            })
            ->onExit(function (): void {
                ++$this->exits;
            })
            ->onFocusNext(function (): void {
                ++$this->focusNext;
            })
            ->onLink(function (string $url): void {
                $this->links[] = $url;
            });
    }

    /** @param list<string> $screenshots */
    private static function entry(
        string $name,
        StoreCategory $category = StoreCategory::Linters,
        bool $verified = false,
        bool $featured = false,
        array $screenshots = [],
    ): StoreEntry {
        return new StoreEntry(
            'acme/porta-'.$name,
            $name,
            \ucfirst($name),
            'Ada',
            'Does '.$name,
            $category,
            'v1.0.0',
            time: 1_800_000_000,
            downloads: 1234,
            screenshots: $screenshots,
            changelog: self::CHANGELOG,
            readme: self::README,
            source: 'https://github.com/acme/porta-'.$name,
            license: 'MIT',
            verified: $verified,
            featured: $featured,
            history: [
                ['v1.0.0', 1_800_000_000],
                ['v0.9.0', 0],
            ],
        );
    }

    /** The shelf: rector (verified, Get), phpunit (featured, testing, Update), todo (Working). */
    private function shelf(): void
    {
        $this->widget->setStore(
            [
                new StoreRow(self::entry('rector', verified: true, screenshots: [self::SHOT, self::SHOT_2, self::SHOT_3]), StoreAction::Get),
                new StoreRow(
                    self::entry('phpunit', StoreCategory::Testing, featured: true),
                    StoreAction::Update,
                    'v0.9.0',
                    new PluginUpdate('phpunit', InstallScope::Global, 'acme/porta-phpunit', 'v0.9.0', 'v1.0.0'),
                ),
                new StoreRow(self::entry('todo', StoreCategory::Productivity), StoreAction::Working),
            ],
            false,
            1,
        );
    }

    /** Five linters: more than two rows of a two-column grid hold. */
    private function linters(): void
    {
        $rows = [];
        foreach (['alpha', 'bravo', 'charlie', 'delta', 'echo'] as $name) {
            $rows[] = new StoreRow(self::entry($name), StoreAction::Get);
        }
        $this->widget->setStore($rows, false, 0);
    }

    private function installed(): void
    {
        $this->shelf();
        $scope = new SettingsRow(
            key: 'configScope',
            label: 'Save changes to',
            kind: SettingsRowKind::Choice,
            value: 'project',
            restart: false,
            options: ['project', 'global'],
            optionLabels: ['project' => '.porta.json', 'global' => '~/.porta/config.json'],
        );
        $this->widget->setInstalled([new InstalledPlugin('git', 'Git history', 'on · built-in'), new InstalledPlugin('phpunit', '', 'on · global')], $scope);
        $this->widget->showTab(StoreTab::Installed);
    }

    /** The screen as drawn, its escapes kept: what hover changes is a style. */
    private function raw(int $columns = 100, int $rows = 40): string
    {
        return \implode("\n", $this->widget->render(new RenderContext($columns, $rows)));
    }

    private function screen(int $columns = 100, int $rows = 40): string
    {
        return AnsiUtils::stripAnsiCodes(\implode("\n", $this->widget->render(new RenderContext($columns, $rows))));
    }

    /**
     * Where $needle is first drawn: its row and column.
     *
     * @return array{int, int}
     */
    private function at(string $needle, int $columns = 100, int $rows = 40): array
    {
        foreach (\explode("\n", $this->screen($columns, $rows)) as $row => $line) {
            $col = \mb_strpos($line, $needle);
            if (false !== $col) {
                return [$row, $col];
            }
        }
        $this->fail('Not drawn: '.$needle);
    }

    private function click(string $needle, int $columns = 100, int $rows = 40): void
    {
        [$row, $col] = $this->at($needle, $columns, $rows);
        $this->widget->handleClick($row, $col);
        $this->screen($columns, $rows);
    }

    private function key(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->widget->handleInput($key);
            $this->screen();
        }
    }

    /** A 4×4 PNG, for the screenshot the page draws. */
    private static function png(): string
    {
        $image = \imagecreatetruecolor(4, height: 4);
        \ob_start();
        \imagepng($image);

        return (string) \ob_get_clean();
    }

    public function testDiscoverIsTheFrontPageFeaturedFirstThenAShelfPerCategory(): void
    {
        $this->shelf();
        $screen = $this->screen();

        $this->assertStringContainsString('Settings › Plugins ', $screen);
        $this->assertMatchesRegularExpression('/Discover\s+Categories\s+Updates \(1\)\s+Installed/u', $screen);
        $this->assertStringContainsString('╭ Featured ─', $screen);
        $this->assertMatchesRegularExpression('/│ Re │ Does rector\s+│  Get  │/u', $screen);
        $this->assertMatchesRegularExpression('/Rector  ✓ Verified\s+╭/u', $screen);
        $this->assertStringContainsString('Ada · Testing · v0.9.0 → v1.0.0 · 2027-01-15 · 1,234 downloads', $screen);
        $this->assertStringContainsString('Phpunit  Featured', $screen);
        $this->assertStringContainsString('Working…', $screen);
        $this->assertLessThan(\strpos($screen, needle: 'Linters & formatters'), \strpos($screen, needle: 'Featured ─'), 'the banner first');
        $this->assertLessThan(\strpos($screen, needle: 'Productivity'), \strpos($screen, needle: 'Testing'), 'the shelves in category order');
        $this->assertStringNotContainsString('See all', $screen, 'every shelf shows all it has');
        $this->assertStringNotContainsString('Languages', $screen, 'an empty category has no shelf');
    }

    public function testAShelfWithMoreThanTwoRowsHoldSaysSeeAllWhichOpensTheWholeCategory(): void
    {
        $this->linters();
        $screen = $this->screen();
        $this->assertStringContainsString('See all ›', $screen);
        $this->assertMatchesRegularExpression('/Alpha.*Bravo/u', $screen, 'two to a row');
        $this->assertStringNotContainsString('Echo', $screen, 'two rows of two');

        $this->click('See all');
        $screen = $this->screen();
        $this->assertStringContainsString('Settings › Plugins › Linters & formatters', $screen);
        $this->assertStringContainsString('Echo', $screen);
        $this->assertStringNotContainsString('See all', $screen);

        $this->key("\x1b");
        $this->assertSame(0, $this->exits, 'Esc leaves the category, not the view');
        $this->assertStringNotContainsString('Echo', $this->screen());

        $this->click('See all');
        $this->click('Plugins');
        $this->assertStringNotContainsString('Echo', $this->screen(), 'the Plugins crumb is the way back to the front page');
        $this->assertSame([], $this->crumbs);

        $this->click('See all');
        $this->click('Settings');
        $this->assertSame(['general'], $this->crumbs, 'in a category, another crumb is still the view\'s');
        $this->key(']');
        $this->assertStringNotContainsString('Linters & formatters', \explode("\n", $this->screen())[0], 'another tab leaves the category, its crumb with it');
    }

    public function testANarrowPaneHasOneTileToARowAndACompactScreenOneRowControls(): void
    {
        $this->linters();
        $this->assertDoesNotMatchRegularExpression('/Alpha.*Bravo/u', $this->screen(60), 'one to a row');
        $this->assertStringContainsString('Bravo', $this->screen(60));

        $this->density->observe(24);
        $screen = $this->screen(100, 24);
        $this->assertMatchesRegularExpression('/\[Al\] Alpha\s+│  Get  │/u', $screen);
        $this->assertStringContainsString('Does alpha · Ada · Linters & formatters', $screen);
        $this->assertStringContainsString('Discover │ Categories │', $screen, 'the compact strip');
        $this->assertMatchesRegularExpression('/^─{100}$/mu', $screen, 'under its own rule');
        $this->assertStringNotContainsString('┌────┐', $screen);

        $this->shelf();
        $screen = $this->screen(100, 24);
        $this->assertMatchesRegularExpression('/^ Featured\s*$/mu', $screen, 'compact, the banner is a plain shelf');
        $this->assertStringNotContainsString('╭ Featured', $screen);
    }

    public function testAStoreWiredToNothingStillDrawsAndAnswersTheKeys(): void
    {
        $bare = new PluginStoreWidget(new Translator('en'));
        $bare->setStore([new StoreRow(self::entry('rector', screenshots: [self::SHOT]), StoreAction::Get)], false, 0);
        $bare->setInstalled([], new SettingsRow(key: 'configScope', label: 'Save changes to', kind: SettingsRowKind::Choice, value: 'project', restart: false, options: ['project']));
        $bare->render(new RenderContext(100, 40));
        foreach (["\r", ' ', "\x1b", ']', ']', ']', 'U'] as $key) {
            $bare->handleInput($key);
            $bare->render(new RenderContext(100, 40));
        }
        $bare->handleInput("\r"); // the scope's list, with no one to show it
        $bare->handleInput("\x1b[C");
        $bare->handleInput("\r");
        $bare->handleInput('x');
        $bare->handleInput("\r"); // nothing to hand the source to
        $bare->setInstalled([new InstalledPlugin('git', '', 'on')], null);
        $bare->render(new RenderContext(100, 40));
        $bare->handleInput("\x1b[B");
        $bare->render(new RenderContext(100, 40));
        $bare->handleInput(' '); // no one to open its page
        $this->assertStringContainsString('Install from URL or path', AnsiUtils::stripAnsiCodes(\implode("\n", $bare->render(new RenderContext(100, 40)))));
    }

    public function testTheArrowsWalkTheGridAndSpacePressesTheSelectedButton(): void
    {
        $this->linters();
        $this->screen();
        $this->key(' ');
        $this->assertSame(['acme/porta-alpha'], $this->pressed, 'the first tile starts selected');
        $this->key("\x1b[C", ' '); // right
        $this->key("\x1b[B", ' '); // down: delta, under bravo
        $this->key("\x1b[D", ' '); // left: charlie
        $this->key("\x1b[A", ' '); // up: alpha
        $this->key("\x1b[D", ' '); // nothing further left
        $this->assertSame(['acme/porta-alpha', 'acme/porta-bravo', 'acme/porta-delta', 'acme/porta-charlie', 'acme/porta-alpha', 'acme/porta-alpha'], $this->pressed);
        $this->key("\x1b[A", "\r"); // above the left column: no See all stands there, so nothing; Enter opens alpha
        $this->assertSame('acme/porta-alpha', $this->widget->detail());
        $this->key("\x7f");
        $this->key("\x1b[C", "\x1b[A", "\r"); // above the right column: See all; Enter opens it
        $this->assertStringContainsString('Echo', $this->screen());
        $this->key("\x1b[6~", ' '); // page down: the last row
        $this->assertSame('acme/porta-echo', \end($this->pressed));
        $this->key("\x1b[5~", ' ');
        $this->assertSame('acme/porta-alpha', \end($this->pressed));

        $this->key('U', "\t", '1', "\x7f");
        $this->assertSame(0, $this->exits);
        $this->assertStringNotContainsString('Echo', $this->screen(), 'Backspace left the category');
        $this->key("\x7f", "\x1b");
        $this->assertSame(1, $this->updateAll);
        $this->assertSame(1, $this->focusNext);
        $this->assertSame([['general', null]], $this->sections);
        $this->assertSame(1, $this->exits, 'on the front page, Esc leaves the view');
    }

    public function testTheSelectionScrollsIntoViewAndTheWheelScrollsWithoutMovingIt(): void
    {
        $this->shelf();
        $this->assertStringContainsString('╭ Featured', $this->screen(100, 14));
        $this->key("\x1b[B", "\x1b[B", "\x1b[B");
        $screen = $this->screen(100, 14);
        $this->assertStringContainsString('Productivity', $screen, 'the last shelf, its heading with it');
        $this->assertStringNotContainsString('╭ Featured', $screen);

        $this->widget->scrollBy(-20);
        $this->assertStringContainsString('╭ Featured', $this->screen(100, 14));
        $this->widget->handleInput(' ');
        $this->assertSame([], $this->pressed, 'todo, still selected, is Working');
        $this->widget->scrollBy(3);
        $this->assertStringNotContainsString('╭ Featured', $this->screen(100, 14));

        // Scrolled, a click and the pointer still find what is drawn under them.
        [$row] = $this->at('Does rector', 100, 14);
        $get = (int) \mb_strpos(\explode("\n", $this->screen(100, 14))[$row], needle: 'Get');
        $this->assertTrue($this->widget->hoverAt($row, $get));
        $this->assertStringContainsString("\e[4m", $this->raw(100, 14), 'the button drawn under the pointer underlines');
        [$row, $col] = $this->at('Does rector', 100, 14);
        $this->widget->handleClick($row, $col);
        $this->widget->handleClick($row, $col);
        $this->assertSame('acme/porta-rector', $this->widget->detail(), 'the tile drawn there, not the one that row held unscrolled');
        $this->widget->closeDetail();

        $this->widget->scrollBy(10);
        $this->key(']', '[');
        $this->assertStringContainsString('╭ Featured', $this->screen(100, 14), 'a tab is shown from its top');
    }

    public function testCategoriesListsEveryCategoryAndOpensOne(): void
    {
        $this->shelf();
        $this->key(']');
        $this->assertSame(StoreTab::Categories, $this->widget->tab());
        $screen = $this->screen();
        $this->assertMatchesRegularExpression('/Languages\s+0 ›/u', $screen);
        $this->assertMatchesRegularExpression('/Linters & formatters\s+1 ›/u', $screen);

        $this->key("\r"); // Languages
        $this->assertStringContainsString('No plugin matches', $this->screen());
        $this->key("\x7f", "\x1b[B", "\r"); // back on Languages; down to Linters
        $screen = $this->screen();
        $this->assertStringContainsString('Settings › Plugins › Linters & formatters', $screen);
        $this->assertStringContainsString('Rector', $screen);
        $this->key("\x7f", "\r");
        $this->assertStringContainsString('Settings › Plugins › Linters & formatters', $this->screen(), 'back on the category just left, not the first');

        // A page down is a screen of the list, not its end.
        $this->widget->showTab(StoreTab::Categories);
        $this->screen(100, 14);
        $this->widget->handleInput("\x1b[6~");
        $this->widget->handleInput("\r");
        $trail = \explode("\n", $this->screen(100, 14))[0];
        $this->assertDoesNotMatchRegularExpression('/Languages|Other/u', $trail, 'neither the first category nor the last: '.$trail);
        $this->assertStringContainsString('Settings › Plugins › ', $trail);

        $this->key('[', '[');
        $this->assertSame(StoreTab::Installed, $this->widget->tab(), 'round from the first tab to the last');
    }

    public function testUpdatesListsWhatHasOneAndUpdatesAll(): void
    {
        $this->shelf();
        $this->key('u');
        $screen = $this->screen();
        $this->assertStringContainsString('Update all', $screen);
        $this->assertStringContainsString('Phpunit', $screen);
        $this->assertStringNotContainsString('Rector', $screen);
        $this->key(' ', "\r");
        $this->assertSame(2, $this->updateAll, 'the button starts selected');
        $this->click('Update all');
        $this->assertSame(3, $this->updateAll);
        $this->key("\x1b[B", ' ');
        $this->assertSame(['acme/porta-phpunit'], $this->pressed);

        $this->widget->setStore([new StoreRow(self::entry('rector'), StoreAction::Get)], false, 0);
        $this->assertStringContainsString('Every plugin is up to date', $this->screen());
    }

    public function testInstalledHoldsTheScopeTheInstallFieldAndEveryInstalledPlugin(): void
    {
        $this->installed();
        $screen = $this->screen();
        $this->assertStringContainsString('Save changes to', $screen);
        $this->assertMatchesRegularExpression('/\.porta\.json\s+▾/u', $screen);
        $this->assertStringContainsString('Install from URL or path', $screen);
        $this->assertMatchesRegularExpression('/git\s+╭/u', $screen);
        $this->assertStringContainsString('on · built-in', $screen);
        $this->assertStringContainsString('on · global · v0.9.0 → v1.0.0', $this->screen(140), 'the store knows phpunit');
        $this->assertMatchesRegularExpression('/│  Update  │/u', $screen);

        $this->key("\r"); // the scope select
        $this->assertSame('configScope', $this->choices[0][0] ?? null);
        [$row, $col] = $this->at('Save changes to');
        $this->assertSame([$row + 3, $col - 2, $row], [$this->choices[0][1], $this->choices[0][2], $this->choices[0][3]], 'the list hangs under the box');

        $this->key("\x1b[C", 'x'); // the install field: shut, it takes nothing
        $this->assertSame([], $this->changes);
        $this->key("\r", ' ', 'a', 'b', '/', 'c', ']', "\x7f", ' ', "\r");
        $this->assertSame([['pluginInstall', 'ab/c']], $this->changes, 'open, every key is the field\'s; the source is trimmed');
        $this->key('u', ']');
        $this->assertSame(StoreTab::Installed, $this->widget->tab(), 'installing shut the field: the keys are the store\'s again');
        $this->screen();
        $this->key("\x1b[C");
        $this->key("\r", "\r");
        $this->assertCount(1, $this->changes, 'nothing typed, nothing installed');
        $this->key("\r", 'z', "\x1b");
        $this->assertSame(0, $this->exits, 'Esc shuts the field');
        $this->key('u', ']');
        $this->assertSame(StoreTab::Installed, $this->widget->tab(), 'shut, the field takes no more keys');
        $this->key("\x1b[C");
        $this->key("\r", "\x7f", 'q', "\r");
        $this->assertSame(
            [
                ['pluginInstall', 'ab/c'],
                ['pluginInstall', 'q'],
            ],
            $this->changes,
            'Backspace on an empty field keeps it open',
        );
        $this->screen();
        $this->key("\x1b[C");
        $this->key("\r", "\x7f", "\x7f", 'q', "\x1b[B", "\x1b[D", ' '); // the arrows shut it and move on: phpunit, under it, then git
        $this->assertSame([['plugin:git', 'pluginEnabled']], $this->sections);
        $this->assertCount(2, $this->changes);
        $this->key("\r");
        $this->assertSame(
            [
                ['plugin:git', 'pluginEnabled'],
                ['plugin:git', 'pluginEnabled'],
            ],
            $this->sections,
            'git is not in the store: Enter opens its settings',
        );

        $this->key("\x1b[C", ' ');
        $this->assertSame(['acme/porta-phpunit'], $this->pressed, 'Update');
        $this->key("\r");
        $this->assertSame('acme/porta-phpunit', $this->widget->detail(), 'one the store knows has its page');
    }

    public function testOnANarrowPaneTheScopeStandsOverTheInstallField(): void
    {
        $this->installed();
        $screen = $this->screen(56);
        $this->assertMatchesRegularExpression('/▾ │\s*\n\s*╰─+╯\s*\n\s*╭ Install from URL or path/u', $screen, 'the field under the select, not squeezed beside it');
        $this->assertStringContainsString('.porta.json          ▾', $screen, 'the select reads whole');
        $this->widget->handleInput("\x1b[B"); // from the select down to the field
        $this->screen(56);
        foreach (["\r", 'x', "\r"] as $key) {
            $this->widget->handleInput($key);
            $this->screen(56);
        }
        $this->assertSame([['pluginInstall', 'x']], $this->changes);
    }

    public function testASearchHitOpensTheInstalledTabOnItsControl(): void
    {
        $this->installed();
        $this->widget->showTab(StoreTab::Installed, 'install');
        $this->screen();
        $this->key("\r", 'x', "\r");
        $this->assertSame([['pluginInstall', 'x']], $this->changes);
    }

    public function testAnInstalledPluginTheStoreDoesNotListAsItsCopyIsNotLookedUp(): void
    {
        // A takeover offered beside the bundled plugin is Get: not the copy installed.
        $this->widget->setStore([new StoreRow(self::entry('git'), StoreAction::Get)], false, 0);
        $this->widget->setInstalled([new InstalledPlugin('git', '', 'on · built-in')], null);
        $this->widget->showTab(StoreTab::Installed);
        $screen = $this->screen();
        $this->assertStringNotContainsString('Save changes to', $screen, 'no scope to choose');
        $this->assertStringNotContainsString('No plugin is installed', $screen);
        $this->widget->setInstalled([], null);
        $this->assertStringContainsString('No plugin is installed', $this->screen());
        $this->widget->setInstalled([new InstalledPlugin('git', '', 'on · built-in')], null);
        $this->assertStringNotContainsString('Does git', $screen);
        $this->screen();
        $this->key("\x1b[B", ' ');
        $this->assertSame([['plugin:git', 'pluginEnabled']], $this->sections);
    }

    public function testTheSearchNarrowsTheShelfIntoResults(): void
    {
        $this->shelf();
        $this->key('/', 'r', 'e', 'c', "\x7f");
        $screen = $this->screen();
        $this->assertStringContainsString('╭ Search', $screen, 'the search shows its field');
        $this->assertStringContainsString('Results for "re"', $screen);
        $this->assertStringContainsString('Rector', $screen);
        $this->assertStringNotContainsString('Todo', $screen);
        $this->key("\x1b"); // leaves the search, not the view
        $this->assertSame(0, $this->exits);
        $this->assertStringContainsString('Todo', $this->screen());

        $this->installed();
        $this->key('/', 'p', 'h', 'p');
        $this->assertStringContainsString('on · global', $this->screen(), 'a name matches, not only a description');
        $this->key("\x1b", '/', 'g', 'i');
        $screen = $this->screen();
        $this->assertStringContainsString('Git history', $screen);
        $this->assertStringNotContainsString('on · global', $screen);
        $this->click('Install from URL');
        $this->click('Install from URL');
        $this->key('a');
        $this->assertStringNotContainsString('╭ Search', $this->screen(), 'opening the field closes the search');
        $this->assertStringContainsString('Git history', $this->screen(), 'its query kept');
    }

    public function testWhatAnEmptyStoreSays(): void
    {
        $this->widget->setStore([], true, 0);
        $this->assertStringContainsString('Asking Packagist…', $this->screen());
        $this->widget->setStore([], false, 0);
        $this->assertStringContainsString('The store is empty: Packagist could not be reached', $this->screen());
        $this->key(' ', "\r", "\x1b[B");
        $this->assertNull($this->widget->detail(), 'nothing to select');
        $this->shelf();
        $this->key('/', 'z', 'z');
        $this->assertStringContainsString('No plugin matches', $this->screen());
    }

    public function testThePointerSelectsOpensPressesAndSwitches(): void
    {
        $this->shelf();
        $this->screen();

        $this->click('Does rector'); // selects
        $this->assertSame([], $this->pressed);
        $this->assertNull($this->widget->detail());
        [$row, $col] = $this->at('Does rector');
        $this->assertTrue($this->widget->hoverAt($row, $col));
        $this->assertFalse($this->widget->hoverAt($row, $col));
        $this->click('Does rector'); // the selected one: its page
        $this->assertSame('acme/porta-rector', $this->widget->detail());
        $this->widget->closeDetail();

        [$row] = $this->at('Does rector');
        $col = \mb_strpos(\explode("\n", $this->screen())[$row], needle: 'Get');
        $this->assertStringNotContainsString("\e[4m", $this->raw(), 'nothing underlined at rest');
        $this->assertTrue($this->widget->hoverAt($row, (int) $col), 'onto its button');
        $this->assertStringContainsString("\e[4m", $this->raw(), 'the button under the pointer underlines its caption');
        $this->widget->handleClick($row, (int) $col);
        $this->assertSame(['acme/porta-rector'], $this->pressed);
        $this->assertTrue($this->widget->clearHover());
        $this->assertFalse($this->widget->clearHover());

        $this->widget->handleClick(39, 5); // past everything
        [$stripRow, $updatesCol] = $this->at('Updates (1)');
        $this->widget->handleClick($stripRow - 1, $updatesCol); // over the tab, on the strip's upper rule
        $this->assertSame(StoreTab::Discover, $this->widget->tab());
        $this->widget->handleClick($stripRow, $updatesCol - 1); // the column before its label: still its pill
        $this->assertSame(StoreTab::Updates, $this->widget->tab());
        $this->widget->showTab(StoreTab::Discover);
        $this->click('Updates (1)');
        $this->assertSame(StoreTab::Updates, $this->widget->tab());
        $this->widget->handleClick(2, 99); // past every tab
        $this->assertSame(StoreTab::Updates, $this->widget->tab());

        $this->widget->handleClick(0, 2); // Settings
        $this->assertSame(['general'], $this->crumbs);
        $this->assertTrue($this->widget->hoverCrumb(2));
        $this->assertFalse($this->widget->hoverCrumb(2));
    }

    /** A screenshot that answered nothing is asked for again when the page opens next: a converter may be there by then. */
    public function testWhatAnsweredNothingIsAskedAgainOnTheNextVisit(): void
    {
        $this->shelf();
        $this->screen();
        $this->key("\x1b[B", "\r"); // rector's page
        $this->screen(120, 60);
        $this->widget->setAsset(self::CHANGELOG, null);
        $this->widget->setAsset(self::README, "# Rector\n");
        $this->widget->setAsset(self::SHOT, self::png());
        $this->widget->setAsset(self::SHOT_2, null);
        $this->screen(120, 60);
        $this->assertCount(5, $this->asked);

        $this->widget->closeDetail();
        $this->asked = [];
        $this->screen();
        $this->key("\r");
        $this->assertSame('acme/porta-rector', $this->widget->detail());
        $this->screen(120, 60);
        $this->assertSame([self::CHANGELOG, '!'.self::SHOT_2], $this->asked, 'only what answered nothing, and what never answered stays asked');
    }

    public function testThePageShowsWhatTheStoreKnowsAndAsksForTheRest(): void
    {
        $this->shelf();
        $this->screen();
        $this->key("\x1b[B", "\r"); // rector's page
        $this->assertSame('acme/porta-rector', $this->widget->detail());
        $this->assertSame([self::CHANGELOG, self::README, '!'.self::SHOT, '!'.self::SHOT_2, '!'.self::SHOT_3], $this->asked, 'every screenshot it has');

        $screen = $this->screen(120, 60);
        $this->assertStringContainsString('Settings › Plugins › Rector', $screen);
        $this->assertStringContainsString('Esc back · ⏎ get · ←/→ screenshots', $screen);
        $this->assertMatchesRegularExpression('/│ Re │ Does rector\s+│  Get  │/u', $screen);
        $this->assertStringContainsString('by Ada', $screen);
        $this->assertMatchesRegularExpression('/DOWNLOADS\s+│\s+CATEGORY\s+│\s+DEVELOPER\s+│\s+VERSION\s+│\s+LICENSE/u', $screen);
        $this->assertMatchesRegularExpression('/1,234\s+│\s+Linters & formatters\s+│\s+Ada\s+│\s+v1\.0\.0\s+│\s+MIT/u', $screen);
        $this->assertStringContainsString('Preview  ‹ 1/3 ›', $screen);
        $this->assertStringContainsString('Loading…', $screen);
        $this->assertMatchesRegularExpression("/What's New\\s+v1\\.0\\.0 · 2027-01-15/u", $screen);
        $this->assertMatchesRegularExpression('/Downloads\s+1,234/', $screen);

        $this->widget->setAsset(self::CHANGELOG, "# Changelog\n\n## [Unreleased]\n- soon\n\n## [1.0.0] - 2027-01-15\n- **Fixed** the thing\n\n## [0.9.0]\n- older\n");
        $this->widget->setAsset(self::README, "# Rector\n\nRefactor *everything*.\n");
        $this->widget->setAsset(self::SHOT, self::png());
        $this->widget->setAsset(self::SHOT_2, self::png());
        $this->widget->setAsset(self::SHOT_3, self::png());
        $screen = $this->screen(100, 80);
        $this->assertStringContainsString('Fixed the thing', $screen);
        $this->assertStringNotContainsString('older', $screen);
        $this->assertStringNotContainsString('soon', $screen);
        $this->assertStringContainsString('Refactor everything.', $screen);
        $this->assertStringNotContainsString('Loading…', $screen);

        $this->assertCount(
            StorePage::STRIP_ROWS,
            \array_filter(\explode("\n", $screen), static fn (string $line): bool => 1 === \preg_match('/^ ▀{20}  ▀{20}  ▀{20} +$/u', $line)),
            'three screenshots side by side, each as tall as the strip, two columns apart',
        );
        $narrow = \array_filter(\explode("\n", $this->screen(40, 80)), static fn (string $line): bool => 1 === \preg_match('/▀{4}/u', $line));
        $this->assertSame(
            [2],
            \array_values(\array_unique(\array_map(static fn (string $line): int => (int) \preg_match_all('/▀+/u', $line), $narrow))),
            'a narrow page shows what fits, the rest cut off',
        );
        $this->widget->setAsset(self::SHOT_2, null);
        $this->assertStringContainsString('The screenshot could not be shown', $this->screen(100, 80), 'the second stands beside the first');
        $this->assertMatchesRegularExpression('/^ ▀{20} +The screenshot could not be shown/mu', $this->screen(100, 80));
        $this->key("\x1b[C"); // the strip moves on one
        $this->assertStringContainsString('Preview  ‹ 2/3 ›', $this->screen(100, 80));
        $this->assertMatchesRegularExpression('/^ +The screenshot could not be shown +▀{20} +$/mu', $this->screen(100, 80), 'it starts at the second');
        $this->assertCount(5, $this->asked, 'nothing it shows was not asked for already');
        $this->key("\x1b[C", "\x1b[C");
        $this->assertStringContainsString('Preview  ‹ 3/3 ›', $this->screen(100, 80), 'it stops at the last');
        $this->key("\x1b[D");
        $this->assertStringContainsString('Preview  ‹ 2/3 ›', $this->screen(100, 80), 'back one');
        $this->key("\x1b[D", "\x1b[D");
        $this->assertStringContainsString('Preview  ‹ 1/3 ›', $this->screen(100, 80), 'and it stops at the first');

        $this->key(' ', "\r");
        $this->assertSame(['acme/porta-rector', 'acme/porta-rector'], $this->pressed);
        [$row, $col] = $this->at('Get');
        $this->assertStringNotContainsString("\e[4m", $this->raw());
        $this->assertTrue($this->widget->hoverAt($row, $col), 'the page\'s button');
        $this->assertStringContainsString("\e[4m", $this->raw(), 'underlined under the pointer');
        $this->widget->handleClick($row + 2, $col); // under its box
        $this->assertCount(2, $this->pressed);
        $this->widget->handleClick($row - 1, $col); // its top border is the button too
        $this->assertCount(3, $this->pressed);
        $this->widget->handleClick($row + 1, $col); // and its bottom one
        $this->assertCount(4, $this->pressed);
        $this->assertTrue($this->widget->hoverAt($row + 4, 5));
        $this->widget->handleClick($row + 4, 5); // elsewhere on the page
        $this->assertCount(4, $this->pressed);
        // Tab goes through the page's links — its package and its source here — and round to the button.
        $this->key("\t", "\r");
        $this->assertSame(['https://packagist.org/packages/acme/porta-rector'], $this->links);
        $this->key("\t", "\r", "\t", ' ');
        $this->assertSame(['https://packagist.org/packages/acme/porta-rector', 'https://github.com/acme/porta-rector'], $this->links);
        $this->assertCount(5, $this->pressed, 'past the last link, round to the button: Space presses it');
        $this->key("\x1b[Z", "\x1b[Z", "\x1b[Z", ' ');
        $this->assertCount(6, $this->pressed, 'Shift+Tab goes back, round to the button');
        $this->assertSame(0, $this->focusNext, 'a page keeps Tab: it always has a link, its package');
        [$at, $col] = $this->at('https://github.com/acme/porta-rector');
        $this->widget->handleClick($at, $col + 2);
        $this->assertCount(3, $this->links, 'a click on a link follows it');

        $this->key("\x7f"); // Backspace: back to the shelf
        $this->assertNull($this->widget->detail());
    }

    public function testAPageScrollsAndGoesBackByEscapeOrThePluginsCrumb(): void
    {
        $this->shelf();
        $this->screen();
        $this->key("\r"); // phpunit, first in the banner: one screenshot-less page
        $this->assertStringContainsString('Esc back · ⏎ update', $this->screen(100, 12));
        $this->assertSame([self::CHANGELOG, self::README], $this->asked);

        $this->widget->handleInput("\x1b[B");
        $this->widget->handleInput("\x1b[B");
        $this->assertStringNotContainsString('┌────┐', $this->screen(100, 12), 'scrolled past the header');
        foreach (["\x1b[A", "\x1b[A", "\x1b[6~", "\x1b[5~"] as $key) {
            $this->widget->handleInput($key);
        }
        $this->widget->scrollBy(1);
        $this->widget->scrollBy(-1);
        $this->widget->handleInput("\x1b[C"); // no screenshots: nothing to page
        $this->widget->handleInput("\x1b[D");
        $this->assertStringContainsString('┌────┐', $this->screen(100, 12));

        $this->key("\x1b");
        $this->assertNull($this->widget->detail());
        $this->assertSame(0, $this->exits, 'Esc on a page leaves the page, not the view');

        $this->key("\r");
        $this->click('Plugins');
        $this->assertNull($this->widget->detail());
        $this->assertSame([], $this->crumbs, 'the store\'s own crumb is the widget\'s');
    }

    public function testAPageWithoutAChangelogListsTheReleasesAndOneNotListedClosesItself(): void
    {
        $entry = new StoreEntry(
            'acme/porta-bare',
            'bare',
            'Bare',
            'Ada',
            '',
            StoreCategory::Other,
            'v1.0.0',
            history: [
                ['v1.0.0', 1_800_000_000],
                ['v0.1.0', 0],
            ],
            replaces: 'mago',
            compatible: false,
        );
        $this->widget->setStore([new StoreRow($entry, StoreAction::Incompatible)], false, 0);
        $this->assertStringContainsString('Ada · Other · v1.0.0', $this->screen(), 'no description: its category says what it is');
        $this->key("\r");
        $screen = $this->screen(100, 40);

        $this->assertStringContainsString('Replaces the built-in mago plugin', $screen);
        $this->assertStringContainsString('No release of it runs on this Porta', $screen);
        $this->assertMatchesRegularExpression('/v1\.0\.0\s+2027-01-15\s*\n\s+v0\.1\.0/', $screen);
        $this->assertStringNotContainsString('About', $screen);
        $this->assertStringNotContainsString('DOWNLOADS', $screen, 'a fact it does not have is not in the strip');
        $this->key(' ');
        $this->assertSame([], $this->pressed, 'Incompatible does nothing');

        $this->widget->setStore([], false, 0);
        $this->assertNull($this->widget->detail());
    }

    /** @param list<string> $rows the first of $rows that holds $needle, or '' */
    private static function rowWith(array $rows, string $needle): string
    {
        return \array_find($rows, static fn (string $line): bool => \str_contains($line, $needle)) ?? '';
    }

    /**
     * Mirrored, a tile is mirrored whole: its icon's three rows stay one box,
     * and each fact of the page's strip stays under its caption. Read on the
     * reordered rows — what the terminal shows, not what was composed.
     */
    public function testInHebrewEachTileAndEachFactMirrorsWhole(): void
    {
        $widget = new PluginStoreWidget(new Translator('he'));
        $widget->setStore(
            [
                new StoreRow(self::entry('chroma', StoreCategory::Languages), StoreAction::Enable),
                new StoreRow(self::entry('phpactor', StoreCategory::Languages), StoreAction::Get),
            ],
            false,
            0,
        );
        $rows = static fn (): array => \array_map(
            static fn (string $line): string => AnsiUtils::stripAnsiCodes(Bidi::reorder($line, BaseDirection::Rtl)),
            $widget->render(new RenderContext(86, 14)),
        );
        $shelf = $rows();
        $top = self::rowWith($shelf, '┌────┐');
        $middle = self::rowWith($shelf, '│ Ch │');
        $bottom = self::rowWith($shelf, '└────┘');
        $this->assertSame(\mb_strrpos($top, needle: '┌────┐'), \mb_strpos($middle, needle: '│ Ch │'), 'chroma\'s icon: one box, at the right as a mirrored row starts');
        $this->assertSame(\mb_strrpos($top, needle: '┌────┐'), \mb_strrpos($bottom, needle: '└────┘'));
        $this->assertGreaterThan(\mb_strpos($middle, needle: 'Does chroma'), \mb_strpos($middle, needle: '│ Ch │'), 'the icon right of its text');
        $this->assertLessThan(\mb_strpos($middle, needle: 'Does chroma'), \mb_strpos($middle, needle: '│ Ph │'), 'phpactor, the second tile, to the left of the first');

        $widget->handleInput("\r");
        $page = $rows();
        $captions = self::rowWith($page, 'ןוישיר');
        $values = self::rowWith($page, 'MIT');
        $this->assertSame(\mb_strpos($captions, needle: '│'), \mb_strpos($values, needle: '│'), 'the cells line up');
        $this->assertLessThan(\mb_strpos($values, needle: '│'), \mb_strpos($values, needle: 'MIT'), 'the licence under its caption, the leftmost');
        $this->assertLessThan(\mb_strpos($captions, needle: '│'), \mb_strpos($captions, needle: 'ןוישיר'));
    }

    public function testTheAppsInputHookComesFirstAndAStrayKeyOnAPageDoesNothing(): void
    {
        $this->shelf();
        $this->screen();
        $this->key('x'); // no key of the shelf's
        $this->assertSame(StoreTab::Discover, $this->widget->tab());
        $this->widget->onInput(static fn (string $keys): bool => 'u' === $keys);
        $this->key('u');
        $this->assertSame(StoreTab::Discover, $this->widget->tab(), 'the hook took the key');

        $this->key("\r", 'x');
        $this->assertSame('acme/porta-phpunit', $this->widget->detail());
        $this->assertSame([], $this->pressed);
        $this->widget->showTab(StoreTab::Updates);
        $this->assertNull($this->widget->detail(), 'a tab is shown from its shelf');
    }

    public function testATileWithAnIconDrawsItInPlaceOfItsInitials(): void
    {
        $this->shelf();
        $this->assertMatchesRegularExpression('/│ Re │ Does rector/u', $this->screen());

        $this->widget->setArt('acme/porta-rector', new Art(icon: PngFixture::bytes(32, 32)));
        $screen = $this->screen();
        $this->assertDoesNotMatchRegularExpression('/│ Re │/u', $screen, 'the picture takes the box');
        $this->assertMatchesRegularExpression('/^ ▀{6} Rector/mu', $screen, 'six columns wide');
        $this->assertCount(3, \array_filter(\explode("\n", $screen), static fn (string $line): bool => 1 === \preg_match('/^ ▀{6} \S/u', $line)), 'three rows tall, beside the tile\'s three lines');
        $this->assertStringContainsString('│ To │', $screen, 'a plugin with no picture keeps its initials');

        $this->density->observe(24);
        $screen = $this->screen(100, 24);
        $this->assertMatchesRegularExpression('/^ ▀{4} Rector/mu', $screen, 'compact, four columns');
        $this->assertMatchesRegularExpression('/^ ▀{4} Does rector · Ada/mu', $screen, 'and two rows: what it is beside the second');
        $this->assertStringContainsString('[To]', $screen);

        $this->widget->setArt('acme/porta-rector', new Art(icon: 'not a png'));
        $this->assertStringContainsString('[Re]', $this->screen(100, 24), 'a picture that cannot be drawn leaves the initials');
    }

    public function testAnInstalledPluginWearsItsStoreIcon(): void
    {
        $this->installed();
        $this->widget->setArt('acme/porta-phpunit', new Art(icon: PngFixture::bytes(32, 32)));
        $screen = $this->screen();
        $this->assertMatchesRegularExpression('/▀{6} Phpunit/u', $screen);
        $this->assertMatchesRegularExpression('/│ Gi │ Git history/u', $screen, 'one the store does not know keeps its initials');
    }

    public function testAFeaturedPluginsPictureStandsOverItsTile(): void
    {
        $this->shelf();
        $plain = \explode("\n", $this->screen());
        $this->widget->setArt('acme/porta-phpunit', new Art(hero: PngFixture::bytes(320, 90)));
        $lines = \explode("\n", $this->screen());
        $title = \array_key_first(\array_filter($lines, static fn (string $line): bool => \str_contains($line, 'Phpunit')));
        $this->assertMatchesRegularExpression('/^ │ +▀{20,}/u', $lines[$title - 2], 'inside the banner, over the tile');
        $this->assertMatchesRegularExpression('/^ ╭ Featured/u', $lines[5], 'the banner\'s top border opens the shelf');
        $pictureRows = \count(\array_filter($lines, static fn (string $line): bool => 1 === \preg_match('/^ │ +▀{20,}/u', $line)));
        $this->assertGreaterThan(1, $pictureRows);
        $this->assertLessThanOrEqual(StoreShelf::HERO_ROWS, $pictureRows);
        $this->assertSame(
            $pictureRows,
            \count($lines) - \count($plain) + \count(\array_filter($plain, static fn (string $line): bool => '' === \trim($line)))
            - \count(\array_filter($lines, static fn (string $line): bool => '' === \trim($line))),
            'the shelf grows by the picture alone',
        );

        $this->widget->setArt('acme/porta-rector', new Art(hero: PngFixture::bytes(320, 90)));
        $this->assertCount(
            $pictureRows,
            \array_filter(\explode("\n", $this->screen()), static fn (string $line): bool => 1 === \preg_match('/^ │ +▀{20,}/u', $line)),
            'only a featured plugin stands under its picture',
        );

        // Down the shelf, the banner scrolled away, and back up: the featured tile brings its picture into view.
        foreach (["\x1b[B", "\x1b[B", "\x1b[B"] as $key) {
            $this->widget->handleInput($key);
            $this->screen(100, 24);
        }
        $this->assertDoesNotMatchRegularExpression('/╭ Featured/u', $this->screen(100, 24), 'its top scrolled away');
        foreach (["\x1b[A", "\x1b[A", "\x1b[A"] as $key) {
            $this->widget->handleInput($key);
            $this->screen(100, 24);
        }
        $short = \explode("\n", $this->screen(100, 24));
        $this->assertMatchesRegularExpression('/^ ╭ Featured/u', $short[5], 'the banner\'s top, with the picture under it');
        $this->assertMatchesRegularExpression('/^ │ +▀{20,}/u', $short[6]);

        $this->density->observe(24);
        $this->assertDoesNotMatchRegularExpression('/▀{20,}/u', $this->screen(100, 24), 'compact, no picture: the banner is a plain shelf');
    }

    public function testThePageShowsItsIconAndTheScreenshotsItsReadmeShows(): void
    {
        $this->shelf();
        $this->screen();
        $this->key("\x1b[B", "\x1b[B", "\x1b[B", "\r"); // the page of the plugin called todo
        $this->assertSame('acme/porta-todo', $this->widget->detail());
        $this->assertSame([self::CHANGELOG, self::README], $this->asked, 'it lists no screenshot');
        $this->assertStringNotContainsString('Preview', $this->screen());

        $this->widget->setArt('acme/porta-todo', new Art(icon: PngFixture::bytes(64, 64), shots: [self::SHOT_2]));
        $this->assertSame([self::CHANGELOG, self::README, '!'.self::SHOT_2], $this->asked, 'its README\'s screenshot is asked for as it is found');
        $screen = $this->screen(100, 40);
        $this->assertMatchesRegularExpression('/^ ▀{10} Todo/mu', $screen, 'a large icon by its name');
        $this->assertCount(5, \array_filter(\explode("\n", $screen), static fn (string $line): bool => 1 === \preg_match('/^ ▀{10} /u', $line)), 'five rows tall');
        $this->assertStringContainsString('Preview', $screen);
        $this->assertStringNotContainsString('←/→ screenshots', $screen, 'one screenshot has nowhere to move');

        $this->widget->setArt('acme/porta-rector', new Art(shots: ['https://img.test/readme.png']));
        $this->key("\x7f", "\x1b[A", "\x1b[A", "\r"); // rector's page: it lists its own
        $this->assertSame('acme/porta-rector', $this->widget->detail());
        $this->assertNotContains('!https://img.test/readme.png', $this->asked, 'what a package lists wins');
    }

    public function testASecondRowOfFeaturedPluginsBringsItsPicturesIntoViewWithIt(): void
    {
        $names = ['alpha', 'bravo', 'charlie', 'delta'];
        $this->widget->setStore(\array_map(static fn (string $name): StoreRow => new StoreRow(self::entry($name, featured: true), StoreAction::Get), $names), false, 0);
        foreach ($names as $name) {
            $this->widget->setArt('acme/porta-'.$name, new Art(hero: PngFixture::bytes(320, 90)));
        }
        /** @param list<string> $keys */
        $walk = function (array $keys): array {
            foreach ($keys as $key) {
                $this->widget->handleInput($key);
                $this->screen(100, 16);
            }

            return \explode("\n", $this->screen(100, 16));
        };

        // To the foot of the shelf, then up to Charlie, the banner's second row.
        $walk(\array_fill(start_index: 0, count: 8, value: "\x1b[B"));
        $lines = $walk(["\x1b[A", "\x1b[A"]);
        $this->assertStringContainsString('Charlie', \implode("\n", $lines));
        $this->assertMatchesRegularExpression('/^ │  ▀{45}   ▀{45}  │$/u', $lines[4], 'its row\'s pictures stand at the top of the shelf, over it, each over its tile');

        $lines = $walk(["\x1b[C", "\x1b[A"]); // Delta, then Bravo over it
        $this->assertStringContainsString('Bravo', \implode("\n", $lines));
        $this->assertMatchesRegularExpression('/^ ╭ Featured/u', $lines[4], 'the first row brings the banner\'s top, its pictures under it');

        // Pictures whose header says a size but that cannot be drawn: the second row brings only itself, the first the banner's top.
        foreach ($names as $name) {
            $this->widget->setArt('acme/porta-'.$name, new Art(hero: \substr(PngFixture::bytes(320, 90), offset: 0, length: 33)));
        }
        $walk(\array_fill(start_index: 0, count: 8, value: "\x1b[B"));
        $lines = $walk(["\x1b[A", "\x1b[A"]);
        $this->assertMatchesRegularExpression('/^ │  ┌────┐ Charlie/u', $lines[4], 'a picture that cannot be drawn stands nowhere');
        $lines = $walk(["\x1b[C", "\x1b[A"]);
        $this->assertMatchesRegularExpression('/^ ╭ Featured/u', $lines[4]);
    }

    public function testAScreenshotTheStripMovesPastIsFreedInTheTerminal(): void
    {
        [$graphics, $terminal] = PixelGraphics::answered();
        $this->widget = new PluginStoreWidget(new Translator('en'), graphics: $graphics);
        $this->shelf();
        $this->screen();
        $this->key("\x1b[B", "\r"); // rector's page
        foreach ([self::SHOT, self::SHOT_2, self::SHOT_3] as $i => $url) {
            $this->widget->setAsset($url, PngFixture::bytes(32, 32, [40 * $i, 0, 0]));
        }
        $this->screen(100, 80);
        $terminal->clearOutput();

        $this->key("\x1b[C");
        $this->screen(100, 80);
        $this->assertStringContainsString('a=d,d=I,', $terminal->getOutput(), 'the first, out of the strip, is deleted');
    }

    /** A row of featured plugins stands pictures over every tile or none: one alone left a hole beside it. */
    public function testARowOfFeaturedPluginsHasPicturesOverAllItsTilesOrNone(): void
    {
        $this->widget->setStore([new StoreRow(self::entry('alpha', featured: true), StoreAction::Get), new StoreRow(self::entry('bravo', featured: true), StoreAction::Get)], false, 0);
        $this->widget->setArt('acme/porta-bravo', new Art(hero: PngFixture::bytes(320, 90)));
        $this->assertDoesNotMatchRegularExpression('/▀{45}/u', $this->screen(), 'only the second has one: none');

        $this->widget->setArt('acme/porta-alpha', new Art(hero: PngFixture::bytes(320, 90)));
        $this->assertMatchesRegularExpression('/^ │  ▀{45}   ▀{45}  │$/mu', $this->screen(), 'both: over each');
    }

    /** From a featured tile in the left column, down is the next shelf's first tile — not the See all at its far right. */
    public function testDownFromTheLeftColumnPassesTheSeeAllAtTheRight(): void
    {
        $rows = [
            new StoreRow(self::entry('star', StoreCategory::Testing, featured: true), StoreAction::Get),
            new StoreRow(self::entry('nova', StoreCategory::Testing, featured: true), StoreAction::Get),
        ];
        foreach (['alpha', 'bravo', 'charlie', 'delta', 'echo'] as $name) {
            $rows[] = new StoreRow(self::entry($name), StoreAction::Get);
        }
        $this->widget->setStore($rows, false, 0);
        $this->screen();
        $this->key("\x1b[B", "\r");
        $this->assertSame('acme/porta-alpha', $this->widget->detail());
    }

    /** A page's Markdown links are its own: a README's relative one leads to its page on GitHub, an anchor nowhere. */
    public function testAPagesReadmeLinksAreFollowed(): void
    {
        $this->widget->setStore([new StoreRow(self::entry('rector'), StoreAction::Get)], false, 0);
        $this->screen();
        $this->key("\r");
        $this->widget->setAsset(
            self::README,
            "# Rector\n\n- [ ] a task\n\nSee [usage](docs/usage.md), [top](#top) and [site](https://rector.test).\n\n".\str_repeat("Line.\n\n", times: 40)."[last](https://last.test)\n",
        );
        $this->widget->setAsset(self::CHANGELOG, null);
        $this->screen(100, 20);
        $this->key("\t", "\r", "\t", "\r");
        $this->assertSame(['https://raw.test/docs/usage.md', 'https://rector.test'], $this->links, 'the anchor is no link to follow, nor the task\'s box');
        [$row, $col] = $this->at('site', 100, 20);
        $this->widget->handleClick($row, $col);
        $this->widget->handleClick($row, $col - 1);
        $this->assertSame('https://rector.test', \array_last($this->links), 'a click on it follows it');
        $this->assertCount(3, $this->links, 'beside it, nothing');
        $this->widget->handleInput("\t");
        $this->assertStringContainsString('last', $this->screen(100, 20), 'the page scrolls to the link Tab is on');
        [$row, $col] = $this->at('last', 100, 20);
        $this->widget->handleClick($row, $col);
        $this->assertSame('https://last.test', \array_last($this->links), 'a click on a page scrolled down');
        $this->assertSame('https://github.com/acme/porta-x/blob/abc/docs/a.md', StorePage::address('docs/a.md', 'https://raw.githubusercontent.com/acme/porta-x/abc/README.md'));
        $this->assertNull(StorePage::address('mailto:ada@x.test', 'https://raw.test/README.md'));
        $this->assertNull(StorePage::address('docs/a.md', ''));
    }
}
