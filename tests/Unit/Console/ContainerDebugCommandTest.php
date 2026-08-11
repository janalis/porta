<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Console\ContainerDebugCommand;
use Porta\Container\Ansi;
use Porta\Container\IdeContainerFactory;
use Porta\Tests\Support\Probe;
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
        $this->assertStringContainsString('Porta\Layout\ResponsiveLayout {"priority":600}', $display, 'priority is contractual and shown');
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
        $this->assertStringContainsString('Porta\Editor\FileOpener -> Porta\Editor\BufferService', $display, 'the loader-derived single-impl aliases');
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
        $savedCache = getenv('PORTA_CONTAINER_CACHE');
        /** @var array<string, mixed> $saved */
        $saved = [];
        foreach ($_ENV as $key => $value) {
            if (\is_string($key) && str_starts_with($key, 'PORTA_')) {
                $saved[$key] = $value;
                unset($_ENV[$key]);
            }
        }
        putenv('PORTA_CONTAINER_CACHE=off');

        try {
            new IdeContainerFactory()->build('/tmp/porta-env-contract-test', IdeConfig::load('/tmp/porta-env-contract-test'), Ansi::Enabled);
            $seeded = [];
            foreach (array_keys($_ENV) as $key) {
                if (\is_string($key) && str_starts_with($key, 'PORTA_')) {
                    $seeded[] = $key;
                }
            }
        } finally {
            foreach (array_keys($_ENV) as $key) {
                if (\is_string($key) && str_starts_with($key, 'PORTA_')) {
                    unset($_ENV[$key]);
                }
            }
            foreach ($saved as $key => $value) {
                $_ENV[$key] = $value;
            }
            putenv(false === $savedCache ? 'PORTA_CONTAINER_CACHE' : 'PORTA_CONTAINER_CACHE='.$savedCache);
        }

        /** @var array<string, string> $defaults */
        $defaults = Probe::getStatic(ContainerDebugCommand::class, 'ENV_DEFAULTS');
        $this->assertEqualsCanonicalizing(
            [...array_keys($defaults), 'PORTA_ROOT_DIR'],
            $seeded,
            'ENV_DEFAULTS + PORTA_ROOT_DIR (set separately in execute()) must equal the keys the factory seeds',
        );
    }

    private function serviceCount(string $display): int
    {
        $matches = [];
        preg_match('/# (\d+) service\(s\)/', $display, $matches);

        return (int) ($matches[1] ?? 0);
    }
}
