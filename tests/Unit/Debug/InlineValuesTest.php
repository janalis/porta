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
            ['php' => [['command' => ['php-ls'], 'name' => 'php-ls']]],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );
        $active->set('/p/a.php');
        $pool->notifyDidOpen('/p/a.php', "<?php\n", 'php', 1);

        return new InlineValues(
            new InlineValueRequest($pool),
            $this->editor->decorations,
            $this->tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 10)),
            $this->editor,
        );
    }

    /** Stop, and let the server answer with {@see $lookups}. */
    private function serverAnswered(InlineValues $values): void
    {
        $values->stoppedAt('/p/a.php', 1, frameId: 1);
        $this->client->requests[0]['respond'](\array_map(static fn (array $l): array => [
            'range' => ['start' => ['line' => $l['line'], 'character' => 0], 'end' => ['line' => $l['line'], 'character' => $l['endCol']]],
            'variableName' => $l['name'],
        ], $this->lookups));
    }

    /** @return array<int, string> */
    private function painted(): array
    {
        /** @var array<int, string> */
        return $this->editor->decorations->debugValues;
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

    /**
     * A server with no `inlineValue` — phpantom_lsp, which is most PHP setups.
     *
     * Its SEMANTIC TOKENS answer the same question and Porta already has them:
     * the colouring pass fetches the whole document, and measured against the
     * real server a local is typed `variable` with a range whose text includes
     * the `$`, which is exactly how the adapter spells a name. Nothing is
     * guessed and nothing extra is asked for.
     */
    private function withoutInlineValueSupport(string $text): InlineValues
    {
        $this->editor = new CodeEditorWidget();
        $this->editor->showDocument('/p/a.php', new Document($text));

        $pool = new LspSessionPool('/p', new FixedActiveFile(), new Translator('en'));

        return new InlineValues(
            new InlineValueRequest($pool),
            $this->editor->decorations,
            $this->tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 10)),
            $this->editor,
        );
    }

    public function testTheCOLOURINGSTokensStandInWhenTheServerHasNoInlineValues(): void
    {
        $values = $this->withoutInlineValueSupport("<?php\n\$count = 41;\n");
        // What SemanticTokensController stored for this file: the `$count` at
        // column 0..6, typed as a local.
        $this->editor->decorations
            ->setSemanticTokens([
                1 => [['col' => 0, 'endCol' => 6, 'type' => 'variable', 'modifiers' => []]],
            ]);

        $values->stoppedAt('/p/a.php', 1, frameId: 1);
        $values->addVariables([['name' => '$count', 'value' => '41', 'variablesReference' => 0]]);

        $this->assertSame([1 => '$count = 41'], $this->painted(), 'the name came from the token text, with its $');
    }

    public function testAPROPERTYTokenIsNotALocalAndIsSkipped(): void
    {
        // `$this->name` types `name` as a property — no `$`, and not a key the
        // frame's variables are listed under. The declaration `public string
        // $name` is a property too, and is not a local at all.
        $values = $this->withoutInlineValueSupport("<?php\nreturn \$this->name;\n");
        $this->editor->decorations
            ->setSemanticTokens([
                1 => [
                    ['col' => 7, 'endCol' => 12, 'type' => 'variable', 'modifiers' => []],
                    ['col' => 14, 'endCol' => 18, 'type' => 'property', 'modifiers' => []],
                ],
            ]);

        $values->stoppedAt('/p/a.php', 1, frameId: 1);
        $values->addVariables([
            ['name' => '$this', 'value' => 'Thing', 'variablesReference' => 1],
            ['name' => 'name', 'value' => "'x'", 'variablesReference' => 0],
        ]);

        $this->assertSame([1 => '$this = Thing'], $this->painted(), 'the property is not offered even though the adapter happens to report that name');
    }

    public function testAPARAMETERCountsAsALocal(): void
    {
        $values = $this->withoutInlineValueSupport("<?php\nfunction f(int \$times) {}\n");
        $this->editor->decorations
            ->setSemanticTokens([
                1 => [['col' => 15, 'endCol' => 21, 'type' => 'parameter', 'modifiers' => []]],
            ]);

        $values->stoppedAt('/p/a.php', 1, frameId: 1);
        $values->addVariables([['name' => '$times', 'value' => '3', 'variablesReference' => 0]]);

        $this->assertSame([1 => '$times = 3'], $this->painted());
    }

    public function testWithNoTokensEitherNothingIsPainted(): void
    {
        // A server offering neither is not an error, it is a file with no
        // inline values — and an empty paint is what says so.
        $values = $this->withoutInlineValueSupport("<?php\n\$count = 41;\n");

        $values->stoppedAt('/p/a.php', 1, frameId: 1);
        $values->addVariables([['name' => '$count', 'value' => '41', 'variablesReference' => 0]]);

        $this->assertSame([], $this->painted());
    }

    public function testASTALETokenMapPaintsNothingRatherThanAnEmptyName(): void
    {
        // Tokens are fetched per document VERSION and the buffer can be edited
        // after — the colouring pass guards against a stale PATH, not a stale
        // edit. A token pointing past the end of a shortened line slices to '',
        // which would otherwise join against nothing and paint " = 41".
        $values = $this->withoutInlineValueSupport("<?php\n\$c;\n");
        $this->editor->decorations
            ->setSemanticTokens([
                1 => [['col' => 40, 'endCol' => 46, 'type' => 'variable', 'modifiers' => []]],
            ]);

        $values->stoppedAt('/p/a.php', 1, frameId: 1);
        $values->addVariables([['name' => '$count', 'value' => '41', 'variablesReference' => 0]]);

        $this->assertSame([], $this->painted());
    }

    public function testASKIPPEDTokenDoesNotStopTheOnesAfterIt(): void
    {
        // Three reasons to skip and a usable token after each: a property, a
        // token pointing past the end of the line, and then the local that
        // actually matters. Skipping is the normal case on any real line.
        $values = $this->withoutInlineValueSupport("<?php\n\$this->name = \$c;\n");
        $this->editor->decorations
            ->setSemanticTokens([
                1 => [
                    ['col' => 7, 'endCol' => 11, 'type' => 'property', 'modifiers' => []],
                    ['col' => 90, 'endCol' => 96, 'type' => 'variable', 'modifiers' => []],
                    ['col' => 14, 'endCol' => 16, 'type' => 'variable', 'modifiers' => []],
                ],
            ]);

        $values->stoppedAt('/p/a.php', 1, frameId: 1);
        $values->addVariables([['name' => '$c', 'value' => '7', 'variablesReference' => 0]]);

        $this->assertSame([1 => '$c = 7'], $this->painted());
    }

    public function testAMULTIBYTELineSlicesTheNameByCHARACTERSNotBytes(): void
    {
        // The server counts in characters and PHP strings are bytes. With `é`
        // ahead of it on the line, a byte-wise slice reads two columns short
        // and hands the join a name no frame ever had.
        $values = $this->withoutInlineValueSupport("<?php\n\$s = 'héllo'; \$count = 41;\n");
        $line = "\$s = 'héllo'; \$count = 41;";
        $at = \mb_strpos($line, needle: '$count');
        $this->editor->decorations
            ->setSemanticTokens([
                1 => [['col' => $at, 'endCol' => $at + 6, 'type' => 'variable', 'modifiers' => []]],
            ]);

        $values->stoppedAt('/p/a.php', 1, frameId: 1);
        $values->addVariables([['name' => '$count', 'value' => '41', 'variablesReference' => 0]]);

        $this->assertSame([1 => '$count = 41'], $this->painted());
    }

    public function testTWOVariablesOnALineBothComeThrough(): void
    {
        $values = $this->withoutInlineValueSupport("<?php\n\$a = \$b;\n");
        $this->editor->decorations
            ->setSemanticTokens([
                1 => [
                    ['col' => 0, 'endCol' => 2, 'type' => 'variable', 'modifiers' => []],
                    ['col' => 5, 'endCol' => 7, 'type' => 'variable', 'modifiers' => []],
                ],
            ]);

        $values->stoppedAt('/p/a.php', 1, frameId: 1);
        $values->addVariables([
            ['name' => '$a', 'value' => '1', 'variablesReference' => 0],
            ['name' => '$b', 'value' => '2', 'variablesReference' => 0],
        ]);

        $this->assertSame([1 => '$a = 1, $b = 2'], $this->painted());
    }

    public function testAServerThatANSWERSKeepsTheTokensOutOfIt(): void
    {
        // The fallback runs only when the request was not SENT. A server that
        // was asked owns the answer, and mixing the two sources would paint a
        // variable the server deliberately left out.
        $this->lookups = [['line' => 1, 'endCol' => 6, 'name' => '$count']];
        $values = $this->values();
        $this->editor->decorations
            ->setSemanticTokens([
                1 => [['col' => 0, 'endCol' => 6, 'type' => 'variable', 'modifiers' => []]],
                2 => [['col' => 0, 'endCol' => 5, 'type' => 'variable', 'modifiers' => []]],
            ]);
        $this->serverAnswered($values);

        $values->addVariables([
            ['name' => '$count', 'value' => '41', 'variablesReference' => 0],
            ['name' => "\$name", 'value' => "'x'", 'variablesReference' => 0],
        ]);

        $this->assertSame([1 => '$count = 41'], $this->painted(), 'only what the server named');
    }

    public function testAServerAnsweringNOTHINGIsNotSecondGuessedByTheTokens(): void
    {
        // "There are no inline values on these lines" is an answer, and the
        // fallback must not overrule it — it exists for a server that cannot be
        // ASKED, not for one that was asked and said none. Without that
        // distinction the tokens would paint variables the server deliberately
        // left out, on exactly the lines it had looked at.
        $this->lookups = [];
        $values = $this->values();
        $this->editor->decorations
            ->setSemanticTokens([
                1 => [['col' => 0, 'endCol' => 6, 'type' => 'variable', 'modifiers' => []]],
            ]);
        $this->serverAnswered($values);

        $values->addVariables([['name' => '$count', 'value' => '41', 'variablesReference' => 0]]);

        $this->assertSame([], $this->painted());
    }
}
