<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Event\SettingsSectionRequested;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\InstallScope;
use Porta\Plugin\Store\PluginUpdate;
use Porta\Plugin\Store\StoreAction;
use Porta\Plugin\Store\StoreCategory;
use Porta\Plugin\Store\StoreEntry;
use Porta\Plugin\Store\StoreRow;
use Porta\Widget\Settings\PluginStoreWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/** The store's shelf and its pages, drawn and driven from the keyboard and the pointer. */
final class PluginStoreWidgetTest extends TestCase
{
    private const string CHANGELOG = 'https://raw.test/CHANGELOG.md';

    private const string README = 'https://raw.test/README.md';

    private const string SHOT = 'https://img.test/1.png';

    private const string SHOT_2 = 'https://img.test/2.png';

    /** @var list<string> */
    private array $pressed = [];

    /** @var list<string> */
    private array $crumbs = [];

    /** @var list<string> each address the page asked for, `!` marking a picture */
    private array $asked = [];

    private int $updateAll = 0;

    private int $exits = 0;

    private int $focusNext = 0;

    /** @var list<string> */
    private array $sections = [];

    private PluginStoreWidget $widget;

    protected function setUp(): void
    {
        $events = new EventDispatcher();
        $events->addListener(SettingsSectionRequested::class, function (SettingsSectionRequested $event): void {
            $this->sections[] = $event->section;
        });
        $this->widget = new PluginStoreWidget(new Translator('en'), events: $events);
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
            ->onExit(function (): void {
                ++$this->exits;
            })
            ->onFocusNext(function (): void {
                ++$this->focusNext;
            });
    }

    private static function entry(string $name, StoreCategory $category = StoreCategory::Linters, bool $verified = false, bool $featured = false, array $screenshots = []): StoreEntry
    {
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

    /** The shelf: rector (verified, Get), phpunit (featured, testing, Update), todo (Built-in). */
    private function shelf(): void
    {
        $this->widget->setStore(
            [
                new StoreRow(self::entry('rector', verified: true, screenshots: [self::SHOT, self::SHOT_2]), StoreAction::Get),
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

    private function screen(int $columns = 100, int $rows = 30): string
    {
        return AnsiUtils::stripAnsiCodes(\implode("\n", $this->widget->render(new RenderContext($columns, $rows))));
    }

    private function key(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->widget->handleInput($key);
        }
    }

    /** A 1×1 PNG, for the screenshot the page draws. */
    private static function png(): string
    {
        $image = \imagecreatetruecolor(4, height: 4);
        \ob_start();
        \imagepng($image);

        return (string) \ob_get_clean();
    }

    public function testTheShelfListsEachPluginWithItsFactsAndButtonFeaturedFirst(): void
    {
        $this->shelf();
        $screen = $this->screen();

        $this->assertStringContainsString('Settings › Plugins › Store', $screen);
        $this->assertStringContainsString('Discover', $screen);
        $this->assertStringContainsString('Updates (1)', $screen);
        $this->assertStringContainsString('All categories ▾', $screen);
        $this->assertMatchesRegularExpression('/Phpunit\s+Featured.*\[ Update \]/u', $screen);
        $this->assertStringContainsString('Ada · Testing · v0.9.0 → v1.0.0 · 2027-01-15 · 1,234 downloads', $screen);
        $this->assertMatchesRegularExpression('/Rector\s+✓ Verified.*\[ Get \]/u', $screen);
        $this->assertStringContainsString('[ Working… ]', $screen);
        $this->assertLessThan(\strpos($screen, needle: 'Rector'), \strpos($screen, needle: 'Phpunit'), 'featured first');
    }

    public function testWhatAnEmptyShelfSays(): void
    {
        $this->widget->setStore([], true, 0);
        $this->assertStringContainsString('Asking Packagist…', $this->screen());
        $this->widget->setStore([], false, 0);
        $this->assertStringContainsString('The store is empty: Packagist could not be reached', $this->screen());
        $this->shelf();
        $this->key('u');
        $this->widget->setStore([new StoreRow(self::entry('rector'), StoreAction::Get)], false, 0);
        $this->assertStringContainsString('Every plugin is up to date', $this->screen());
        $this->key('u', '/', 'z', 'z');
        $this->assertStringContainsString('No plugin matches', $this->screen());
    }

    public function testCategoriesTabsAndTheFilterNarrowTheShelf(): void
    {
        $this->shelf();

        $this->key('u');
        $this->assertStringNotContainsString('Rector', $this->screen(), 'Updates lists only what has one');
        $this->assertStringContainsString('Phpunit', $this->screen());
        $this->key('u');

        $this->key('c'); // Languages: none
        $this->assertStringContainsString('Languages ▾', $this->screen());
        $this->assertStringContainsString('No plugin matches', $this->screen());
        $this->key('c'); // Linters & formatters
        $this->assertStringContainsString('Rector', $this->screen());
        $this->assertStringNotContainsString('Phpunit', $this->screen());
        $this->key('C', 'C'); // Shift+C twice: back to All
        $this->assertStringContainsString('All categories ▾', $this->screen());
        $this->key('C'); // and round to the last shelf
        $this->assertStringContainsString('Other ▾', $this->screen());
        $this->key('c');

        $this->key('/', 'r', 'e', 'c', "\x7f");
        $screen = $this->screen();
        $this->assertStringContainsString('Filter', $screen);
        $this->assertStringContainsString('Rector', $screen);
        $this->assertStringNotContainsString('Todo', $screen);
        $this->key("\x1b"); // leaves the filter, not the view
        $this->assertSame(0, $this->exits);
        $this->assertStringContainsString('Todo', $this->screen());
    }

    public function testTheKeysDriveTheShelf(): void
    {
        $this->shelf();
        $this->screen();
        $this->key("\x1b[B", ' '); // rector, the second shown
        $this->assertSame(['acme/porta-rector'], $this->pressed);
        $this->key("\x1b[B", ' '); // the third plugin: Working does nothing
        $this->assertSame(['acme/porta-rector'], $this->pressed);
        $this->key("\x1b[5~", ' ');
        $this->assertSame(['acme/porta-rector', 'acme/porta-phpunit'], $this->pressed);

        $this->key('U', "\t", '1', "\x1b"); // Shift+U
        $this->assertSame(1, $this->updateAll);
        $this->assertSame(1, $this->focusNext);
        $this->assertSame(['general'], $this->sections);
        $this->assertSame(1, $this->exits);

        $this->widget->scrollBy(2);
        $this->widget->scrollBy(-1);
        $this->key(' ');
        $this->assertSame('acme/porta-rector', \end($this->pressed), 'the wheel is the arrows');
    }

    public function testThePointerSelectsOpensPressesAndSwitches(): void
    {
        $this->shelf();
        $this->screen();

        $this->widget->handleClick(5, 10); // rector's facts row: selected
        $this->assertSame([], $this->pressed);
        $this->assertTrue($this->widget->hoverRow(5));
        $this->assertFalse($this->widget->hoverRow(5));
        $this->widget->handleClick(5, 95); // its button
        $this->assertSame(['acme/porta-rector'], $this->pressed);
        $this->widget->handleClick(3, 95); // phpunit's button, not yet selected
        $this->assertSame(['acme/porta-rector', 'acme/porta-phpunit'], $this->pressed);
        $this->widget->handleClick(6, 95); // under rector's button, on its facts row: selects, presses nothing
        $this->assertCount(2, $this->pressed);
        $this->widget->handleClick(6, 95); // the selected row again: its page
        $this->assertSame('acme/porta-rector', $this->widget->detail());
        $this->widget->closeDetail();
        $this->widget->handleClick(29, 5); // below the last plugin
        $this->widget->handleClick(1, 5); // the rule

        $this->screen();
        $this->widget->handleClick(2, 14); // Updates
        $this->assertStringNotContainsString('Rector', $this->screen());
        $this->widget->handleClick(2, 3); // Discover
        $this->assertStringContainsString('Rector', $this->screen());
        $this->widget->handleClick(2, 30); // the category
        $this->assertStringContainsString('Languages ▾', $this->screen());
        $this->widget->handleClick(2, 90); // past everything on the strip
        $this->assertStringContainsString('Languages ▾', $this->screen());

        $this->widget->handleClick(0, 2); // Settings
        $this->assertSame(['general'], $this->crumbs);
        $this->assertTrue($this->widget->hoverCrumb(2));
        $this->assertFalse($this->widget->hoverCrumb(2));
    }

    public function testThePageShowsWhatTheStoreKnowsAndAsksForTheRest(): void
    {
        $this->shelf();
        $this->screen();
        $this->key("\x1b[B", "\r"); // rector's page
        $this->assertSame('acme/porta-rector', $this->widget->detail());
        $this->assertSame([self::CHANGELOG, self::README, '!'.self::SHOT], $this->asked);

        $screen = $this->screen();
        $this->assertStringContainsString('Settings › Plugins › Store › Rector', $screen);
        $this->assertStringContainsString('Esc back · ⏎ get · ←/→ screenshots', $screen);
        $this->assertStringContainsString('by Ada · Linters & formatters · v1.0.0 · MIT · ✓ Verified', $screen);
        $this->assertStringContainsString('Preview  ‹ 1/2 ›', $screen);
        $this->assertStringContainsString('Loading…', $screen);
        $this->assertStringContainsString("What's New  v1.0.0 · 2027-01-15", $screen);
        $this->assertMatchesRegularExpression('/Downloads\s+1,234/', $screen);

        $this->widget->setAsset(self::CHANGELOG, "# Changelog\n\n## [Unreleased]\n- soon\n\n## [1.0.0] - 2027-01-15\n- **Fixed** the thing\n\n## [0.9.0]\n- older\n");
        $this->widget->setAsset(self::README, "# Rector\n\nRefactor *everything*.\n");
        $this->widget->setAsset(self::SHOT, self::png());
        $screen = $this->screen(100, 80);
        $this->assertStringContainsString('Fixed the thing', $screen);
        $this->assertStringNotContainsString('older', $screen);
        $this->assertStringNotContainsString('soon', $screen);
        $this->assertStringContainsString('Refactor everything.', $screen);
        $this->assertStringNotContainsString('Loading…', $screen);

        $this->key("\x1b[C"); // the next screenshot
        $this->assertSame('!'.self::SHOT_2, \end($this->asked));
        $this->widget->setAsset(self::SHOT_2, null);
        $this->assertStringContainsString('The screenshot could not be shown', $this->screen(100, 80));
        $this->key("\x1b[D", "\x1b[D");
        $this->assertStringContainsString('Preview  ‹ 2/2 ›', $this->screen(100, 80), 'round from the first to the last');

        $this->key(' ', "\r");
        $this->assertSame(['acme/porta-rector', 'acme/porta-rector'], $this->pressed);
        $this->widget->handleClick(2, 95); // the page's button
        $this->assertCount(3, $this->pressed);
        $this->widget->handleClick(5, 5); // elsewhere on the page
        $this->assertCount(3, $this->pressed);
        $this->assertFalse($this->widget->hoverRow(3), 'a page has no rows to tint');
        $this->key("\t");
        $this->assertSame(1, $this->focusNext);

        $this->key("\x7f"); // Backspace: back to the shelf
        $this->assertNull($this->widget->detail());
    }

    public function testAPageScrollsAndGoesBackByEscapeOrTheStoresCrumb(): void
    {
        $this->shelf();
        $this->screen();
        $this->key("\r"); // phpunit: one screenshot-less page
        $this->assertStringContainsString('Esc back · ⏎ update', $this->screen(100, 12));
        $this->assertSame([self::CHANGELOG, self::README], $this->asked);

        $this->key("\x1b[B", "\x1b[B");
        $this->assertStringNotContainsString('by Ada', $this->screen(100, 12), 'scrolled past the header');
        $this->key("\x1b[A", "\x1b[A", "\x1b[6~", "\x1b[5~");
        $this->widget->scrollBy(1);
        $this->widget->scrollBy(-1);
        $this->key("\x1b[C"); // no screenshots: nothing to page
        $this->assertStringContainsString('by Ada', $this->screen(100, 12));

        $this->key("\x1b");
        $this->assertNull($this->widget->detail());
        $this->assertSame(0, $this->exits, 'Esc on a page leaves the page, not the view');

        $this->key("\r");
        $this->screen();
        $this->widget->handleClick(0, 23); // the Store crumb
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
        $this->screen();
        $this->key("\r");
        $screen = $this->screen(100, 40);

        $this->assertStringContainsString('Replaces the built-in mago plugin', $screen);
        $this->assertStringContainsString('No release of it runs on this Porta', $screen);
        $this->assertMatchesRegularExpression('/v1\.0\.0\s+2027-01-15\s*\n\s+v0\.1\.0/', $screen);
        $this->assertStringNotContainsString('About', $screen);
        $this->key(' ');
        $this->assertSame([], $this->pressed, 'Incompatible does nothing');

        $this->widget->setStore([], false, 0);
        $this->assertNull($this->widget->detail());
        $this->key("\r");
        $this->assertNull($this->widget->detail(), 'nothing to open');
    }

    public function testTheNewestSectionIsTheFirstVersionedOne(): void
    {
        $this->assertSame("- a\n- b", PluginStoreWidget::newestSection("# Log\r\n\r\n## Unreleased 2\r\n- x\r\n## 1.2.0\r\n- a\r\n- b\r\n## 1.1.0\r\n- c"));
        $this->assertSame('', PluginStoreWidget::newestSection("# Log\n\nnothing versioned"));
    }

    public function testTheAppsInputHookComesFirstAndAStrayKeyOnAPageDoesNothing(): void
    {
        $this->shelf();
        $this->screen();
        $this->key('x'); // no key of the shelf's
        $this->assertStringContainsString('Rector', $this->screen());
        $this->widget->onInput(static fn (string $keys): bool => 'u' === $keys);
        $this->key('u');
        $this->assertStringContainsString('Rector', $this->screen(), 'the hook took the key: still on Discover');

        $this->key("\r", 'x');
        $this->assertSame('acme/porta-phpunit', $this->widget->detail());
        $this->assertSame([], $this->pressed);
    }
}
