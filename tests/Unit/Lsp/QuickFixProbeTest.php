<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\DiagnosticSeverity;
use Porta\Event\ActiveBufferChanged;
use Porta\Event\DiagnosticsChanged;
use Porta\I18n\Translator;
use Porta\Lsp\FixLookup;
use Porta\Lsp\FixState;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\QuickFixProbe;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Widget\CodeEditorWidget;

/**
 * The problem card's "Fix:" line asks the servers about ONE problem, on the
 * next loop turn, once per problem — and only the problem asked about last
 * hears its answer.
 */
final class QuickFixProbeTest extends TestCase
{
    /** @var list<FakeLspClient> */
    private array $clients = [];

    private FakeScheduler $scheduler;

    /** @var list<FixLookup> what the last problem heard */
    private array $heard = [];

    /** @param array<string, mixed> $capabilities */
    // @mago-expect lint:no-boolean-flag-parameter -- the rig's one switch: whether the file was ever opened on the server.
    private function probe(array $capabilities = ['codeActionProvider' => true], int $servers = 1, bool $open = true): QuickFixProbe
    {
        $pool = new LspSessionPool(
            '/tmp/proj',
            new FixedActiveFile('/tmp/proj/a.php'),
            new Translator('en'),
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->clients[] = new FakeLspClient($capabilities),
        );
        $pool->configure(
            [
                'php' => 2 === $servers
                    ? [['command' => ['php-ls'], 'name' => 'php-ls'], ['command' => ['sf-ls'], 'name' => 'sf-ls']]
                    : [['command' => ['php-ls'], 'name' => 'php-ls']],
            ],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );
        $editor = new CodeEditorWidget();
        $buffer = new BufferManager()->open('/tmp/proj/a.php', "<?php\n\$x = foo();\n");
        $editor->showDocument('/tmp/proj/a.php', $buffer->document);
        if ($open) {
            $pool->notifyDidOpen('/tmp/proj/a.php', $buffer->document->getText(), 'php', 1);
        }

        return new QuickFixProbe($pool, $editor, $this->scheduler = new FakeScheduler());
    }

    /** @return array{severity: DiagnosticSeverity, message: string, col: int, endCol: int, code: string} */
    private static function problem(string $message = 'Undefined function foo', int $col = 5): array
    {
        return ['severity' => DiagnosticSeverity::Error, 'message' => $message, 'col' => $col, 'endCol' => $col + 3, 'code' => 'E1'];
    }

    private function ask(QuickFixProbe $probe, ?array $problem = null): FixLookup
    {
        return $probe->lookup(1, $problem ?? self::problem(), function (FixLookup $answer): void {
            $this->heard[] = $answer;
        });
    }

    /** @return list<array{method: string, params: mixed, respond: \Closure}> */
    private function codeActionRequests(int $client = 0): array
    {
        return \array_values(\array_filter($this->clients[$client]->requests, static fn (array $r): bool => 'textDocument/codeAction' === $r['method']));
    }

    public function testItAsksOnTheNextTurnAboutThatProblemAlone(): void
    {
        $probe = $this->probe();

        $this->assertSame(FixState::Pending, $this->ask($probe)->state);
        $this->assertSame([], $this->codeActionRequests(), 'nothing sent in the caller\'s own call stack');

        $this->scheduler->fire();
        $request = $this->codeActionRequests()[0] ?? null;
        $this->assertNotNull($request);
        /** @var array{range: array<string, mixed>, context: array{diagnostics: list<array<string, mixed>>, triggerKind: int}} $params */
        $params = $request['params'];
        $this->assertSame(2, $params['context']['triggerKind'], 'asked on the user\'s behalf, not invoked');
        $this->assertCount(1, $params['context']['diagnostics'], 'only the problem under the pointer');
        $this->assertSame('E1', $params['context']['diagnostics'][0]['code']);
        $this->assertSame(['start' => ['line' => 1, 'character' => 5], 'end' => ['line' => 1, 'character' => 8]], $params['range']);

        $request['respond']([['title' => 'Extract', 'kind' => 'refactor'], ['title' => 'Create function foo', 'kind' => 'quickfix']], error: null);

        $this->assertEquals([FixLookup::found('Create function foo', 2)], $this->heard);
        $this->assertEquals(FixLookup::found('Create function foo', 2), $this->ask($probe), 'remembered: asking again sends nothing');
        $this->assertSame([], $this->scheduler->scheduled);
    }

    public function testOnlyTheProblemAskedAboutLastHearsItsAnswer(): void
    {
        $probe = $this->probe();
        $this->ask($probe, self::problem('first'));
        $this->ask($probe, self::problem('second', col: 9));
        $this->scheduler->fire();
        $this->scheduler->fire();
        [$first, $second] = $this->codeActionRequests();

        $first['respond']([['title' => 'Fix first']], error: null);
        $this->assertSame([], $this->heard, 'the pointer has moved on');
        $this->assertEquals(FixLookup::found('Fix first', 1), $this->ask($probe, self::problem('first')), 'but the answer is kept');

        $second['respond']([], error: null);
        $this->assertSame([], $this->heard, 'and the first is the current one again');
    }

    public function testEveryServerHasItsSayUnlessOneOffersItsPreferredFix(): void
    {
        $probe = $this->probe(servers: 2);
        $this->ask($probe);
        $this->scheduler->fire();

        $this->codeActionRequests(0)[0]['respond']([['title' => 'Maybe']], error: null);
        $this->assertSame([], $this->heard, 'a plain fix waits for the other server');
        $this->codeActionRequests(1)[0]['respond'](null, error: ['code' => -1, 'message' => 'boom']);
        $this->assertEquals([FixLookup::found('Maybe', 1)], $this->heard);

        $this->heard = [];
        $this->ask($probe, self::problem('other'));
        $this->scheduler->fire();
        $this->codeActionRequests(0)[1]['respond']([['title' => 'Best', 'isPreferred' => true]], error: null);
        $this->assertEquals([FixLookup::found('Best', 1)], $this->heard, 'the preferred fix is the answer at once');
        $this->codeActionRequests(1)[1]['respond']([['title' => 'Late']], error: null);
        $this->assertCount(1, $this->heard, 'and a later answer changes nothing');
    }

    public function testNothingOfferedIsSaid(): void
    {
        $probe = $this->probe();
        $this->ask($probe);
        $this->scheduler->fire();

        $this->codeActionRequests()[0]['respond']([['title' => 'Extract', 'kind' => 'refactor.extract'], ['title' => '']], error: null);

        $this->assertEquals([FixLookup::none(1)], $this->heard, 'no fix, but one action behind More actions');
    }

    public function testWithoutAServerToAskThereIsNoFixAndNothingIsRemembered(): void
    {
        $this->assertEquals(FixLookup::none(), $this->ask($this->probe(open: false)));
        $this->assertSame([], $this->scheduler->scheduled);

        $probe = $this->probe(capabilities: []);
        $this->ask($probe);
        $this->scheduler->fire();
        $this->assertEquals([FixLookup::none()], $this->heard, 'a server that offers no code actions is not asked');
    }

    public function testChangedProblemsOrAnotherFileForgetWhatWasOffered(): void
    {
        $probe = $this->probe();
        $this->ask($probe);
        $this->scheduler->fire();
        $this->codeActionRequests()[0]['respond']([['title' => 'Fix']], error: null);

        $probe->onDiagnosticsChanged(new DiagnosticsChanged());
        $this->assertSame(FixState::Pending, $this->ask($probe)->state, 'asked again');

        $this->scheduler->fire();
        $probe->onActiveBufferChanged(new ActiveBufferChanged('/tmp/proj/b.php'));
        $this->codeActionRequests()[1]['respond']([['title' => 'Stale']], error: null);
        $this->assertCount(1, $this->heard, 'an answer about problems gone is dropped');
        $this->assertSame(FixState::Pending, $this->ask($probe)->state);
    }
}
