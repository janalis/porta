<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

use Janalis\Viz\GraphViz\DotCommand;
use Janalis\Viz\GraphViz\DotJsonParser;
use Janalis\Viz\GraphViz\DotPipeline;
use Janalis\Viz\GraphViz\GraphParseException;
use Janalis\Viz\Render\Format;
use Porta\Support\SingleSlotProcess;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The Graphviz-backed {@see LayoutEngine}: `dot -Tjson0` for geometry and
 * `dot -Tpng` for the picture, both as async child processes with DOT fed via
 * stdin (the onWritable feeder keeps large documents off the critical path — no
 * temp file, and the PNG comes back the same way). Success is keyed on the EXIT
 * CODE, never on stderr presence: dot writes warnings there and still exits 0,
 * and stderr must be captured regardless or a warning-spewing dot blocks on a
 * full pipe.
 *
 * Three independent slots (layout / render / export), each latest wins: a new
 * call cancels that slot's in-flight process (the TodoScanner single-slot
 * precedent), so a stale answer can never repaint over a newer graph, and a
 * zoom-driven re-render cannot cancel the geometry it is about to be drawn with. Pathological output sizes are bounded upstream — the
 * viewer refuses DOT documents over {@see DotFiles::MAX_BYTES} — and by PHP's
 * own memory limit, so no separate output cap is imposed here.
 *
 * With no `dot` installed, the picture and the exports are drawn natively by
 * viz ({@see NativeDiagram}); the geometry has no native source, so layout()
 * still answers that graphviz is missing and the tab is a picture alone.
 */
final readonly class DotLayoutEngine implements LayoutEngine
{
    /**
     * @param null|(\Closure(): ?string)|string $dotPath the "Graphviz dot path" setting; '' resolves to
     *                                                   null (auto-discover). A CLOSURE is read per call,
     *                                                   so a Settings edit applies without a restart — the
     *                                                   AbstractToolPlugin::binary() idiom
     */
    public function __construct(
        private string $rootDir,
        private \Closure|string|null $dotPath = null,
        private SingleSlotProcess $layoutSlot = new SingleSlotProcess(),
        private SingleSlotProcess $renderSlot = new SingleSlotProcess(),
        private SingleSlotProcess $exportSlot = new SingleSlotProcess(),
        private NativeDiagram $native = new NativeDiagram(new DotPipeline(), DotCommand::BASE_DPI),
    ) {}

    public function baseDpi(): int
    {
        return DotCommand::BASE_DPI;
    }

    public function binary(): ?string
    {
        // An explicit path (the dotPath setting) wins over discovery, the gitPath pattern.
        $configured = $this->dotPath instanceof \Closure ? ($this->dotPath)() : $this->dotPath;
        if (null !== $configured && '' !== $configured) {
            return $configured;
        }

        return new ExecutableFinder()->find('dot');
    }

    public function layout(string $dot, \Closure $onDone, \Closure $onError): void
    {
        $binary = $this->binary();
        if (null === $binary) {
            $onError('graphviz not found');

            return;
        }
        $this->layoutSlot->run(
            DotCommand::layout($binary),
            $this->rootDir,
            $dot,
            'dot',
            static function (string $stdout) use ($onDone, $onError): void {
                try {
                    $onDone(DotJsonParser::parse($stdout));
                } catch (GraphParseException $e) {
                    $onError($e->getMessage());
                }
            },
            $onError,
        );
    }

    public function render(string $source, int $dpi, \Closure $onPng, \Closure $onError): void
    {
        $binary = $this->binary();
        if (null === $binary) {
            $this->native->render($source, $dpi, $onPng, $onError);

            return;
        }
        $this->renderSlot->run(DotCommand::render($binary, Format::Png, $dpi), $this->rootDir, $source, 'dot', $onPng, $onError);
    }

    public function export(string $dot, Format $format, string $outPath, \Closure $onDone): void
    {
        $binary = $this->binary();
        if (null === $binary) {
            $this->native->export($dot, $format, $outPath, $onDone);

            return;
        }
        $this->exportSlot->run(
            [...DotCommand::render($binary, $format), '-o', $outPath],
            $this->rootDir,
            $dot,
            'dot',
            static function () use ($onDone): void {
                $onDone(null);
            },
            $onDone,
        );
    }

    /** Stop any in-flight processes (viewer teardown; idempotent). */
    public function cancel(): void
    {
        $this->layoutSlot->cancel();
        $this->renderSlot->cancel();
        $this->exportSlot->cancel();
        $this->native->cancel();
    }
}
