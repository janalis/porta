<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\I18n\Translator;
use Porta\Ide;
use Porta\Layout\ResponsiveLayout;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Glyphs;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Chrome rows are fixed-height and their content is translated, which is the
 * standing trap in this codebase: a width chosen against the English screen
 * quietly stops fitting when a translator gets to it, and the row does not
 * complain — it drops something.
 *
 * Three landed in one session. The settings nav's floor was 14 columns while
 * French spells Keybindings `Raccourcis clavier` (19). The keybindings header
 * lost `Ctrl+F to filter` off the end when a fourth hint went in. The status
 * bar dropped the FILE NAME — not truncated, absent — at 100 columns in
 * English and 140 in German, while a five-item key legend kept its full width.
 *
 * Each was found by rendering a screen and looking at it. This does the
 * looking on every commit, in all seven shipped locales, across the width band
 * the IDE actually supports.
 *
 * The assertions are deliberately locale-INDEPENDENT — they never compare
 * against an English string, because that is the assumption under test. They
 * ask structural questions instead: is the thing still there at all, and if it
 * did not fit, does the row say so. A surface is free to truncate; what it may
 * not do is silently drop an element.
 */
final class ChromeSurvivesEveryLocaleTest extends TestCase
{
    use ClearsPortaLsp;
    use NavigatesSettings;
    use PreservesPath;
    use PreservesPortaHome;
    use RunsIde;
    use ScreenReads;

    /**
     * The band the IDE supports: {@see ResponsiveLayout::COLLAPSE_BELOW} is
     * where the sidebar folds away, so 70 is the narrow edge of a usable
     * window rather than an invented worst case.
     */
    private const array WIDTHS = [60, ResponsiveLayout::COLLAPSE_BELOW, 80, 100, 120, 140];

    /** Deep enough that the path cannot fit, and named so its tail is unmistakable. */
    private const string FIXTURE = 'src/Some/Deep/Namespace/ExampleClass.php';

    private TempProject $project;

    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        foreach (Translator::LOCALES as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('locales')]
    public function testEveryChromeRowStillSaysWhatItIsFor(string $locale): void
    {
        \putenv('PATH=/nonexistent'); // no machine-dependent LSP status in the bar
        $this->project = new TempProject([
            self::FIXTURE => "<?php\n\$x = 1;\n",
            '.porta.json' => \json_encode(['settings' => ['language' => $locale]], \JSON_THROW_ON_ERROR),
        ]);

        $this->runIde(
            $this->project,
            $this->project->path(self::FIXTURE),
            [
                400 => function ($tui) use ($locale): void {
                    $this->assertStatusBarKeepsTheFileName($tui, $locale);
                    $this->assertMenuBarDropsNoTitle($tui, $locale);
                },
                600 => function ($tui, $terminal, Ide $ide) use ($locale): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $this->frame($tui);
                    $this->assertSettingsNavFitsItsOwnRows($tui, $locale);
                    $this->assertKeybindingsHeaderKeepsItsFilterHint($tui, $locale);
                },
            ],
            columns: 140,
        );
    }

    /**
     * Which file you are editing is the one fact this row exists for, and it
     * used to be the first thing sacrificed: the right side took `columns - 3`
     * and the left got the remainder, down to nothing.
     */
    private function assertStatusBarKeepsTheFileName(mixed $tui, string $locale): void
    {
        $bar = $tui->getById('status');
        \assert($bar instanceof StatusBarWidget, description: 'the status bar is the status widget');
        foreach (self::WIDTHS as $width) {
            $row = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext($width, 1))[0]);
            $this->assertStringContainsString(
                'Class.php',
                $row,
                \sprintf('[%s @ %d] the status bar dropped the file name: |%s|', $locale, $width, \rtrim($row)),
            );
            $this->assertSame($width, AnsiUtils::visibleWidth($row), \sprintf('[%s @ %d] the status row is exactly its width', $locale, $width));
        }
    }

    /**
     * A title that does not fit may be dropped — the row is one line and the
     * keyboard still reaches it — but the row has to SAY so. At 60 columns the
     * German and French bars end at Ansicht / Affichage and used to read as
     * though Hilfe / Aide did not exist.
     *
     * Titles are read off the widget itself, never from a translated list:
     * comparing against English is the assumption under test.
     */
    private function assertMenuBarDropsNoTitle(mixed $tui, string $locale): void
    {
        $bar = $tui->getById('menu');
        \assert($bar instanceof MenuBarWidget, description: 'the menu bar is the menu widget');
        /** @var list<object> $menus */
        $menus = Probe::get($bar, 'menus');
        $this->assertNotEmpty($menus, 'the menu bar has menus to lose');
        foreach (self::WIDTHS as $width) {
            $row = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext($width, 1))[0]);
            $missing = [];
            foreach ($menus as $menu) {
                /** @var string $title */
                $title = $menu->title;
                if (!\str_contains($row, $title)) {
                    $missing[] = $title;
                }
            }
            if ([] === $missing) {
                continue;
            }

            $this->assertStringEndsWith(
                Glyphs::ELLIPSIS,
                \rtrim($row),
                \sprintf('[%s @ %d] the menu bar dropped %s with nothing to say it did: |%s|', $locale, $width, \implode(', ', $missing), \rtrim($row)),
            );
        }
    }

    /**
     * The nav's floor is where a drag to the stop lands, so it is the width
     * the sidebar is guaranteed to be drawn at. Every row has to READ there —
     * a floor that predates the rows it now holds cuts the plugin and section
     * names the sidebar exists to name.
     */
    private function assertSettingsNavFitsItsOwnRows(mixed $tui, string $locale): void
    {
        $nav = $tui->getById('settings-nav');
        \assert($nav instanceof SettingsNavWidget, description: 'the settings view owns the nav');
        // setWidth, not just a narrow RenderContext: the widget renders at
        // min(its own width, the context) — handing it a context alone measures
        // the default 22 and says nothing about the floor.
        $floor = $nav->minWidth();
        $nav->setWidth($floor);
        $this->assertSame($floor, $nav->width(), \sprintf('[%s] the nav really is at its floor', $locale));
        foreach ($nav->render(new RenderContext($floor, 40)) as $line) {
            $row = AnsiUtils::stripAnsiCodes($line);
            $this->assertStringNotContainsString(
                Glyphs::ELLIPSIS,
                $row,
                \sprintf('[%s] the settings nav cannot spell its own rows at its floor of %d: |%s|', $locale, $floor, \rtrim($row)),
            );
        }
    }

    /**
     * The header teaches the page's keys, and a fourth hint once pushed the
     * filter off the end at 60 columns. `Ctrl+F` is Latin in every catalog, so
     * asking for it needs no translated expectation.
     */
    private function assertKeybindingsHeaderKeepsItsFilterHint(mixed $tui, string $locale): void
    {
        $this->selectSettingsSection($this->settingsNav($tui), 'keybindings');
        $this->frame($tui);
        $list = $tui->getById('settings-keys');
        \assert($list instanceof KeybindingsListWidget, description: 'the keybindings page owns the list');
        foreach (self::WIDTHS as $width) {
            $header = AnsiUtils::stripAnsiCodes($list->render(new RenderContext($width, 8))[0]);
            $this->assertStringContainsString(
                'Ctrl+F',
                $header,
                \sprintf('[%s @ %d] the keybindings header lost its filter hint: |%s|', $locale, $width, \rtrim($header)),
            );
        }
    }
}
