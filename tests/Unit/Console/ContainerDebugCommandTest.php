<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Console\ContainerDebugCommand;
use Porta\Container\Ansi;
use Porta\Container\IdeContainerFactory;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The wiring inspector's four views over the real config/services.php — the
 * same load-and-compile a boot performs (the ContainerAliasesTest precedent).
 */
final class ContainerDebugCommandTest extends TestCase
{
    private function tester(): CommandTester
    {
        return new CommandTester(new ContainerDebugCommand());
    }

    public function testTheCompiledViewListsSurvivingServices(): void
    {
        $tester = $this->tester();
        $status = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertMatchesRegularExpression('/^# \d+ service\(s\) — compiled \(runtime\) view/', $display);
        $this->assertStringContainsString('Porta\Editor\BufferService', $display);
        $this->assertStringContainsString('[synthetic', $display, 'the synthetics are flagged');
        $this->assertStringContainsString('porta.ide', $display, 'the one public id');
    }

    public function testTheLoadedViewShowsWhatTheCompilerWouldPrune(): void
    {
        $compiled = $this->tester();
        $compiled->execute([]);
        $loaded = $this->tester();
        $loaded->execute(['--loaded' => true]);

        $this->assertStringContainsString('loaded (pre-compile) view', $loaded->getDisplay());
        $this->assertGreaterThan(
            $this->serviceCount($compiled->getDisplay()),
            $this->serviceCount($loaded->getDisplay()),
            'the pre-compile view holds the definitions compile pruning removes',
        );
    }

    public function testTheTagViewCarriesTagAttributes(): void
    {
        $tester = $this->tester();
        $status = $tester->execute(['--tag' => 'porta.eager']);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('tagged porta.eager', $display);
        $this->assertMatchesRegularExpression('/Porta\\\\Layout\\\\ResponsiveLayout\s+\{"priority":600\}/', $display, 'priority is contractual and shown');
    }

    public function testATagNobodyCarriesListsNothing(): void
    {
        $tester = $this->tester();
        $tester->execute(['--tag' => 'porta.nonexistent']);

        $this->assertStringContainsString('# 0 service(s) tagged porta.nonexistent', $tester->getDisplay());
    }

    public function testTheAliasViewIsAlwaysPreCompile(): void
    {
        $tester = $this->tester();
        $status = $tester->execute(['--aliases' => true]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('alias(es) — loaded (pre-compile) view', $display);
        $this->assertMatchesRegularExpression('/Porta\\\\Editor\\\\FileOpener\s+Porta\\\\Editor\\\\BufferService/', $display, 'the loader-derived single-impl aliases');
    }

    public function testAnUnknownOptionIsSymfonysOwnError(): void
    {
        // Hand-rolled option parsing is gone: Symfony rejects unknown options
        // during input binding, with a real message instead of a usage dump.
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessageMatches('/--bogus/');
        $this->tester()->execute(['--bogus' => true]);
    }

    public function testTheConfigDirectoryDefaultsToThePackagesOwn(): void
    {
        $command = new ContainerDebugCommand();

        $this->assertSame('container', $command->getName());
        $this->assertFileExists(\dirname(__DIR__, levels: 3).'/config/services.php', 'the default config dir is where the command looks');
    }

    public function testEnvDefaultsMirrorExactlyWhatTheFactorySeeds(): void
    {
        // ENV_DEFAULTS and IdeContainerFactory::build()'s seeding are two
        // hand-maintained lists of ONE contract (the per-boot env channel), and
        // they had already drifted apart once (three plugin-tool keys). Observe
        // the factory's half by seeding into a scrubbed $_ENV, then require the
        // two sets to be identical in BOTH directions — a key seeded but not
        // mirrored fails, and a mirrored key no longer seeded fails too.
        $savedCache = \getenv('PORTA_CONTAINER_CACHE');
        /** @var array<string, mixed> $saved */
        $saved = [];
        foreach ($_ENV as $key => $value) {
            if (!(\is_string($key) && \str_starts_with($key, 'PORTA_'))) {
                continue;
            }

            $saved[$key] = $value;
            unset($_ENV[$key]);
        }
        \putenv('PORTA_CONTAINER_CACHE=off');

        try {
            new IdeContainerFactory()->build('/tmp/porta-env-contract-test', IdeConfig::load('/tmp/porta-env-contract-test'), Ansi::Enabled);
            $seeded = [];
            foreach (\array_keys($_ENV) as $key) {
                if (!(\is_string($key) && \str_starts_with($key, 'PORTA_'))) {
                    continue;
                }

                $seeded[] = $key;
            }
        } finally {
            // No `continue` in a finally block (the lint gate forbids control
            // flow there): filter first, then sweep.
            $portaKeys = \array_filter(\array_keys($_ENV), static fn (int|string $key): bool => \is_string($key) && \str_starts_with($key, 'PORTA_'));
            foreach ($portaKeys as $key) {
                unset($_ENV[$key]);
            }
            foreach ($saved as $key => $value) {
                $_ENV[$key] = $value;
            }
            \putenv(false === $savedCache ? 'PORTA_CONTAINER_CACHE' : 'PORTA_CONTAINER_CACHE='.$savedCache);
        }

        /** @var array<string, string> $defaults */
        $defaults = Probe::getStatic(ContainerDebugCommand::class, 'ENV_DEFAULTS');
        $this->assertEqualsCanonicalizing(
            [...\array_keys($defaults), 'PORTA_ROOT_DIR'],
            $seeded,
            'ENV_DEFAULTS + PORTA_ROOT_DIR (set separately in execute()) must equal the keys the factory seeds',
        );
    }

    public function testAnExplicitConfigDirIsUsedNotTheDefault(): void
    {
        // A custom configDir must actually WIN over the default — the
        // constructor's `?? dirname(...)` coalesce has this backwards if
        // dirname() runs unconditionally regardless of the arg.
        $project = new TempProject([
            'services.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                use Symfony\Component\DependencyInjection\ContainerBuilder;
                use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

                return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
                    $builder->register('custom.marker', \stdClass::class)->setPublic(true);
                };
                PHP,
        ]);

        $tester = new CommandTester(new ContainerDebugCommand($project->dir));
        $tester->execute([]);

        $this->assertStringContainsString('custom.marker', $tester->getDisplay());
        $this->assertStringNotContainsString('porta.ide', $tester->getDisplay(), 'the real services.php was not also loaded');
    }

    public function testRootDirIsSeededWithoutOverwritingCallerSetKeys(): void
    {
        // += only fills in MISSING keys — a caller-set PORTA_LOCALE must
        // survive, proving it's not a blind overwrite (`=`) of the whole
        // ENV_DEFAULTS block.
        $saved = $_ENV['PORTA_LOCALE'] ?? null;
        $_ENV['PORTA_LOCALE'] = 'zz-caller-set';

        try {
            $this->tester()->execute([]);
            $this->assertSame('zz-caller-set', $_ENV['PORTA_LOCALE'], 'a caller-set env value must not be overwritten');
        } finally {
            // @mago-expect lint:no-else-clause -- both branches restore the same key; an early return here would exit the finally block mid-cleanup, which reads worse than the symmetric if/else.
            if (null === $saved) {
                unset($_ENV['PORTA_LOCALE']);
            } else {
                $_ENV['PORTA_LOCALE'] = $saved;
            }
        }
    }

    public function testTheTagViewNeverAlsoPrintsTheServicesListing(): void
    {
        $tester = $this->tester();
        $tester->execute(['--tag' => 'porta.eager']);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('# ', $display);
        $this->assertStringNotContainsString('service(s) — ', $display, 'the tagged view must not fall through to printServices()');
    }

    public function testTheAliasViewNeverAlsoPrintsTheServicesListing(): void
    {
        $tester = $this->tester();
        $tester->execute(['--aliases' => true]);
        $display = $tester->getDisplay();

        $this->assertStringNotContainsString('service(s) — ', $display, 'the alias view must not fall through to printServices()');
    }

    public function testATagNobodyCarriesNeverPrintsATableAtAll(): void
    {
        $tester = $this->tester();
        $tester->execute(['--tag' => 'porta.nonexistent']);
        $display = $tester->getDisplay();

        $this->assertStringNotContainsString('Tag attributes', $display, 'an empty tag must skip the table entirely, not print an empty one');
    }

    public function testTheTaggedTableHasBothColumnHeaders(): void
    {
        $tester = $this->tester();
        $tester->execute(['--tag' => 'porta.eager']);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('Service', $display);
        $this->assertStringContainsString('Tag attributes', $display);
    }

    public function testAliasesAreListedInSortedOrder(): void
    {
        $tester = $this->tester();
        $tester->execute(['--aliases' => true]);
        $display = $tester->getDisplay();

        $lines = false !== \preg_split('/\R/', $display) ? \preg_split('/\R/', $display) : [];
        $aliasLines = \array_values(\array_filter(
            $lines,
            static fn (string $line): bool => 1 === \preg_match('/^Porta\\\\[A-Z]/', \trim($line)),
        ));
        $sorted = $aliasLines;
        \sort($sorted);
        $this->assertNotEmpty($aliasLines);
        $this->assertSame($sorted, $aliasLines, 'alias rows appear in sorted key order');
    }

    public function testTheAliasTableHasBothColumnHeaders(): void
    {
        $tester = $this->tester();
        $tester->execute(['--aliases' => true]);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('Alias', $display);
        $this->assertStringContainsString('Target', $display);
    }

    public function testServicesAreListedInSortedOrder(): void
    {
        $tester = $this->tester();
        $tester->execute([]);
        $display = $tester->getDisplay();

        $lines = false !== \preg_split('/\R/', $display) ? \preg_split('/\R/', $display) : [];
        $ids = \array_filter(
            $lines,
            static fn (string $line): bool => \str_starts_with(\trim($line), 'porta.'),
        );
        $sorted = \array_values($ids);
        \sort($sorted);
        $this->assertSame($sorted, \array_values($ids), 'porta.* service ids appear in sorted order');
    }

    public function testTheServicesTableHasAllThreeColumnHeaders(): void
    {
        $tester = $this->tester();
        $tester->execute([]);
        $display = $tester->getDisplay();

        // The three headers together, on the header row — a plain
        // "contains 'Service'" check is a trap here: several class names
        // (e.g. ServiceLocator) contain that substring on their own.
        $this->assertMatchesRegularExpression('/Service\s+Class\s+Flags/', $display);
    }

    public function testAServiceWhoseIdDiffersFromItsClassShowsTheRealClassNotAPlaceholder(): void
    {
        // porta.ide's id is NOT its own class name, so the Class column must
        // show the real FQCN — a broken `?? '?'` coalesce (or its flipped
        // ternary) would show '?' or the id itself instead.
        $tester = $this->tester();
        $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertMatchesRegularExpression('/porta\.ide\s+Symfony\\\\Component\\\\DependencyInjection\\\\ServiceLocator\b/', $display, 'a known non-self-named service shows its real class');
    }

    public function testASelfNamedServiceShowsABlankClassCellNotTheClassRepeated(): void
    {
        // A self-named service (id === class) that ALSO carries flags: the
        // ternary's true branch must produce '' for the class cell (not
        // repeat the class), so Flags sits right after the id with nothing
        // between — proven by requiring the flag to immediately follow.
        $tester = $this->tester();
        $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertMatchesRegularExpression('/Symfony\\\\Component\\\\Tui\\\\Tui\s+\[synthetic/', $display, 'a self-named service has a blank class cell, so Flags sits right after the id');
    }

    public function testASyntheticServiceIsFlaggedAndAnOrdinaryOneIsNot(): void
    {
        $tester = $this->tester();
        $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertMatchesRegularExpression('/Symfony\\\\Component\\\\Tui\\\\Tui\s+\[synthetic/', $display, 'Tui is synthetic and must carry the flag');
        $this->assertDoesNotMatchRegularExpression('/Porta\\\\Editor\\\\BufferService\s+\[synthetic/', $display, 'an ordinary service must not carry it');
    }

    public function testAFactoryDefinedServiceCarriesTheFactoryFlag(): void
    {
        $tester = $this->tester();
        $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('[factory]', $display, 'at least one service in the real container is factory-defined');
    }

    public function testFlagsAreWrappedInBracketsWithACommaSeparator(): void
    {
        $tester = $this->tester();
        $tester->execute([]);
        $display = $tester->getDisplay();

        // A synthetic AND public service closes its bracket — a missing
        // closing ']' would leave the flags list unterminated.
        $this->assertMatchesRegularExpression('/\[synthetic,public\]/', $display, $display);
    }

    private function serviceCount(string $display): int
    {
        $matches = [];
        \preg_match('/# (\d+) service\(s\)/', $display, $matches);

        return (int) ($matches[1] ?? 0);
    }
}
