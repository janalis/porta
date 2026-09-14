<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\OccurrenceHighlights;
use Porta\I18n\Translator;
use Porta\Lsp\HighlightController;
use Porta\Lsp\LspSessionPool;
use Porta\Support\Scheduler;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class HighlightControllerTest extends TestCase
{
    private CodeEditorWidget $editor;
    private FixedActiveFile $activeFile;
    private FakeLspClient $client;
    private OccurrenceHighlights $occurrences;

    /** @param array<string, mixed> $capabilities */
    private function controller(array $capabilities = ['documentHighlightProvider' => true], ?Scheduler $scheduler = null): HighlightController
    {
        $this->occurrences = new OccurrenceHighlights();
        $this->editor = new CodeEditorWidget(occurrences: $this->occurrences);
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $notifier = new Notifier(new StatusBarWidget(), $tui);
        $translator = new Translator('en');
        $pool = new LspSessionPool(
            '/p',
            $this->activeFile = new FixedActiveFile(),
            $translator,
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->client = new FakeLspClient($capabilities),
        );
        $pool->configure(
            ['php' => ['command' => ['php-ls'], 'name' => 'php-ls']],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );

        return new HighlightController($pool, $this->editor, $tui, $notifier, $translator, $this->occurrences, $scheduler);
    }

    private function readyOn(HighlightController $controller, string $path, string $text): void
    {
        $this->editor->showDocument($path, new Document($text));
        $this->activeFile->set($path);
        Probe::get($controller, 'lsp')->notifyDidOpen($path, $text, 'php', 1);
    }

    /** @return list<array{method: string, params: mixed, respond?: \Closure}> */
    private function highlightRequests(): array
    {
        return \array_values(\array_filter($this->client->requests, static fn (array $r): bool => 'textDocument/documentHighlight' === $r['method']));
    }

    public function testAServerWithoutTheCapabilityIsNeverAsked(): void
    {
        $controller = $this->controller(capabilities: []);
        $this->readyOn($controller, '/p/a.php', "<?php\n\$name = 1;\n");

        $controller->requestHighlights();

        $this->assertSame([], $this->highlightRequests(), 'a server that never advertised documentHighlight must not be asked');
    }

    public function testItAsksAtTheCaretAndHandsTheSpansToTheEditor(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$name = 1;\necho \$name;\n");
        $this->editor->getDocument()->setCursor(1, 2);

        $controller->requestHighlights();
        $request = $this->highlightRequests()[0];
        $this->assertSame(['line' => 1, 'character' => 2], $request['params']['position']);

        $request['respond']([
            ['range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 5]], 'kind' => 3],
            ['range' => ['start' => ['line' => 2, 'character' => 5], 'end' => ['line' => 2, 'character' => 10]], 'kind' => 2],
        ], error: null);

        $this->assertTrue(
            $this->occurrences->isFresh('/p/a.php', $this->editor->getDocument()->version, 1, 2),
            'the answer is stamped with the file, version and caret it was asked about',
        );
    }

    /** A caret parked on the same word must not re-ask on every settle. */
    public function testAParkedCaretIsNotAskedTwice(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$name = 1;\n");
        $this->editor->getDocument()->setCursor(1, 2);

        $controller->requestHighlights();
        $this->highlightRequests()[0]['respond']([], error: null);
        $controller->requestHighlights();

        $this->assertCount(1, $this->highlightRequests(), 'the answer on hand already covers this caret');
    }

    /** Moving the caret invalidates the stamp, so the next settle does ask again. */
    public function testMovingTheCaretAsksAgain(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$name = 1;\necho \$name;\n");
        $this->editor->getDocument()->setCursor(1, 2);

        $controller->requestHighlights();
        $this->highlightRequests()[0]['respond']([], error: null);
        $this->editor->getDocument()->setCursor(2, 6);
        $controller->requestHighlights();

        $this->assertCount(2, $this->highlightRequests());
    }

    /**
     * A held arrow key crosses many symbols; only where it stops is worth a
     * request, so the timer is re-armed rather than stacked.
     */
    public function testCaretMovesCoalesceIntoOneRequestOnceTheCaretSettles(): void
    {
        $scheduler = new FakeScheduler();
        $controller = $this->controller(scheduler: $scheduler);
        $this->readyOn($controller, '/p/a.php', "<?php\n\$name = 1;\necho \$name;\n");

        $doc = $this->editor->getDocument();
        foreach ([[1, 1], [1, 3], [2, 6]] as [$line, $col]) {
            $doc->setCursor($line, $col);
            $controller->caretMoved();
        }
        $this->assertSame([], $this->highlightRequests(), 'nothing is asked while the caret is still moving');
        $this->assertCount(2, $scheduler->cancelled, 'each new move cancelled the previous timer instead of stacking one');

        $scheduler->fire();
        $this->assertCount(1, $this->highlightRequests(), 'exactly one request once it settles');
    }

    /** An error response must leave the editor with no stamped answer, so the local scan keeps showing. */
    public function testAnErrorResponseLeavesNoStampedAnswer(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$name = 1;\n");
        $this->editor->getDocument()->setCursor(1, 2);

        $controller->requestHighlights();
        $this->highlightRequests()[0]['respond'](null, error: ['code' => -32_601, 'message' => 'unsupported']);

        $this->assertFalse($this->occurrences->isFresh('/p/a.php', $this->editor->getDocument()->version, 1, 2));
    }

    /**
     * A bare construction has no scheduler (the container always injects one):
     * the settle is skipped and the question is asked straight away, so a test
     * rig without a loop still exercises the real path.
     */
    public function testWithNoSchedulerTheCaretMoveAsksImmediately(): void
    {
        $controller = $this->controller();
        $this->readyOn($controller, '/p/a.php', "<?php\n\$name = 1;\n");
        $this->editor->getDocument()->setCursor(1, 2);

        $controller->caretMoved();

        $this->assertCount(1, $this->highlightRequests());
    }

    /**
     * The hook runs before EVERY frame, so a repaint with the caret where it
     * already was must not re-arm the settle timer — one that is re-armed every
     * frame fires never, and the feature would silently do nothing.
     */
    public function testARepaintWithTheCaretUnmovedDoesNotReArmTheTimer(): void
    {
        $scheduler = new FakeScheduler();
        $controller = $this->controller(scheduler: $scheduler);
        $this->readyOn($controller, '/p/a.php', "<?php\n\$name = 1;\n");
        $this->editor->getDocument()->setCursor(1, 2);

        $controller->caretMoved();
        $controller->caretMoved();
        $controller->caretMoved();

        $this->assertSame([], $scheduler->cancelled, 'a parked caret re-arms nothing');
        $scheduler->fire();
        $this->assertCount(1, $this->highlightRequests(), 'the one timer armed by the real move still fired');
    }
}
