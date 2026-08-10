<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Porta\Console\ContainerDebugCommand;
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

    private function serviceCount(string $display): int
    {
        $matches = [];
        preg_match('/# (\d+) service\(s\)/', $display, $matches);

        return (int) ($matches[1] ?? 0);
    }
}
