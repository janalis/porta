<?php

declare(strict_types=1);

namespace Porta\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Ide;
use Porta\Tests\Support\TempProject;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * End-to-end wiring of EditorConfig through the coordinator: indentation applied
 * to the live document on open, and the whitespace/line-ending rules applied to
 * the on-disk bytes on save.
 */
final class EditorConfigIntegrationTest extends TestCase
{
    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
        putenv('PATH=/nonexistent'); // keep git/LSP out of these unit runs
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
    }

    private function ide(TempProject $project): Ide
    {
        return new Ide($project->dir, null, new VirtualTerminal(columns: 80, rows: 24));
    }

    private function open(Ide $ide, string $path): Buffer
    {
        new \ReflectionMethod($ide, 'openFile')->invoke($ide, $path);
        $buffers = new \ReflectionProperty($ide, 'buffers')->getValue($ide);
        \assert($buffers instanceof BufferManager);

        return $buffers->active();
    }

    private function save(Ide $ide, Buffer $buffer): void
    {
        $service = new \ReflectionProperty($ide, 'bufferService')->getValue($ide);
        \assert($service instanceof BufferService);
        $service->saveBuffer($buffer);
    }

    private function flashText(Ide $ide): ?string
    {
        $status = $ide->getTui()->getById('status');
        \assert($status instanceof StatusBarWidget);
        $flash = new \ReflectionProperty($status, 'flash')->getValue($status);

        return $flash['text'] ?? null;
    }

    public function testIndentationAppliedOnOpen(): void
    {
        $project = new TempProject([
            'composer.json' => '{}',
            '.editorconfig' => "root = true\n[*.py]\nindent_style = space\nindent_size = 2\n",
            'main.py' => "def f():\n    pass\n",
        ]);
        $ide = $this->ide($project);

        $buffer = $this->open($ide, $project->path('main.py'));

        $this->assertSame(2, $buffer->document->indentSize());
        $this->assertSame('  ', $buffer->document->indentUnit());
        $this->assertNotNull($buffer->editorConfig);
    }

    public function testSaveAppliesTrimEolAndFinalNewline(): void
    {
        $project = new TempProject([
            'composer.json' => '{}',
            '.editorconfig' => "root = true\n[*.txt]\ntrim_trailing_whitespace = true\ninsert_final_newline = true\nend_of_line = crlf\n",
            'note.txt' => "hello  \nworld", // trailing spaces, no final newline
        ]);
        $ide = $this->ide($project);
        $buffer = $this->open($ide, $project->path('note.txt'));

        $this->save($ide, $buffer);

        $this->assertSame("hello\r\nworld\r\n", (string) file_get_contents($project->path('note.txt')));
    }

    public function testDisabledSettingSkipsEditorConfig(): void
    {
        $project = new TempProject([
            'composer.json' => '{}',
            '.porta.json' => json_encode(['settings' => ['editorConfig' => false]]),
            '.editorconfig' => "root = true\n[*.txt]\nindent_size = 2\ntrim_trailing_whitespace = true\n",
            'note.txt' => "keep  \n",
        ]);
        $ide = $this->ide($project);
        $buffer = $this->open($ide, $project->path('note.txt'));

        // No config applied to the document, and none stashed on the buffer.
        $this->assertSame(4, $buffer->document->indentSize());
        $this->assertNull($buffer->editorConfig);

        // Save writes the untouched (LF, untrimmed) bytes.
        $this->save($ide, $buffer);
        $this->assertSame("keep  \n", (string) file_get_contents($project->path('note.txt')));
    }

    public function testUnsupportedCharsetFlashesWarning(): void
    {
        $project = new TempProject([
            'composer.json' => '{}',
            '.editorconfig' => "root = true\n[*.py]\ncharset = latin1\n",
            'main.py' => "x = 1\n",
        ]);
        $ide = $this->ide($project);

        $buffer = $this->open($ide, $project->path('main.py'));

        $this->assertSame('latin1', $buffer->editorConfig?->charset);
        $this->assertStringContainsString('charset "latin1" ignored', (string) $this->flashText($ide));
    }

    public function testUtf8CharsetDoesNotWarn(): void
    {
        $project = new TempProject([
            'composer.json' => '{}',
            '.editorconfig' => "root = true\n[*.py]\ncharset = utf-8\n",
            'main.py' => "x = 1\n",
        ]);
        $ide = $this->ide($project);

        $this->open($ide, $project->path('main.py'));

        $this->assertNull($this->flashText($ide), 'utf-8 is honoured silently');
    }
}
