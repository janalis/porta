<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

use Porta\Support\AsyncProcess;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The PlantUML-backed {@see DiagramEngine}: the diagram on stdin, PNG off
 * stdout, one slot with latest-wins — {@see DotLayoutEngine}'s render half,
 * with a different tool behind it and no geometry to offer.
 *
 * It is a JVM, so a render costs about a second of startup before it draws
 * anything. That is why the viewer's requested resolution ceilings to whole
 * multiples and its request is debounced: a whole zoom gesture costs one start
 * per doubling, and the previous picture stays on screen while it runs.
 *
 * Note PlantUML shells out to Graphviz itself for several diagram types (class,
 * activity, state); sequence diagrams and a few others it draws alone. A
 * machine without `dot` therefore renders some of these documents and not
 * others, and the tool says so on stderr — which is what the pane's banner
 * shows.
 */
final class PlantUmlEngine implements DiagramEngine
{
    private ?AsyncProcess $running = null;

    public function __construct(
        private readonly string $rootDir,
        // The "PlantUML" setting: a launcher, a .jar, or '' to auto-discover.
        #[Autowire('%env(default::PORTA_PLANTUML)%')]
        private readonly ?string $toolPath = null,
    ) {}

    public function baseDpi(): int
    {
        return PlantUmlCommand::BASE_DPI;
    }

    public function binary(): ?string
    {
        return PlantUmlCommand::tool($this->toolPath);
    }

    public function render(string $source, int $dpi, \Closure $onPng, \Closure $onError): void
    {
        $tool = $this->binary();
        if (null === $tool) {
            $onError('plantuml not found');

            return;
        }
        $this->running?->cancel();
        $process = new AsyncProcess(PlantUmlCommand::argv($tool, $dpi), $this->rootDir, stdin: $source, captureStderr: true);
        $this->running = $process;
        $started = $process->start(function (int $code, string $stdout) use ($process, $onPng, $onError): void {
            if ($this->running === $process) {
                $this->running = null;
            }
            if (0 !== $code) {
                // -failfast2 puts syntax errors here rather than in the picture.
                $stderr = trim($process->errorOutput());
                $onError('' !== $stderr ? $stderr : "plantuml failed (exit {$code})");

                return;
            }
            $onPng($stdout);
        });
        if (!$started) {
            // proc_open spawns even unusable binaries (exec failure surfaces as
            // an exit code), so this is unreachable in tests.
            // @codeCoverageIgnoreStart
            $this->running = null;
            $onError('could not start plantuml');

            // @codeCoverageIgnoreEnd
        }
    }

    /** Stop any in-flight render (viewer teardown; idempotent). */
    public function cancel(): void
    {
        $this->running?->cancel();
        $this->running = null;
    }
}
