<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Porta\Debug\InlineValues;
use Porta\Editor\Document;
use Porta\I18n\Translator;
use Porta\Lsp\InlineValueRequest;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The join: the language server says WHERE the variables are and what they are
 * called, the debug adapter says what they hold, and neither half is worth
 * drawing alone.
 *
 * The request is stubbed here — its own gate and wire shape are pinned in
 * {@see \Porta\Tests\Unit\Lsp\InlineValueRequestTest} — so these tests are
 * about what happens once both answers are in hand, which is where a join goes
 * wrong.
 */
final class InlineValuesTest extends TestCase
{
    private CodeEditorWidget $editor;

    private FakeLspClient $client;

    private Tui $tui;

    /** @var list<array{line: int, endCol: int, name: string}> the answer the server gives back */
    private array $lookups = [];

    /**
     * Wired to a fake server that offers `inlineValueProvider`, so the request
     * really goes out and its answer really comes back — the delivery closure
     * is where a join drops one half on the floor, and a stub in its place
     * would test the two halves and not the seam between them.
     */
    private function values(): InlineValues
    {
        $this->editor = new CodeEditorWidget();
        $this->editor->showDocument('/p/a.php', new Document("<?php\n\$count = 41;\n\$name = 'x';\n"));

        $pool = new LspSessionPool(
            '/p',
            $active = new FixedActiveFile(),
            new Translator('en'),
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->client = new FakeLspClient(['inlineValueProvider' => true]),
        );
        $pool->configure(
            ['php' => ['command' => ['php-ls'], 'name' => 'php-ls']],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );
        $active->set('/p/a.php');
        $pool->notifyDidOpen('/p/a.php', "<?php\n", 'php', 1);

        return new InlineValues(
            new InlineValueRequest($pool),
            $this->editor->decorations(),
            $this->tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 10)),
        );
    }

    /** Stop, and let the server answer with {@see $lookups}. */
    private function serverAnswered(InlineValues $values): void
    {
        $values->stoppedAt('/p/a.php', 1, frameId: 1);
        $this->client->requests[0]['respond'](array_map(static fn (array $l): array => [
            'range' => ['start' => ['line' => $l['line'], 'character' => 0], 'end' => ['line' => $l['line'], 'character' => $l['endCol']]],
            'variableName' => $l['name'],
        ], $this->lookups));
    }

    /** @return array<int, string> */
    private function painted(): array
    {
        /** @var array<int, string> */
        return $this->editor->decorations()->debugValues();
    }

    public function testAVariableIsShownAgainstTheLineThatAssignsIt(): void
    {
        $this->lookups = [['line' => 1, 'endCol' => 6, 'name' => '$count']];
        $values = $this->values();
        $this->serverAnswered($values);

        $values->addVariables([['name' => '$count', 'value' => '41', 'variablesReference' => 0]]);

        $this->assertSame([1 => '$count = 41'], $this->painted());
    }

    public function testAVariableTheADAPTERNeverReportedIsNotInvented(): void
    {
        // The server names every variable in the range; the adapter reports the
        // ones in scope. A name with no value is a variable that has not been
        // assigned yet at this point in the frame.
        $this->lookups = [
            ['line' => 1, 'endCol' => 6, 'name' => '$count'],
            ['line' => 2, 'endCol' => 5, 'name' => '$name'],
        ];
        $values = $this->values();
        $this->serverAnswered($values);

        $values->addVariables([['name' => '$count', 'value' => '41', 'variablesReference' => 0]]);

        $this->assertSame([1 => '$count = 41'], $this->painted());
    }

    public function testTwoVariablesOnALineReadLeftToRight(): void
    {
        // The order the code assigns them in, not the order the adapter happens
        // to deliver them: scopes arrive asynchronously and in no order at all.
        $this->lookups = [
            ['line' => 1, 'endCol' => 20, 'name' => '$second'],
            ['line' => 1, 'endCol' => 6, 'name' => '$first'],
        ];
        $values = $this->values();
        $this->serverAnswered($values);

        $values->addVariables([
            ['name' => '$second', 'value' => '2', 'variablesReference' => 0],
            ['name' => '$first', 'value' => '1', 'variablesReference' => 0],
        ]);

        $this->assertSame([1 => '$first = 1, $second = 2'], $this->painted());
    }

    public function testEachSCOPEDeliveryAddsToWhatIsKnown(): void
    {
        // Locals and globals arrive as separate `variables` responses, and the
        // protocol never says which is last — so every delivery repaints with
        // everything known so far rather than replacing it.
        $this->lookups = [
            ['line' => 1, 'endCol' => 6, 'name' => '$count'],
            ['line' => 2, 'endCol' => 5, 'name' => '$name'],
        ];
        $values = $this->values();
        $this->serverAnswered($values);

        $values->addVariables([['name' => '$count', 'value' => '41', 'variablesReference' => 0]]);
        $values->addVariables([['name' => '$name', 'value' => "'x'", 'variablesReference' => 0]]);

        $this->assertSame([1 => '$count = 41', 2 => "\$name = 'x'"], $this->painted());
    }

    public function testCONTINUINGTakesTheValuesWithIt(): void
    {
        // They were facts about a frame that is running again. Left on screen
        // they are worse than nothing: they look live.
        $this->lookups = [['line' => 1, 'endCol' => 6, 'name' => '$count']];
        $values = $this->values();
        $this->serverAnswered($values);
        $values->addVariables([['name' => '$count', 'value' => '41', 'variablesReference' => 0]]);

        $values->clear();

        $this->assertSame([], $this->painted());
    }

    public function testVariablesArrivingBeforeTheSERVERAnswersPaintNothingYet(): void
    {
        // The two round-trips race. The adapter is usually first — it is one
        // process hop, the server may be indexing — and a delivery with no
        // lookups yet must not paint an empty map over a previous frame's
        // values, nor throw.
        $values = $this->values();
        $values->stoppedAt('/p/a.php', 1, frameId: 1); // asked, not yet answered

        $values->addVariables([['name' => '$count', 'value' => '41', 'variablesReference' => 0]]);

        $this->assertSame([], $this->painted());
    }

    public function testTheSERVERAnsweringLastStillPaints(): void
    {
        // The two round-trips race, and this is the other order: the adapter's
        // values are already in hand when the server finally says where they
        // go. Painting only from the values side would leave the screen empty
        // whenever the server was the slower of the two.
        $this->lookups = [['line' => 1, 'endCol' => 6, 'name' => '$count']];
        $values = $this->values();
        $values->stoppedAt('/p/a.php', 1, frameId: 1);
        $values->addVariables([['name' => '$count', 'value' => '41', 'variablesReference' => 0]]);

        $this->client->requests[0]['respond']([[
            'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 6]],
            'variableName' => '$count',
        ]]);

        $this->assertSame([1 => '$count = 41'], $this->painted());
    }

    public function testAVariableWithNoValueDoesNotStopTheONESAfterIt(): void
    {
        // The skip is a skip, not a stop: the server names every variable in
        // the range and the adapter reports the ones in scope, so gaps in the
        // middle are the normal case, not the edge one.
        $this->lookups = [
            ['line' => 1, 'endCol' => 6, 'name' => '$missing'],
            ['line' => 2, 'endCol' => 5, 'name' => '$known'],
        ];
        $values = $this->values();
        $this->serverAnswered($values);

        $values->addVariables([['name' => '$known', 'value' => '7', 'variablesReference' => 0]]);

        $this->assertSame([2 => '$known = 7'], $this->painted());
    }

    public function testANEWStopForgetsTheOldFramesValuesBeforeItAsks(): void
    {
        // Step, and the previous frame's answer is still in hand while the new
        // one is in flight. Left there it paints values from a frame the
        // debuggee has left — the most convincing kind of wrong.
        $this->lookups = [['line' => 1, 'endCol' => 6, 'name' => '$count']];
        $values = $this->values();
        $this->serverAnswered($values);
        $values->addVariables([['name' => '$count', 'value' => '41', 'variablesReference' => 0]]);
        $this->assertSame([1 => '$count = 41'], $this->painted());

        $values->stoppedAt('/p/a.php', 2, frameId: 2);

        $this->assertSame([], $this->painted(), 'cleared on the way in, not when the answer lands');
    }

    public function testAPaintedValueASKSForARedraw(): void
    {
        // The answers arrive on the event loop, long after the frame that drew
        // the stop. Without this the values appear whenever something else
        // happens to repaint.
        $this->lookups = [['line' => 1, 'endCol' => 6, 'name' => '$count']];
        $values = $this->values();
        $this->serverAnswered($values);
        Probe::set($this->tui, 'renderRequested', false);

        $values->addVariables([['name' => '$count', 'value' => '41', 'variablesReference' => 0]]);

        $this->assertTrue((bool) Probe::get($this->tui, 'renderRequested'));
    }
}
