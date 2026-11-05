<?php

declare(strict_types=1);

namespace Porta\Tests\E2e\Screenshots;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Ide;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Screenshots\Screenshots;
use Porta\Tests\Support\Screenshots\ShopProject;
use Porta\Tests\Support\Screenshots\TakesScreenshots;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Tests\Support\Widgets;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The screenshot at the top of each bundled plugin's guide, `plugin-<name>`.
 *
 * A plugin with a screen of its own is shown on it. One that wraps a tool or a
 * server — whose effect is a diagnostic, a format, a session, and needs the
 * real binary — is shown on its Settings page, which is where its guide sends
 * the reader first.
 */
final class PluginScreenshotsTest extends TestCase
{
    use NavigatesSettings;
    use PreservesPath;
    use PreservesPortaHome;
    use RunsIde;
    use TakesScreenshots;
    use UsesBundledPlugins;

    private const int COLUMNS = 140;
    private const int ROWS = 36;

    protected function setUp(): void
    {
        \putenv('PATH=/nonexistent');
        // A picture goes out as itself, not as half blocks: the screenshot
        // draws the PNG the IDE sent, as a kitty-protocol terminal would.
        \putenv('PORTA_GRAPHICS=kitty');
    }

    protected function tearDown(): void
    {
        \putenv('PORTA_GRAPHICS');
    }

    /** @return iterable<string, array{string}> */
    public static function settingsPagePlugins(): iterable
    {
        foreach (Screenshots::SETTINGS_PAGES as $plugin) {
            yield $plugin => [$plugin];
        }
    }

    #[DataProvider('settingsPagePlugins')]
    public function testSettingsPage(string $plugin): void
    {
        $project = ShopProject::create();
        $this->runIde(
            $project,
            $project->path('src/Order/Order.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($plugin): void {
                    $this->awaitLspReady($tui, $terminal, ShopProject::SERVER);
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $this->awaitPainted($tui, $terminal, 'Auto-pairs');
                    $this->selectSettingsSection($this->settingsNav($tui), 'plugin:'.$plugin);
                    $this->awaitPainted($tui, $terminal, 'Plugins › '.$plugin);
                    $this->screenshot('plugin-'.$plugin, $tui, $terminal, $ide);
                },
            ],
            columns: self::COLUMNS,
            rows: self::ROWS,
        );
    }

    public function testGit(): void
    {
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH); // git must be reachable
        $project = ShopProject::create();
        $project->initGit(name: 'Ada', email: 'ada@example.com');
        $project->commitAll('Orders, prices and the checkout');
        $project->write('src/Order/Order.php', \str_replace(
            "    public function isEmpty(): bool\n",
            replace: "    public function count(): int\n    {\n        return \\count(\$this->lines);\n    }\n\n    public function isEmpty(): bool\n",
            subject: ShopProject::files()['src/Order/Order.php'],
        ));
        $project->write('src/Order/Discount.php', "<?php\n\nnamespace Acme\\Shop\\Order;\n\nenum Discount: int\n{\n    case None = 0;\n    case Member = 10;\n}\n");
        $this->runIde(
            $project,
            $project->path('src/Order/Order.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitLspReady($tui, $terminal, ShopProject::SERVER);
                    Services::resolve($ide, CommandRegistry::class)->run('ide_git');
                    $this->awaitPainted($tui, $terminal, 'Discount.php');
                    // Onto the first change, so the diff pane shows it.
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[A");
                    $this->awaitPainted($tui, $terminal, 'count(): int');
                    $this->screenshot('plugin-git', $tui, $terminal, $ide);
                },
            ],
            columns: self::COLUMNS,
            rows: self::ROWS,
        );
    }

    public function testTodo(): void
    {
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH); // grep runs the scan
        $project = ShopProject::create(['src/Pricing/Money.php' => \str_replace(
            '    public function times(int $factor): self',
            replace: "    // FIXME: round half to even, as the accountants asked\n".'    public function times(int $factor): self',
            subject: ShopProject::files()['src/Pricing/Money.php'],
        )]);
        $this->runIde(
            $project,
            $project->path('src/Checkout/CheckoutController.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitLspReady($tui, $terminal, ShopProject::SERVER);
                    $tui->handleInput("\x1b8"); // alt+8
                    $this->awaitPainted($tui, $terminal, 'round half to even');
                    $this->awaitPainted($tui, $terminal, 'send the receipt');
                    $this->screenshot('plugin-todo', $tui, $terminal, $ide);
                },
            ],
            columns: self::COLUMNS,
            rows: self::ROWS,
        );
    }

    public function testPhpUnit(): void
    {
        // The shop's own OrderTest, and a second test that fails, run through
        // this repository's PHPUnit — behind a binary outside the project, so
        // the tree shows no vendor/, with the shop's classes autoloaded.
        $project = ShopProject::create(
            ['tests/OrderTest.php' => \str_replace(
                "        self::assertSame(4900, \$order->total()->cents);\n    }\n",
                replace: "        self::assertSame(4900, \$order->total()->cents);\n    }\n\n    public function testAMemberGetsTenPercentOff(): void\n    {\n        \$order = new Order('A-1002', new Customer('Grace', 'grace@example.com'));\n        \$order->add(new Product('MUG', 'Mug', Money::of(1200, 'EUR')), 2);\n\n        self::assertSame(2160, \$order->total()->cents);\n    }\n",
                subject: ShopProject::files()['tests/OrderTest.php'],
            )],
        );
        $binary = \dirname($project->dir).'/phpunit';
        \file_put_contents($binary, \strtr(
            <<<'STUB'
                #!{php}
                <?php
                spl_autoload_register(static function (string $class): void {
                    $file = {root}.'/src/'.str_replace('\\', '/', substr($class, 10)).'.php';
                    if (str_starts_with($class, 'Acme\\Shop\\') && is_file($file)) {
                        require $file;
                    }
                });
                require {phpunit};

                STUB,
            [
                '{php}' => \PHP_BINARY,
                '{root}' => \var_export($project->dir, return: true),
                '{phpunit}' => \var_export(\dirname(__DIR__, levels: 3).'/vendor/phpunit/phpunit/phpunit', return: true),
            ],
        ));
        \chmod($binary, permissions: 0o755);
        \file_put_contents(\dirname($project->dir).'/home/.porta/config.json', \json_encode(
            [
                'lsp' => \json_decode((string) \file_get_contents(\dirname($project->dir).'/home/.porta/config.json'), associative: true, flags: \JSON_THROW_ON_ERROR)['lsp'],
                'enabledPlugins' => [...IdeConfig::DEFAULT_ENABLED_PLUGINS, 'tempest', 'phpunit'],
                'plugins' => ['phpunit' => ['binary' => $binary]],
            ],
            \JSON_THROW_ON_ERROR,
        ));
        $this->runIde(
            $project,
            $project->path('tests/OrderTest.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitLspReady($tui, $terminal, ShopProject::SERVER);
                    Services::resolve($ide, CommandRegistry::class)->run('phpunit:run_file');
                    $this->awaitPainted($tui, $terminal, '1 of 2 tests failed');
                    $tui->handleInput("\r"); // Enter on the failure, which leads the dock: to its assertion
                    $this->awaitPainted($tui, $terminal, 'testAMemberGetsTenPercentOff(): void');
                    $this->awaitPainted($tui, $terminal, '✓ test passed');
                    $this->screenshot('plugin-phpunit', $tui, $terminal, $ide);
                },
            ],
            columns: self::COLUMNS,
            rows: self::ROWS,
        );
    }

    public function testLocalHistory(): void
    {
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH); // diff draws the comparison
        $project = ShopProject::create();
        $this->runIde(
            $project,
            $project->path('src/Pricing/Money.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitLspReady($tui, $terminal, ShopProject::SERVER);
                    $document = Widgets::editor($tui)->getDocument();
                    $document->setCursor(6, 0); // above the class
                    $document->insert("/** An amount of money, in cents. */\n");
                    $tui->handleInput("\x13"); // ctrl+s: the older snapshot
                },
                200 => static function (Tui $tui): void {
                    $document = Widgets::editor($tui)->getDocument();
                    $document->setCursor(41, 0); // after format()
                    $document->insert("\n    public function isZero(): bool\n    {\n        return 0 === \$this->cents;\n    }\n");
                    $tui->handleInput("\x13"); // ctrl+s: the newer one
                },
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('local-history:compare_snapshot');
                    $this->awaitPainted($tui, $terminal, 'Compare with Snapshot');
                    $tui->handleInput("\x1b[B"); // the older snapshot
                    $tui->handleInput("\r");
                    $this->awaitPainted($tui, $terminal, 'Local History Diff');
                    $this->screenshot('plugin-local-history', $tui, $terminal, $ide);
                },
            ],
            columns: self::COLUMNS,
            rows: self::ROWS,
        );
    }

    public function testImageViewer(): void
    {
        $project = ShopProject::create(['assets/logo.png' => (string) \file_get_contents(\dirname(__DIR__, levels: 3).'/docs/public/logo.png')]);
        $this->runIde(
            $project,
            $project->path('assets/logo.png'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, '512 × 512');
                    $this->screenshot('plugin-image-viewer', $tui, $terminal, $ide);
                },
            ],
            columns: self::COLUMNS,
            rows: self::ROWS,
        );
    }

    public function testGraphViewer(): void
    {
        $project = ShopProject::create();
        $this->runIde(
            $project,
            $project->path('src/Order/Order.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitLspReady($tui, $terminal, ShopProject::SERVER);
                    Services::resolve($ide, CommandRegistry::class)->run('graph-viewer:architecture');
                    $this->awaitPainted($tui, $terminal, 'Fit (');
                    $this->screenshot('plugin-graph-viewer', $tui, $terminal, $ide);
                },
            ],
            columns: self::COLUMNS,
            rows: self::ROWS,
        );
    }

    public function testDiff(): void
    {
        // A merge stopped on a conflict: the file holds both sides.
        $conflicted = \str_replace(
            "        \$total = Money::zero('EUR');\n",
            replace: "<<<<<<< HEAD\n        \$total = Money::zero(\$this->customer->currency());\n=======\n        \$total = Money::zero('EUR');\n        \$this->assertNotEmpty();\n>>>>>>> feature/discounts\n",
            subject: ShopProject::files()['src/Order/Order.php'],
        );
        $project = ShopProject::create(['src/Order/Order.php' => $conflicted]);
        $this->runIde(
            $project,
            $project->path('src/Order/Order.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitLspReady($tui, $terminal, ShopProject::SERVER);
                    $this->awaitPainted($tui, $terminal, 'Split');
                    foreach (\range(1, end: 40) as $ignored) {
                        $tui->handleInput("\x1b[B"); // down past the conflict, so it scrolls into view
                    }
                    $this->awaitPainted($tui, $terminal, 'feature/discounts');
                    $this->screenshot('plugin-diff', $tui, $terminal, $ide);
                },
            ],
            columns: self::COLUMNS,
            rows: self::ROWS,
        );
    }

    public function testReview(): void
    {
        $project = ShopProject::create(
            [
                '.porta/reviews/agent/review.json' => '{"title":"Checkout: charge the order total"}',
                '.porta/reviews/agent/t1/thread.json' => \json_encode(
                    ['path' => 'src/Checkout/CheckoutController.php', 'line' => 23, 'lineText' => '        $receipt = $this->payments->charge($order->customer, $order->total());'],
                    \JSON_THROW_ON_ERROR,
                ),
                '.porta/reviews/agent/t1/20261102T100000Z--claude.md' => "`total()` walks every line, and runs twice per checkout. Compute it once and pass it on.\n",
                '.porta/reviews/agent/t2/thread.json' => \json_encode(
                    ['path' => 'src/Checkout/CheckoutController.php', 'line' => 18, 'lineText' => '        if ($order->isEmpty()) {'],
                    \JSON_THROW_ON_ERROR,
                ),
                '.porta/reviews/agent/t2/20261102T100500Z--ada.md' => "Should an empty basket be a 400 rather than a message?\n",
            ],
            ['enabledPlugins' => [...IdeConfig::DEFAULT_ENABLED_PLUGINS, 'tempest', 'review'], 'plugins' => ['review' => ['provider' => 'local']]],
        );
        $this->runIde(
            $project,
            $project->path('src/Checkout/CheckoutController.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitLspReady($tui, $terminal, ShopProject::SERVER);
                    Services::resolve($ide, CommandRegistry::class)->run('review:open');
                    $this->awaitPainted($tui, $terminal, 'Review (2 open)');
                    // Down from the conversation to the first thread, so its comment shows.
                    $tui->handleInput("\x1b[B");
                    $this->awaitPainted($tui, $terminal, 'rather than a');
                    $this->screenshot('plugin-review', $tui, $terminal, $ide);
                },
            ],
            columns: self::COLUMNS,
            rows: self::ROWS,
        );
    }
}
