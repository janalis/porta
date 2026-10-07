<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\ProblemCards;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\QuickFixProbe;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Ui\Role;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipBody;
use Porta\Ui\TooltipLine;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * PhpStorm's problem card: the problems under the pointer, worst first, where
 * each came from, and — once a server answers — the fix and the list behind it,
 * each with the key the live keymap binds.
 */
final class ProblemCardsTest extends TestCase
{
    private CodeEditorWidget $editor;

    private FakeScheduler $scheduler;

    private ?FakeLspClient $client = null;

    /** `$x = foo($y);` on line 1, an error on `foo` and a warning on `$y`. */
    // @mago-expect lint:no-boolean-flag-parameter -- the rig's one switch: whether a server has the file open.
    private function cards(bool $server = true): ProblemCards
    {
        $pool = new LspSessionPool(
            '/tmp/proj',
            new FixedActiveFile('/tmp/proj/a.php'),
            new Translator('en'),
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->client = new FakeLspClient(['codeActionProvider' => true]),
        );
        $pool->configure(
            ['php' => [['command' => ['php-ls'], 'name' => 'php-ls']]],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );
        $this->editor = new CodeEditorWidget();
        $buffer = new BufferManager()->open('/tmp/proj/a.php', "<?php\n\$x = foo(\$y);\n");
        $this->editor->showDocument('/tmp/proj/a.php', $buffer->document);
        if ($server) {
            $pool->notifyDidOpen('/tmp/proj/a.php', $buffer->document->getText(), 'php', 1);
        }
        $this->editor->decorations->setDiagnostics([
            1 => [
                ['severity' => DiagnosticSeverity::Warning, 'message' => "Unused\n  variable", 'col' => 9, 'endCol' => 11, 'source' => 'phpstan'],
                ['severity' => DiagnosticSeverity::Error, 'message' => 'Undefined function foo', 'col' => 5, 'endCol' => 10, 'source' => 'phpantom', 'code' => 'E1'],
            ],
        ]);
        $this->editor->render(new RenderContext(60, 6));

        return new ProblemCards($this->editor, new QuickFixProbe($pool, $this->editor, $this->scheduler = new FakeScheduler()), new Translator('en'));
    }

    /** @return list<string> */
    private static function texts(TooltipBody $body): array
    {
        return \array_map(static fn (TooltipLine $line): string => $line->lead.$line->text.('' === $line->key ? '' : ' ['.$line->key.']'), $body->lines);
    }

    public function testEachProblemSaysWhatItIsAndWhereItCameFromWorstFirst(): void
    {
        $cards = $this->cards(server: false);
        $diags = $this->editor->decorations->diagnostics[1];

        $body = $cards->body([$diags[1], $diags[0], ['severity' => DiagnosticSeverity::Hint, 'message' => 'h', 'col' => 0, 'code' => 7]], 1, static function (TooltipBody $body): void {});

        $this->assertSame(['✗ Undefined function foo', '  phpantom · E1', '▲ Unused variable', '  phpstan', '· h', '  7'], self::texts($body));
        $this->assertSame(Role::DiagError, $body->lines[0]->leadRole, 'the glyph in its severity colour');
        $this->assertSame(Role::Faint, $body->lines[1]->role, 'where it came from, faint');
        $this->assertSame(60, $body->maxText, 'a message is read, so the card may run wider than a hint');
    }

    public function testTheFixAndTheListBehindItArriveWithTheirKeys(): void
    {
        $cards = $this->cards();
        $diags = $this->editor->decorations->diagnostics[1];
        $updated = null;

        $body = $cards->body([$diags[1]], 1, static function (TooltipBody $body) use (&$updated): void {
            $updated = $body;
        });
        $this->assertSame(['✗ Undefined function foo', '  phpantom · E1'], self::texts($body), 'nothing promised before a server answers');

        $this->scheduler->fire();
        $this->assertInstanceOf(FakeLspClient::class, $this->client);
        \array_last($this->client->requests)['respond']([['title' => 'Create function foo', 'kind' => 'quickfix'], ['title' => 'Ignore']], error: null);

        $this->assertInstanceOf(TooltipBody::class, $updated);
        $this->assertSame(['✗ Undefined function foo', '  phpantom · E1', 'Fix: Create function foo', 'More actions… [Alt+⏎]'], self::texts($updated), 'a key only where the keymap binds one');
    }

    public function testACardAnchorsOnTheSquiggleAndKeepsItsKeyAlongIt(): void
    {
        $cards = $this->cards(server: false);
        $gutter = $this->editor->gutterWidth();
        $refresh = static function (Tooltip $tip): void {};

        $onFoo = $cards->tooltipAt(1, $gutter + 6, $this->editor->viewport(), $refresh);
        $this->assertInstanceOf(Tooltip::class, $onFoo);
        $this->assertSame([$gutter + 5, $gutter + 10], [$onFoo->colStart, $onFoo->colEnd], 'the arrow points at foo(, not at the pointer');
        $this->assertSame($onFoo->key, $cards->tooltipAt(1, $gutter + 8, $this->editor->viewport(), $refresh)?->key, 'the same card along the squiggle');
        $this->assertSame($onFoo->body?->plain(), $onFoo->text, 'its words are its body\'s');

        $both = $cards->tooltipAt(1, $gutter + 9, $this->editor->viewport(), $refresh);
        $this->assertNotSame($onFoo->key, $both?->key, 'where two squiggles meet, another card: both problems');
        $this->assertStringStartsWith('✗ Undefined function foo', (string) $both?->text);

        $this->assertNull($cards->tooltipAt(1, $gutter + 1, $this->editor->viewport(), $refresh), 'no squiggle, no card');
        $this->assertNull($cards->tooltipAt(1, 1, $this->editor->viewport(), $refresh), 'not the gutter');
    }

    public function testTheCardIsSaidAgainWhenItsFixArrives(): void
    {
        $cards = $this->cards();
        $heard = [];

        $cards->tooltipAt(1, $this->editor->gutterWidth() + 6, $this->editor->viewport(), static function (Tooltip $tip) use (&$heard): void {
            $heard[] = $tip;
        });
        $this->scheduler->fire();
        $this->assertInstanceOf(FakeLspClient::class, $this->client);
        \array_last($this->client->requests)['respond']([['title' => 'Create function foo']], error: null);

        $this->assertCount(1, $heard);
        $this->assertStringContainsString('Fix: Create function foo', $heard[0]->text);
    }
}
