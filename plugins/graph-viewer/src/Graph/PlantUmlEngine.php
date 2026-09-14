<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

use Porta\Support\SingleSlotProcess;

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
    ) {}

    public function baseDpi(): int
    {
        return PlantUmlCommand::BASE_DPI;
    }

    public function binary(): ?string
    {
        return PlantUmlCommand::tool($this->toolPath instanceof \Closure ? ($this->toolPath)() : $this->toolPath);
    }

    public function render(string $source, int $dpi, \Closure $onPng, \Closure $onError): void
    {
        $tool = $this->binary();
        if (null === $tool) {
            $onError('plantuml not found');

            return;
        }
        // -failfast2 puts syntax errors on stderr rather than in the picture.
        $this->slot->run(PlantUmlCommand::argv($tool, $dpi), $this->rootDir, $source, 'plantuml', $onPng, $onError);
    }

    /** Stop any in-flight render (viewer teardown; idempotent). */
    public function cancel(): void
    {
        $this->slot->cancel();
    }
}
