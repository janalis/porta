<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Api;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\DocumentReplacer;
use Porta\Editor\EditorInvalidator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\Uri;
use Porta\Plugin\Api\CodeIntelligence;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FakeLspPool;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestLspQuery;
use Porta\Tests\Support\TestWorkspaceEditor;

/**
 * The two changes a language server computes — a rename and a format — as a
 * plugin applies them, and the outline, over a server that answers what each
 * test tells it to.
 */
final class CodeIntelligenceTest extends TestCase
{
    private TempProject $project;

    private string $root;

    private BufferManager $buffers;

    private ?FakeLspClient $client = null;

    private LspSessionPool $pool;

    /** @var list<array{mixed, ?string}> */
    private array $said = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->project = new TempProject(['src/A.php' => "<?php\nclass A {}\n", 'src/B.php' => "<?php\nnew A();\n"]);
    }

    private function code(): CodeIntelligence
    {
        $this->root = (string) \realpath($this->project->dir);
        $this->buffers = new BufferManager();
        $pool = FakeLspPool::create($this->root, new FixedActiveFile(), fn (): FakeLspClient => $this->client = new FakeLspClient());
        $this->pool = $pool;
        $screen = new class implements EditorInvalidator {
            public function invalidate(): void {}
        };

        return new CodeIntelligence(
            TestLspQuery::create($this->buffers, $pool, $this->root),
            $this->buffers,
            TestWorkspaceEditor::create($this->buffers, $this->root),
            new DocumentReplacer($this->buffers, $screen, $pool),
        );
    }

    /** Open $path in a buffer, as the editor does: the server hears of it. */
    private function open(string $path): Buffer
    {
        $text = (string) \file_get_contents($path);
        $buffer = $this->buffers->open($path, $text);
        $this->pool->notifyDidOpen($path, $text, 'php', 1);

        return $buffer;
    }

    private function keep(): \Closure
    {
        return function (mixed $answer, ?string $error): void {
            $this->said[] = [$answer, $error];
        };
    }

    private function path(string $relative): string
    {
        return $this->root.'/'.$relative;
    }

    /** @param array<array-key, mixed>|null $error */
    private function answer(int $request, mixed $result, ?array $error = null): void
    {
        $this->assertInstanceOf(FakeLspClient::class, $this->client, 'a server was started');
        $respond = $this->client->requests[$request]['respond'] ?? null;
        $this->assertInstanceOf(\Closure::class, $respond, 'request #'.$request.' was sent');
        $respond($result, $error);
    }

    /** @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string} */
    private static function edit(int $line, int $from, int $to, string $text): array
    {
        return ['range' => ['start' => ['line' => $line, 'character' => $from], 'end' => ['line' => $line, 'character' => $to]], 'newText' => $text];
    }

    public function testTheOutlineIsTheServersSymbolsFlattened(): void
    {
        $code = $this->code();

        $code->outline($this->path('src/A.php'), $this->keep());
        $this->answer(0, [['name' => 'A', 'kind' => 5, 'selectionRange' => ['start' => ['line' => 1, 'character' => 6]], 'children' => [['name' => 'run', 'kind' => 6]]]]);

        $this->assertSame(
            [[[['name' => 'A', 'kind' => 5, 'line' => 1, 'column' => 6, 'depth' => 0], ['name' => 'run', 'kind' => 6, 'line' => 0, 'column' => 0, 'depth' => 1]], null]],
            $this->said,
        );
    }

    public function testARenameEditsTheOpenBufferAndRewritesTheFileThatIsNot(): void
    {
        $code = $this->code();
        $a = $this->path('src/A.php');
        $b = $this->path('src/B.php');
        $buffer = $this->open($a);

        $code->rename($a, 1, 6, 'Z', $this->keep());
        $this->answer(0, ['changes' => [Uri::fromPath($a) => [self::edit(1, 6, 7, 'Z')], Uri::fromPath($b) => [self::edit(1, 4, 5, 'Z')]]]);

        $this->assertSame([[['files' => 2, 'skipped' => []], null]], $this->said);
        $this->assertSame("<?php\nclass Z {}\n", $buffer->document->getText());
        $this->assertSame("<?php\nclass A {}\n", \file_get_contents($a), 'the open one is left unsaved');
        $this->assertSame("<?php\nnew Z();\n", \file_get_contents($b), 'the closed one is rewritten');
    }

    public function testARenameTheServerOrTheWorkspaceRefusesChangesNothing(): void
    {
        $code = $this->code();
        $a = $this->path('src/A.php');

        $code->rename($a, 1, 6, 'Z', $this->keep());
        $code->rename($a, 1, 6, 'Z', $this->keep());
        $this->answer(0, null, ['message' => 'No symbol here']);
        $this->answer(1, ['documentChanges' => [['kind' => 'create', 'uri' => Uri::fromPath($a)], ['textDocument' => ['uri' => Uri::fromPath($a)], 'edits' => [self::edit(1, 6, 7, 'Z')]]]]);

        $this->assertSame(
            [
                [null, 'No symbol here'],
                [null, 'A.php already exists'],
            ],
            $this->said,
        );
        $this->assertSame("<?php\nclass A {}\n", \file_get_contents($a));
    }

    public function testAFormatAppliesTheServersEditsAsOneUndoStep(): void
    {
        $code = $this->code();
        $a = $this->path('src/A.php');
        $buffer = $this->open($a);

        $code->format($a, $this->keep());
        $this->assertSame(['tabSize' => 4, 'insertSpaces' => true], $this->client?->requests[0]['params']['options'] ?? null, 'the file\'s own indentation');
        $this->answer(0, [self::edit(1, 0, 0, '// '), self::edit(1, 10, 10, ' // end')]);

        $this->assertSame([[2, null]], $this->said);
        $this->assertSame("<?php\n// class A {} // end\n", $buffer->document->getText());
        $buffer->document->undo();
        $this->assertSame("<?php\nclass A {}\n", $buffer->document->getText());
    }

    public function testAFormatFindsTheBufferUnderTheOtherSpellingOfItsPath(): void
    {
        $code = $this->code();
        $buffer = $this->open($this->path('src/A.php'));
        $other = new TempProject();
        \symlink($this->project->dir, $other->path('repo'));

        $code->format($other->path('repo/src/A.php'), $this->keep());
        $this->answer(0, [self::edit(1, 0, 0, '// ')]);

        $this->assertSame([[1, null]], $this->said);
        $this->assertSame("<?php\n// class A {}\n", $buffer->document->getText());
    }

    public function testAFormatSaysWhyItChangedNothing(): void
    {
        $code = $this->code();
        $a = $this->path('src/A.php');

        $code->format($a, $this->keep());
        $buffer = $this->open($a);
        $code->format($a, $this->keep()); // 0: refused
        $code->format($a, $this->keep()); // 1: already formatted
        $code->format($a, $this->keep()); // 2: overlapping
        $code->format($a, $this->keep()); // 3: the text moved under it
        $this->answer(0, null, ['message' => 'Formatter crashed']);
        $this->answer(1, []);
        $this->answer(2, [self::edit(1, 0, 5, 'x'), self::edit(1, 2, 7, 'y')]);
        $buffer->document->insert('!');
        $this->answer(3, [self::edit(1, 0, 0, '// ')]);

        $this->assertSame(
            [
                [0, $a.' is not open as text'],
                [0, 'Formatter crashed'],
                [0, null],
                [0, 'The language server sent overlapping edits; nothing changed'],
                [0, 'The file changed while the language server formatted it — ask again'],
            ],
            $this->said,
        );
        $this->assertSame("!<?php\nclass A {}\n", $buffer->document->getText(), 'the user\'s keystroke, and nothing else');
    }

    public function testTheServersAreTheFirstEachLanguageStarts(): void
    {
        $code = $this->code();
        $this->pool->configure(
            [
                'php' => [['command' => ['php-ls', '--stdio'], 'name' => 'php-ls', 'initializationOptions' => ['a' => 1]], ['command' => ['extra'], 'name' => 'extra']],
                'twig' => [['command' => ['twig-ls'], 'name' => 'twig-ls']],
                '*' => [['command' => ['any'], 'name' => 'any']],
            ],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );

        $this->assertSame(
            [
                'php' => ['command' => ['php-ls', '--stdio'], 'name' => 'php-ls', 'initializationOptions' => ['a' => 1]],
                'twig' => ['command' => ['twig-ls'], 'name' => 'twig-ls'],
            ],
            $code->languageServers(),
            'the first of each, and no catch-all',
        );
    }
}
