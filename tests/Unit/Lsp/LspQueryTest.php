<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\LineTextReader;
use Porta\I18n\Translator;
use Porta\Lsp\LspQuery;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\Uri;
use Porta\Support\OrphanGuard;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FakeLspPool;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestLspQuery;

/** The language servers asked about any file, with nothing shown: lent documents, open buffers, and every refusal. */
final class LspQueryTest extends TestCase
{
    private TempProject $project;

    private string $root;

    private ?FakeLspClient $client = null;

    private BufferManager $buffers;

    private FixedActiveFile $active;

    private LspSessionPool $pool;

    #[\Override]
    protected function setUp(): void
    {
        $this->project = new TempProject([
            'src/A.php' => "<?php\nclass A {}\n",
            'src/B.php' => "<?php\nnew A();\n",
            'README.md' => "# hi\n",
        ]);
    }

    private function query(): LspQuery
    {
        // Canonical, as buffers and the answers are: the temp dir is behind a
        // symlink on macOS.
        $this->root = (string) \realpath($this->project->dir);
        $this->buffers = new BufferManager();
        $this->pool = FakeLspPool::create($this->root, $this->active = new FixedActiveFile(), fn (): FakeLspClient => $this->client = new FakeLspClient());

        return TestLspQuery::create($this->buffers, $this->pool, $this->root);
    }

    private function path(string $relative): string
    {
        return $this->root.'/'.$relative;
    }

    private function client(): FakeLspClient
    {
        $this->assertInstanceOf(FakeLspClient::class, $this->client, 'a server was started');

        return $this->client;
    }

    /** @return list<string> the document notifications the server received, as "method uri" */
    private function documents(): array
    {
        return \array_map(
            static fn (array $n): string => $n['method'].' '.(\is_array($n['params']) ? $n['params']['textDocument']['uri'] ?? '' : ''),
            \array_values(\array_filter($this->client()->notifications, static fn (array $n): bool => \str_starts_with($n['method'], 'textDocument/did'))),
        );
    }

    private function answer(int $request, mixed $result, ?array $error = null): void
    {
        $respond = $this->client()->requests[$request]['respond'] ?? null;
        $this->assertInstanceOf(\Closure::class, $respond, 'request #'.$request.' was sent');
        $respond($result, $error);
    }

    public function testAFileNobodyHasOpenIsLentToTheServerForTheQuestion(): void
    {
        $query = $this->query();
        $a = $this->path('src/A.php');
        $b = $this->path('src/B.php');
        $got = null;

        $query->definition($b, 1, 5, static function (array $rows, ?string $error) use (&$got): void {
            $got = [$rows, $error];
        });

        $this->assertSame(['textDocument/didOpen '.Uri::fromPath($b)], $this->documents(), 'opened from disk for the question');
        $this->assertSame("<?php\nnew A();\n", $this->client()->notifications[0]['params']['textDocument']['text']);
        $this->assertSame('textDocument/definition', $this->client()->requests[0]['method']);
        $this->assertSame(['textDocument' => ['uri' => Uri::fromPath($b)], 'position' => ['line' => 1, 'character' => 5]], $this->client()->requests[0]['params']);

        $this->answer(0, [
            ['uri' => Uri::fromPath($a), 'range' => ['start' => ['line' => 1, 'character' => 6]]],
            ['targetUri' => Uri::fromPath($a), 'targetSelectionRange' => ['start' => ['line' => 0, 'character' => 0]]],
            ['uri' => 'not-a-file-uri', 'range' => []],
        ]);

        $this->assertSame(
            [
                [
                    ['path' => $a, 'line' => 1, 'column' => 6, 'text' => 'class A {}'],
                    ['path' => $a, 'line' => 0, 'column' => 0, 'text' => '<?php'],
                ],
                null,
            ],
            $got,
        );
        $this->assertSame(['textDocument/didOpen '.Uri::fromPath($b), 'textDocument/didClose '.Uri::fromPath($b)], $this->documents(), 'and given back');
    }

    public function testTwoQuestionsAboutALentFileOpenAndCloseItOnce(): void
    {
        $query = $this->query();
        $b = $this->path('src/B.php');
        $noop = static function (array $rows, ?string $error): void {};

        $query->definition($b, 1, 5, $noop);
        $query->references($b, 1, 5, $noop);
        $this->assertSame(['includeDeclaration' => true], $this->client()->requests[1]['params']['context']);

        $this->answer(0, null);
        $this->assertSame(['textDocument/didOpen '.Uri::fromPath($b)], $this->documents(), 'still in use by the second question');
        $this->answer(1, []);
        $this->assertSame(['textDocument/didOpen '.Uri::fromPath($b), 'textDocument/didClose '.Uri::fromPath($b)], $this->documents());
    }

    public function testAFileTheUserOpensMeanwhileIsLeftOpen(): void
    {
        $query = $this->query();
        $b = $this->path('src/B.php');
        $query->hover($b, 1, 5, static function (?string $text, ?string $error): void {});

        $this->buffers->open($b, "<?php\nnew A();\n");
        $this->answer(0, null);

        $this->assertSame(['textDocument/didOpen '.Uri::fromPath($b)], $this->documents(), 'the document is the user\'s now');
    }

    public function testAnOpenBufferIsAskedAboutAsItIsOnScreen(): void
    {
        $query = $this->query();
        $b = $this->path('src/B.php');
        $this->pool->notifyDidOpen($b, "<?php\nnew A();\n", 'php', 1);
        $buffer = $this->buffers->open($b, "<?php\nnew A();\n");
        $buffer->document->insert('// unsaved'."\n");
        $rows = null;

        $query->references($b, 0, 0, static function (array $found) use (&$rows): void {
            $rows = $found;
        });
        $this->answer(0, [['uri' => Uri::fromPath($b), 'range' => ['start' => ['line' => 0, 'character' => 0]]]]);

        $this->assertSame([['path' => $b, 'line' => 0, 'column' => 0, 'text' => '// unsaved']], $rows, 'the line as the buffer has it');
        $this->assertSame(['textDocument/didOpen '.Uri::fromPath($b)], $this->documents(), 'nothing lent, nothing closed');
    }

    public function testHoverAnswersPlainTextOrNothing(): void
    {
        $query = $this->query();
        $a = $this->path('src/A.php');
        $said = [];
        $keep = static function (?string $text, ?string $error) use (&$said): void {
            $said[] = [$text, $error];
        };

        $query->hover($a, 1, 6, $keep);
        $this->answer(0, ['contents' => ['kind' => 'markdown', 'value' => 'class **A**']]);
        $query->hover($a, 1, 6, $keep);
        $this->answer(1, null);

        $this->assertSame([['class A', null], [null, null]], $said);
    }

    public function testEveryRefusalSaysWhy(): void
    {
        $query = $this->query();
        $said = [];
        $keep = static function (mixed $answer, ?string $error) use (&$said): void {
            $said[] = [$answer, $error];
        };

        $query->definition($this->path('README.md'), 0, 0, $keep);
        $query->definition($this->path('src/Gone.php'), 0, 0, $keep);
        $query->hover($this->path('src/A.php'), 0, 0, $keep);
        $this->answer(0, null, ['code' => -32_603, 'message' => 'Internal error']);
        $query->references($this->path('src/A.php'), 0, 0, $keep);
        $this->answer(1, null, ['code' => -32_603]);

        $this->assertSame(
            [
                [[], 'No language server is configured for README.md'],
                [[], 'src/Gone.php is not a file'],
                [null, 'Internal error'],
                [[], 'The language server refused the request'],
            ],
            $said,
        );
    }

    public function testABufferTheServerWasNeverToldAboutIsRefused(): void
    {
        $query = $this->query();
        $a = $this->path('src/A.php');
        $this->buffers->open($a, "<?php\nclass A {}\n"); // no didOpen: no session was ever started
        $said = null;

        $query->definition($a, 1, 6, static function (array $rows, ?string $error) use (&$said): void {
            $said = $error;
        });

        $this->assertSame('No language server is running for this file', $said);
    }

    public function testWorkspaceSymbolsComeFromTheRunningServerWithAbsolutePaths(): void
    {
        $query = $this->query();
        $said = [];
        $keep = static function (array $symbols, ?string $error) use (&$said): void {
            $said[] = [$symbols, $error];
        };

        $query->workspaceSymbols('A', $keep);
        $this->assertSame([[[], 'No language server is running — open a file of the project first']], $said);

        $this->pool->notifyDidOpen($this->path('src/A.php'), "<?php\n", 'php', 1);
        $this->active->set($this->path('src/A.php'));
        $query->workspaceSymbols('A', $keep);
        $this->assertSame(['query' => 'A'], $this->client()->requests[0]['params']);
        $this->answer(0, [['name' => 'A', 'kind' => 5, 'location' => ['uri' => Uri::fromPath($this->path('src/A.php')), 'range' => ['start' => ['line' => 1, 'character' => 0]]]]]);
        $query->workspaceSymbols('A', $keep);
        $this->answer(1, null, ['code' => 1, 'message' => 'Index not ready']);

        $this->assertSame(
            [
                [[], 'No language server is running — open a file of the project first'],
                [[['path' => $this->path('src/A.php'), 'line' => 1, 'name' => 'A', 'kind' => 5]], null],
                [[], 'Index not ready'],
            ],
            $said,
        );
    }

    public function testTheOutlineRenameAndFormattingAreAskedNotApplied(): void
    {
        $query = $this->query();
        $a = $this->path('src/A.php');
        $said = [];
        $keep = static function (mixed $answer, ?string $error) use (&$said): void {
            $said[] = [$answer, $error];
        };

        $query->documentSymbols($a, $keep);
        $query->rename($a, 1, 6, 'Z', $keep);
        $query->rename($a, 1, 6, 'Z', $keep);
        $query->rename($a, 1, 6, 'Z', $keep);
        $query->formatting($a, ['tabSize' => 2, 'insertSpaces' => true], $keep);
        $query->formatting($a, ['tabSize' => 2, 'insertSpaces' => true], $keep);

        $this->assertSame(
            ['textDocument/documentSymbol', 'textDocument/rename', 'textDocument/rename', 'textDocument/rename', 'textDocument/formatting', 'textDocument/formatting'],
            \array_column($this->client()->requests, 'method'),
        );
        $this->assertSame(['textDocument' => ['uri' => Uri::fromPath($a)], 'position' => ['line' => 1, 'character' => 6], 'newName' => 'Z'], $this->client()->requests[1]['params']);
        $this->assertSame(['textDocument' => ['uri' => Uri::fromPath($a)], 'options' => ['tabSize' => 2, 'insertSpaces' => true]], $this->client()->requests[4]['params']);

        $edit = ['changes' => [Uri::fromPath($a) => []]];
        $format = ['range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]], 'newText' => ' '];
        $this->answer(0, ['A' => ['name' => 'A', 'kind' => 5, 'selectionRange' => ['start' => ['line' => 1, 'character' => 6]]]]);
        $this->answer(1, $edit);
        $this->answer(2, null);
        $this->answer(3, null, ['message' => 'No symbol here']);
        $this->answer(4, [7 => $format]);
        $this->answer(5, null);

        $this->assertSame(
            [
                [[['name' => 'A', 'kind' => 5, 'line' => 1, 'column' => 6, 'depth' => 0]], null],
                [$edit, null],
                [null, 'The language server cannot rename anything at this position'],
                [null, 'No symbol here'],
                [[$format], null],
                [[], null],
            ],
            $said,
            'a keyed answer is read as the list it should have been; no edits is nothing to format',
        );
        $this->assertSame(
            ['textDocument/didOpen '.Uri::fromPath($a), 'textDocument/didClose '.Uri::fromPath($a)],
            $this->documents(),
            'lent once for the six questions, given back after the last',
        );
    }

    /**
     * A server a plugin runs on someone else's behalf runs as the pool's own
     * do: its command, in the root it is given, and guarded — it ends with
     * the IDE however the IDE ends.
     */
    public function testAServerTransportIsGuardedAsThePoolsOwnAre(): void
    {
        $guard = new OrphanGuard();
        $buffers = new BufferManager();
        $query = new LspQuery(new LspSessionPool('/p', new FixedActiveFile(), new Translator('en')), $buffers, new LineTextReader($buffers), new ProjectPaths('/p'), $guard);

        $transport = $query->serverTransport(['command' => ['phpactor', 'language-server'], 'name' => 'phpactor'], '/p/root', 'phpactor (for Ana)');

        $this->assertSame($guard, Probe::get($transport, 'guard'));
        $this->assertSame(['phpactor', 'language-server'], Probe::get($transport, 'command'));
        $this->assertSame('/p/root', Probe::get($transport, 'rootDir'));
        $this->assertSame('phpactor (for Ana)', Probe::get($transport, 'peerName'));
    }
}
