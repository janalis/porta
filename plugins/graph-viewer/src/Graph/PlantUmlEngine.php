<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

use Janalis\Viz\PlantUML\PlantUmlCommand;
use Janalis\Viz\Process\Tool;
use Janalis\Viz\Render\Format;
use Porta\Support\SingleSlotProcess;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

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
final readonly class PlantUmlEngine implements DiagramEngine
{
    /**
     * @param null|(\Closure(): ?string)|string $toolPath the "PlantUML" setting: a launcher, a .jar, or ''
     *                                                    to auto-discover. A CLOSURE is read per call, so a
     *                                                    Settings edit applies without a restart
     */
    public function __construct(
        private string $rootDir,
        private \Closure|string|null $toolPath = null,
        private SingleSlotProcess $slot = new SingleSlotProcess(),
        private SingleSlotProcess $exportSlot = new SingleSlotProcess(),
        private Filesystem $files = new Filesystem(),
    ) {}

    public function baseDpi(): int
    {
        return PlantUmlCommand::BASE_DPI;
    }

    public function binary(): ?string
    {
        return new Tool('plantuml', $this->toolPath instanceof \Closure ? ($this->toolPath)() : $this->toolPath)->path();
    }

    public function render(string $source, int $dpi, \Closure $onPng, \Closure $onError): void
    {
        $tool = $this->binary();
        if (null === $tool) {
            $onError('plantuml not found');

            return;
        }
        // -failfast2 puts syntax errors on stderr rather than in the picture.
        $this->slot->run(PlantUmlCommand::render($tool, Format::Png, $dpi), $this->rootDir, $source, 'plantuml', $onPng, $onError);
    }

    /**
     * Render $source to $outPath. The file is written only once plantuml has
     * answered in full, so a failed export leaves an earlier one intact.
     *
     * @param \Closure(?string): void $onDone null on success, the error text on failure
     */
    public function export(string $source, Format $format, string $outPath, \Closure $onDone): void
    {
        $tool = $this->binary();
        if (null === $tool) {
            $onDone('plantuml not found');

            return;
        }
        $this->exportSlot->run(
            PlantUmlCommand::render($tool, $format),
            $this->rootDir,
            $source,
            'plantuml',
            function (string $bytes) use ($outPath, $onDone): void {
                try {
                    $this->files->dumpFile($outPath, $bytes);
                } catch (IOExceptionInterface $exception) {
                    $onDone($exception->getMessage());

                    return;
                }
                $onDone(null);
            },
            $onDone,
        );
    }

    /** Stop any in-flight render or export (viewer teardown; idempotent). */
    public function cancel(): void
    {
        $this->slot->cancel();
        $this->exportSlot->cancel();
    }
}
