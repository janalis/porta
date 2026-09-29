<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Ide;
use Porta\Settings\Section\CommandLineSection;
use Porta\Settings\Section\GeneralSection;
use Porta\Settings\Section\HighlightingSection;
use Porta\Settings\Section\InterfaceSection;
use Porta\Settings\Section\LanguageSection;
use Porta\Settings\Section\PluginsSection;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\Paint;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\ReadsSettingsForm;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Widget\Settings\SettingsFormWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Tui;

/**
 * Locks what every settings page actually SAYS, with both config layers in
 * play.
 *
 * The sibling {@see \Porta\Tests\Unit\Widget\Settings\SettingsRenderGoldenTest}
 * locks the form's styling over synthetic rows, which is what makes it stable
 * — and also what makes it blind to this: the rows here come from the real
 * controllers, so the golden covers the text those controllers compose. Row
 * wording is assembled from up to four clauses by three collaborators, and no
 * point assertion says "this reads as one thing rather than two".
 *
 * That is not hypothetical. The scope row shipped reading
 * `Save changes to │ .porta.json ▾ │ · for this session · ~/.porta/config.json`
 * — two file paths joined by a middle dot, which parses as two destinations.
 * Every gate was green; the defect was found by looking at the screen. This
 * turns looking at the screen into something that happens on every commit.
 *
 * The fixture is deliberately two-layered: a global file that sets a theme, a
 * setting, and a language's indent, and a project that overrides one of them.
 * That is the only configuration in which the provenance tags say anything.
 *
 * Regenerate after an intentional change, and READ THE DIFF — it is the review:
 *   UPDATE_SETTINGS_PAGES_GOLDEN=1 vendor/bin/phpunit tests/E2e/SettingsPagesGoldenTest.php
 */
final class SettingsPagesGoldenTest extends TestCase
{
    use ClearsPortaLsp;
    use NavigatesSettings;
    use PreservesPath;
    use ReadsSettingsForm;
    use PreservesPortaHome;
    use RunsIde;
    use ScreenReads;

    private const string GOLDEN = __DIR__.'/../Support/golden/settings-pages.json';

    /** Every page a controller composes rows for. */
    private const array PAGES = ['general', 'interface', 'highlighting', 'commandLine', 'lang:php', 'plugins'];

    /**
     * Which page above stands for which section class.
     *
     * PAGES is a hand-written list of "every page", and a hand-written list of
     * everything is wrong the first time someone adds one — three such lists
     * went stale the same way the day this was written. {@see
     * testEverySettingsSectionHasAGoldenPage()} checks it against the
     * `Section/` directory, which is the container's own membership.
     *
     * `LanguageSection` claims a whole PREFIX rather than one id, so one
     * language stands for the family: the rows come from the same composer.
     *
     * @var array<class-string, string>
     */
    private const array SECTION_PAGES = [
        CommandLineSection::class => 'commandLine',
        GeneralSection::class => 'general',
        HighlightingSection::class => 'highlighting',
        InterfaceSection::class => 'interface',
        LanguageSection::class => 'lang:php',
        PluginsSection::class => 'plugins',
    ];

    /**
     * A new settings section has a golden page, or this fails.
     *
     * The defect it guards against is the one the golden test exists for,
     * arriving through the door the golden test cannot see: a whole page
     * rendering wrongly with every gate green, because nothing captured it.
     */
    public function testEverySettingsSectionHasAGoldenPage(): void
    {
        $sections = [];
        foreach (\glob(\dirname(__DIR__, levels: 2).'/src/Settings/Section/*.php') as $file) {
            $sections[] = 'Porta\\Settings\\Section\\'.\basename($file, suffix: '.php');
        }
        \sort($sections);
        $mapped = \array_keys(self::SECTION_PAGES);
        \sort($mapped);

        $this->assertSame($mapped, $sections, 'a settings section appeared or moved: give it a page in SECTION_PAGES and PAGES, then regenerate the golden');
        $this->assertSame(
            [],
            \array_values(\array_diff(\array_values(self::SECTION_PAGES), self::PAGES)),
            'every section stands for a page the golden actually captures',
        );
    }

    /** Held so the destructors do not remove the dirs mid-test. */
    private TempProject $home;

    private TempProject $project;

    public function testEveryPageReadsAsItDid(): void
    {
        $actual = $this->renderPages();

        if (\getenv('UPDATE_SETTINGS_PAGES_GOLDEN')) {
            \file_put_contents(self::GOLDEN, \json_encode($actual, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            $this->markTestSkipped('Golden regenerated.');
        }

        $this->assertFileExists(self::GOLDEN, 'run once with UPDATE_SETTINGS_PAGES_GOLDEN=1 to seed');
        /** @var array<string, list<string>> $expected */
        $expected = \json_decode((string) \file_get_contents(self::GOLDEN), associative: true);

        // Page by page, so a failure names the page rather than the whole file.
        foreach ($expected as $page => $lines) {
            $this->assertArrayHasKey($page, $actual, "page {$page} missing");
            $this->assertSame($lines, $actual[$page], "page {$page} reads differently");
        }
        $this->assertSame(\array_keys($expected), \array_keys($actual), 'the page set changed');
    }

    /**
     * Each page's form, ANSI stripped and right-trimmed.
     *
     * Stripped on purpose — the sibling golden owns styling, and carrying
     * escape codes here would make every theme tweak look like a wording
     * change in the one file whose job is wording.
     *
     * @return array<string, list<string>>
     */
    private function renderPages(): array
    {
        // No PATH: the LSP page must not report whatever this machine happens
        // to have installed, or the golden is per-developer.
        \putenv('PATH=/nonexistent');
        $this->home = new TempProject(['.porta/config.json' => \json_encode(
            [
                'settings' => ['theme' => 'light', 'stickyScroll' => false],
                'languages' => ['php' => ['indentStyle' => 'tabs', 'indentSize' => 4]],
            ],
            \JSON_THROW_ON_ERROR,
        )]);
        $this->project = new TempProject([
            'A.php' => "<?php\n\$x = 1;\n",
            '.porta.json' => \json_encode(
                [
                    // Overrides the global theme — the only way a row can say
                    // "overrides global". The empty plugin list keeps the Plugins
                    // page to its fixed rows rather than whatever is bundled today.
                    'settings' => ['theme' => 'dark'],
                    'enabledPlugins' => [],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        ]);
        \putenv('PORTA_HOME='.$this->home->dir);
        // The Command line page names ~/.local/bin/porta: a fixed home keeps
        // that the same on every machine, and absent.
        $home = \getenv('HOME');
        \putenv('HOME=/nonexistent-home');

        $pages = [];
        try {
            $this->capturePages($pages);
        } finally {
            \putenv('HOME='.(string) $home);
        }

        return $pages;
    }

    /** @param array<string, list<string>> $pages */
    private function capturePages(array &$pages): void
    {
        $this->runIde(
            $this->project,
            $this->project->path('A.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide) use (&$pages): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $nav = $this->settingsNav($tui);
                    $form = $this->settingsForm($tui);

                    foreach (self::PAGES as $page) {
                        $this->selectSettingsSection($nav, $page);
                        $this->frame($tui);
                        $pages[$page] = $this->plainRows($form);
                    }
                },
            ],
            columns: 120,
            rows: 30, // the boxed pages; SettingsRowLayoutTest pins the compact form
        );
    }

    /** @return list<string> */
    private function plainRows(SettingsFormWidget $form): array
    {
        $lines = [];
        foreach (Paint::lines($form, new RenderContext(110, 22)) as $line) {
            $lines[] = \rtrim(AnsiUtils::stripAnsiCodes($line));
        }

        return $lines;
    }
}
