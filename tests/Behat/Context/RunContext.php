<?php

declare(strict_types=1);

namespace Porta\Tests\Behat\Context;

use Behat\Behat\Context\Environment\InitializedContextEnvironment;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Janalis\Tuink\Context\TuinkAwareContextInterface;
use Janalis\Tuink\Context\TuinkAwareTrait;
use PHPUnit\Framework\Assert;
use Porta\Editor\CoverageMark;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Widgets;

/**
 * Run, debug and the viewers: the fakes the PHPUnit E2e rig drives them with
 * (the scripted DAP adapter, the fake graph tools, a stub phpunit), and what
 * the screen cannot show of them.
 */
final class RunContext implements TuinkAwareContextInterface
{
    use TuinkAwareTrait;

    private PortaContext $porta;

    /** @var array{srcX: int, srcY: int}|null */
    private ?array $inView = null;

    #[BeforeScenario]
    public function gatherContexts(BeforeScenarioScope $scope): void
    {
        $environment = $scope->getEnvironment();
        Assert::assertInstanceOf(InitializedContextEnvironment::class, $environment);
        $this->porta = $environment->getContext(PortaContext::class);
    }

    // --- The debugger -----------------------------------------------------------

    #[Given('the scripted debug adapter is configured for PHP')]
    public function theScriptedDebugAdapter(): void
    {
        $this->porta->mergeProjectConfig([
            'languages' => [
                'php' => ['debug' => [
                    'adapter' => \PHP_BINARY.' '.self::support('mock-dap-adapter.php'),
                ]],
            ],
        ]);
    }

    /**
     * The plugin's adapter script is the scripted adapter, and its "node" is
     * this PHP: what the plugin hands the debugger is what runs.
     */
    #[Given('the xdebug plugin runs the scripted debug adapter')]
    public function theXdebugPluginRunsTheScriptedAdapter(): void
    {
        $this->porta->mergeProjectConfig([
            'plugins' => ['xdebug' => [
                'nodeBinary' => \PHP_BINARY,
                'adapterPath' => self::support('mock-dap-adapter.php'),
            ]],
        ]);
    }

    #[When('I put the caret on line :line')]
    public function iPutTheCaretOnLine(int $line): void
    {
        // Go to Line, as a user would: Ctrl+G is the editor's binding.
        $this->porta->iUseTheAction('goto_line');
        $session = $this->getSession();
        $session->type((string) $line);
        $session->press('Enter');
        $session->waitUntil(
            fn (): bool => ($line - 1) === Widgets::editor($this->porta->tui())->getDocument()->cursorLine,
            \sprintf('The caret did not reach line %d.', $line),
        );
    }

    // --- Viewers ----------------------------------------------------------------

    /**
     * .porta.json, where "%fakes%" names tests/Support — the stand-ins for
     * dot, mmdc, plantuml and rsvg-convert the PHPUnit suites use — and
     * "%workspace%" the project.
     */
    #[Given('the project configuration naming the test fakes:')]
    public function theProjectConfigurationNamingTheFakes(PyStringNode $json): void
    {
        $raw = \str_replace(
            ['%fakes%', '%workspace%'],
            [\dirname(__DIR__, 2).'/Support', $this->getSession()->getWorkspace()->path],
            $json->getRaw(),
        );
        $this->porta->mergeProjectConfig(self::object(\json_decode($raw, associative: true, flags: \JSON_THROW_ON_ERROR)));
    }

    /**
     * An executable at $path that runs the stand-in $fake, after writing what
     * it was asked into "$path.calls": a picture alone cannot say which tool
     * drew it, the calls file can.
     */
    #[Given('a recording stand-in :path for :fake')]
    public function aRecordingStandIn(string $path, string $fake): void
    {
        $workspace = $this->getSession()->getWorkspace();
        $script = \sprintf(
            <<<'PHP_WRAP'
                #!%s
                <?php
                file_put_contents(__FILE__.'.calls', implode(' ', array_slice($argv, 1))."\n", FILE_APPEND);
                passthru(implode(' ', array_map('escapeshellarg', [PHP_BINARY, %s, ...array_slice($argv, 1)])), $code);
                exit($code);
                PHP_WRAP,
            \PHP_BINARY,
            \var_export(self::support($fake), true),
        );
        $workspace->write($path, $script);
        \chmod($workspace->resolve($path), 0o755);
    }

    /**
     * The machine's own dot, mmdc, plantuml and converters are out of reach:
     * only what a scenario names runs.
     */
    #[Given('only PHP is on the PATH')]
    public function onlyPhpIsOnThePath(): void
    {
        // In the workspace: a shared folder would keep a link to whichever PHP ran first, and two runs would race on it
        $workspace = $this->getSession()->getWorkspace();
        $workspace->createDirectory('.php-only');
        $bin = $workspace->resolve('.php-only');
        \symlink(\PHP_BINARY, $bin.'/php');
        $session = $this->getSession();
        $session->setLaunch($session->getLaunch()->withEnv('PATH', $bin));
    }

    /**
     * A PNG of $width by $height pixels: its left half red, its right half blue.
     */
    #[Given('an image :file of :width by :height pixels')]
    public function anImage(string $file, int $width, int $height): void
    {
        $gd = \imagecreatetruecolor($width, $height);
        \imagefilledrectangle($gd, 0, 0, \intdiv($width, 2) - 1, $height - 1, (int) \imagecolorallocate($gd, 255, 0, 0));
        \imagefilledrectangle($gd, \intdiv($width, 2), 0, $width - 1, $height - 1, (int) \imagecolorallocate($gd, 0, 0, 255));
        \ob_start();
        \imagepng($gd);
        $this
            ->getSession()
            ->getWorkspace()
            ->write($file, (string) \ob_get_clean());
    }

    /**
     * Past the editor's 2 MB: the first line, then comment lines to the size.
     */
    #[Given('a file :file of more than 2 MB beginning with :line')]
    public function aFileOfMoreThanTwoMegabytes(string $file, string $line): void
    {
        $this
            ->getSession()
            ->getWorkspace()
            ->write($file, $line."\n".\str_repeat("// padding the file past what the editor takes\n", 50_000));
    }

    #[When('I note which part of the picture is in view')]
    public function iNoteThePartInView(): void
    {
        $this->inView = $this->partInView();
    }

    /**
     * The picture's window moved: it shows more of the picture's $direction.
     */
    #[Then('the view should have moved :direction')]
    public function theViewShouldHaveMoved(string $direction): void
    {
        $before = $this->inView ?? throw new \LogicException('Note the part in view first.');
        [$axis, $sign] = match ($direction) {
            'left' => ['srcX', -1],
            'right' => ['srcX', 1],
            'up' => ['srcY', -1],
            'down' => ['srcY', 1],
            default => throw new \InvalidArgumentException(\sprintf('No direction "%s".', $direction)),
        };
        $this->getSession()->waitUntil(
            fn (): bool => ($sign * ($this->partInView()[$axis] - $before[$axis])) > 0,
            fn (): string => \sprintf(
                'The view did not move %s: it was at %s, it is at %s.',
                $direction,
                \json_encode($before, \JSON_THROW_ON_ERROR),
                \json_encode($this->partInView(), \JSON_THROW_ON_ERROR),
            ),
        );
    }

    // --- Tests and coverage -------------------------------------------------------

    /**
     * The change strip's coverage bars of the file in front: "covered",
     * "uncovered", or "none" for a line with no bar.
     */
    #[Then('line :line should be marked :mark in the coverage strip')]
    public function lineShouldBeMarked(int $line, string $mark): void
    {
        $expected = match ($mark) {
            'covered' => CoverageMark::Covered,
            'uncovered' => CoverageMark::Uncovered,
            'none' => null,
            default => throw new \InvalidArgumentException(\sprintf('No coverage mark "%s".', $mark)),
        };
        $this->getSession()->waitUntil(
            fn (): bool => $expected === ($this->coverage()[$line - 1] ?? null),
            fn (): string => \sprintf(
                'Line %d is not marked %s; the strip holds %s.',
                $line,
                $mark,
                \json_encode(\array_map(static fn (CoverageMark $m): string => $m->name, $this->coverage()), \JSON_THROW_ON_ERROR),
            ),
        );
    }

    #[Then('the coverage strip should be empty')]
    public function theCoverageStripShouldBeEmpty(): void
    {
        $this->getSession()->waitUntil(fn (): bool => [] === $this->coverage(), 'The coverage strip still shows bars.');
    }

    /** @return array<int, CoverageMark> */
    private function coverage(): array
    {
        return Widgets::editor($this->porta->tui())->decorations->coverage;
    }

    /**
     * Where the focused picture's window starts, in the picture's own pixels.
     *
     * @return array{srcX: int, srcY: int}
     */
    private function partInView(): array
    {
        $pane = $this->porta->tui()->getFocus() ?? throw new \LogicException('Nothing has the focus.');
        $view = Probe::object($pane, 'view');
        if (!\method_exists($view, 'visibleRect')) {
            throw new \LogicException(\sprintf('The focused %s shows no picture.', $pane::class));
        }
        /** @var array{srcX: int, srcY: int} $rect */
        $rect = $view->visibleRect(self::int(Probe::get($pane, 'lastCols')), 2 * self::int(Probe::get($pane, 'lastRows')));

        return ['srcX' => $rect['srcX'], 'srcY' => $rect['srcY']];
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) ? $value : throw new \LogicException('Not a whole number: '.\get_debug_type($value));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function object(mixed $decoded): array
    {
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('The configuration must be a JSON object.');
        }

        return $decoded;
    }

    private static function support(string $file): string
    {
        return \dirname(__DIR__, 2).'/Support/'.$file;
    }
}
