<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\I18n\Translator;
use Porta\Lsp\CompletionController;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Which argument the signature popup emphasises.
 *
 * The server has always sent this and Porta took only the label, so a
 * five-parameter signature said exactly the same thing at every comma. LSP
 * allows the parameter as UTF-16 offsets OR as the substring itself; both forms
 * are in the wild, so both are read.
 */
final class SignatureActiveParameterTest extends TestCase
{
    private const string LABEL = 'fn(int $a, string $b)';

    private CodeEditorWidget $editor;
    private FakeLspClient $client;
    private FixedActiveFile $activeFile;
    private Tui $tui;

    private function controller(): CompletionController
    {
        $this->editor = new CodeEditorWidget();
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $this->tui = $tui;
        $translator = new Translator('en');
        $pool = new LspSessionPool(
            '/p',
            $this->activeFile = new FixedActiveFile(),
            $translator,
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->client = new FakeLspClient(['signatureHelpProvider' => ['triggerCharacters' => ['(', ',']]]),
        );
        $pool->configure(
            ['php' => ['command' => ['php-ls'], 'name' => 'php-ls']],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );
        $controller = new CompletionController($pool, $this->editor, $tui, new Notifier(new StatusBarWidget(), $tui), $translator, null);

        $this->editor->showDocument('/p/a.php', new Document("<?php\nfn(1, 2);\n"));
        $this->activeFile->set('/p/a.php');
        Probe::get($controller, 'lsp')->notifyDidOpen('/p/a.php', "<?php\n", 'php', 1);

        return $controller;
    }

    /** @return array{0: int, 1: int}|null */
    private function answer(mixed $result): ?array
    {
        $controller = $this->controller();
        $controller->requestSignatureHelp(1, 4);
        end($this->client->requests)['respond']($result, error: null);

        /** @var \Porta\Editor\SignatureHint|null $hint */
        $hint = Probe::get($this->editor, 'signatureHint');

        return $hint?->activeRange;
    }

    public function testOffsetPairsAreTakenAsTheRange(): void
    {
        // 'string $b' is chars 11..20 of the label — the second parameter.
        self::assertSame(
            [11, 20],
            $this->answer([
                'signatures' => [[
                    'label' => self::LABEL,
                    'parameters' => [
                        ['label' => [3, 9]],
                        ['label' => [11, 20]],
                    ],
                    'activeParameter' => 1,
                ]],
            ]),
        );
    }

    public function testASubstringParameterIsLocatedInTheLabel(): void
    {
        self::assertSame(
            [11, 20],
            $this->answer([
                'signatures' => [[
                    'label' => self::LABEL,
                    'parameters' => [
                        ['label' => 'int $a'],
                        ['label' => 'string $b'],
                    ],
                    'activeParameter' => 1,
                ]],
            ]),
        );
    }

    public function testTheTopLEVELActiveParameterIsUsedWhenTheSignatureNamesNone(): void
    {
        // Servers put it in either place; the signature's own wins when present.
        self::assertSame(
            [3, 9],
            $this->answer([
                'activeParameter' => 0,
                'signatures' => [[
                    'label' => self::LABEL,
                    'parameters' => [
                        ['label' => [3, 9]],
                        ['label' => [11, 20]],
                    ],
                ]],
            ]),
        );
    }

    public function testWithNoActiveParameterAnywhereTheFirstOneIsEmphasised(): void
    {
        // The default, and it must be the FIRST parameter rather than none: you
        // are on argument one until the server says otherwise.
        self::assertSame(
            [3, 9],
            $this->answer([
                'signatures' => [[
                    'label' => self::LABEL,
                    'parameters' => [
                        ['label' => [3, 9]],
                        ['label' => [11, 20]],
                    ],
                ]],
            ]),
        );
    }

    public function testASubstringTheLabelDoesNotContainEmphasisesNothing(): void
    {
        self::assertNull($this->answer([
            'signatures' => [['label' => self::LABEL, 'parameters' => [['label' => 'nowhere']], 'activeParameter' => 0]],
        ]));
    }

    public function testAnEndPastTheLabelStopsAtTheLabelsEnd(): void
    {
        // Which is what a server sending it means: "from here to the end". It
        // can never land mid-character or outside the string, so clamping is
        // safe where a raw offset would not be.
        self::assertSame(
            [11, \strlen(self::LABEL)],
            $this->answer([
                'signatures' => [['label' => self::LABEL, 'parameters' => [['label' => [11, 400]]], 'activeParameter' => 0]],
            ]),
        );
    }

    public function testAStartPastTheLabelEmphasisesNothing(): void
    {
        // Both ends clamp to the same place, leaving an empty range — and
        // emphasising the wrong text is worse than emphasising none.
        self::assertNull($this->answer([
            'signatures' => [['label' => self::LABEL, 'parameters' => [['label' => [400, 500]]], 'activeParameter' => 0]],
        ]));
    }

    public function testAnIndexBeyondTheParameterListEmphasisesNothing(): void
    {
        self::assertNull($this->answer([
            'signatures' => [['label' => self::LABEL, 'parameters' => [['label' => [3, 9]]], 'activeParameter' => 7]],
        ]));
    }

    public function testAHalfIntegerPairIsNotAnOffsetPair(): void
    {
        // Both ends must be integers; one of each is a shape no reading of the
        // spec produces, so it is refused rather than half-interpreted.
        self::assertNull($this->answer([
            'signatures' => [['label' => self::LABEL, 'parameters' => [['label' => [3, 'nine']]], 'activeParameter' => 0]],
        ]));
    }

    public function testAZeroWidthRangeEmphasisesNothing(): void
    {
        // A caret-like range names no characters; bolding it would emphasise
        // whatever happens to follow.
        self::assertNull($this->answer([
            'signatures' => [['label' => self::LABEL, 'parameters' => [['label' => [3, 3]]], 'activeParameter' => 0]],
        ]));
    }

    public function testAParameterStartingAtTheVeryFirstByteIsKept(): void
    {
        // The lower bound of the validation: offset 0 is legitimate, and a
        // guard written one too strict would silently drop it.
        self::assertSame(
            [0, 2],
            $this->answer([
                'signatures' => [['label' => self::LABEL, 'parameters' => [['label' => [0, 2]]], 'activeParameter' => 0]],
            ]),
        );
    }

    public function testARangeEndingExACTLYAtTheLabelsLastByteIsKept(): void
    {
        // The upper bound: end === strlen is in range, one past it is not.
        self::assertSame(
            [11, \strlen(self::LABEL)],
            $this->answer([
                'signatures' => [['label' => self::LABEL, 'parameters' => [['label' => [11, \strlen(self::LABEL)]]], 'activeParameter' => 0]],
            ]),
        );
    }

    public function testClearingAPREVIOUSHintIsWhatAnEmptyLabelDoes(): void
    {
        // Asserted from a hint that was actually SHOWING: null over null is
        // indistinguishable from doing nothing at all.
        $controller = $this->controller();
        $controller->requestSignatureHelp(1, 4);
        end($this->client->requests)['respond'](['signatures' => [['label' => self::LABEL]]], error: null);
        self::assertSame(self::LABEL, Probe::get($this->editor, 'signatureHint')?->label);

        Probe::set($this->tui, 'renderRequested', false);
        $controller->requestSignatureHelp(1, 4);
        end($this->client->requests)['respond'](['signatures' => []], error: null);

        self::assertNull(Probe::get($this->editor, 'signatureHint'));
        // A hint cleared without a repaint stays on the screen it was drawn on.
        self::assertTrue((bool) Probe::get($this->tui, 'renderRequested'));
    }

    public function testAParameterLabelInNeitherFormEmphasisesNothing(): void
    {
        // Servers do send shapes the spec does not describe; the label is still
        // worth showing, just without a claim about which argument you are on.
        self::assertNull($this->answer([
            'signatures' => [['label' => self::LABEL, 'parameters' => [['label' => ['not' => 'a pair']]], 'activeParameter' => 0]],
        ]));
        self::assertSame(self::LABEL, Probe::get($this->editor, 'signatureHint')?->label);
    }

    public function testAnEmptyParameterSubstringEmphasisesNothing(): void
    {
        // strpos('') would answer 0, emphasising a zero-width slice at the very
        // start of the label — a claim about the wrong argument.
        self::assertNull($this->answer([
            'signatures' => [['label' => self::LABEL, 'parameters' => [['label' => '']], 'activeParameter' => 0]],
        ]));
    }

    public function testASignatureWithNoParametersStillShowsItsLabel(): void
    {
        self::assertNull($this->answer(['signatures' => [['label' => self::LABEL]]]));
        self::assertSame(self::LABEL, Probe::get($this->editor, 'signatureHint')?->label);
    }

    public function testAnEmptyLabelClearsTheHintEntirely(): void
    {
        self::assertNull($this->answer(['signatures' => [['label' => '']]]));
        self::assertNull(Probe::get($this->editor, 'signatureHint'));
    }
}
