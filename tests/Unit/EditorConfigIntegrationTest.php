<?php

declare(strict_types=1);

namespace Porta\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Tests\Support\AwaitsGit;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * End-to-end wiring of EditorConfig through the coordinator: indentation applied
 * to the live document on open, and the whitespace/line-ending rules applied to
 * the on-disk bytes on save.
 */
final class EditorConfigIntegrationTest extends TestCase
{
    use AwaitsGit;
    use PumpsLoop;
    use PreservesPath;

    protected function setUp(): void
    {
        putenv('PATH=/nonexistent'); // keep git/LSP out of these unit runs
    }

    /** The Ide the current test built, so tearDown can perform the teardown run() never gets to. */
    private ?Ide $ide = null;

    /**
     * These tests build an Ide and never run() it, so the teardown in its
     * finally never happens — and a flash arms a toast-expiry timer that
     * outlives the test. Same three parts as IdeHelpersTest, for the same
     * reason.
     */
    protected function tearDown(): void
    {
        if (null === $this->ide) {
            return;
        }

        Services::resolve($this->ide, ViewRegistry::class)->closeAll();
        Services::resolve($this->ide, Notifier::class)->dismissAll();
    }

    private function ide(TempProject $project): Ide
    {
        new OnboardingState($project->dir)->markWelcomeSeen();

        return $this->ide = new Ide($project->dir, null, new VirtualTerminal(columns: 80, rows: 24));
    }

    private function open(Ide $ide, string $path): Buffer
    {
        Services::resolve($ide, BufferService::class)->openFile($path, OpenFocus::Keep);
        $buffers = Services::resolve($ide, BufferManager::class);
        \assert($buffers instanceof BufferManager, description: 'the buffers property is a BufferManager');

        return $buffers->active();
    }

    private function save(Ide $ide, Buffer $buffer): void
    {
        $service = Services::resolve($ide, BufferService::class);
        \assert($service instanceof BufferService, description: 'the bufferService property is a BufferService');
        $service->saveBuffer($buffer);
    }

    private function flashText(Ide $ide): ?string
    {
        // Notifications are corner toasts now; read the newest one unwrapped.
        $last = \Porta\Tests\Support\Toasts::last($ide);

        return '' === $last ? null : $last;
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

    public function testLegacyEditorConfigKeyIsSilentlyIgnored(): void
    {
        // "editorConfig" was the removed "Respect .editorconfig" toggle: a stale
        // false in an old .porta.json must neither disable .editorconfig nor warn.
        $project = new TempProject([
            'composer.json' => '{}',
            '.porta.json' => json_encode(['settings' => ['editorConfig' => false]]),
            '.editorconfig' => "root = true\n[*.txt]\nindent_size = 2\ntrim_trailing_whitespace = true\ninsert_final_newline = true\n",
            'note.txt' => "keep  \n",
        ]);
        $ide = $this->ide($project);
        $buffer = $this->open($ide, $project->path('note.txt'));

        // .editorconfig still applies: to the document and stashed on the buffer.
        $this->assertSame(2, $buffer->document->indentSize());
        $this->assertNotNull($buffer->editorConfig);

        // The legacy key raised no boot warning.
        $this->assertStringNotContainsString('Unknown setting', (string) $this->flashText($ide));

        // Save runs the .editorconfig transforms (trailing spaces trimmed).
        $this->save($ide, $buffer);
        $this->assertSame("keep\n", (string) file_get_contents($project->path('note.txt')));
    }

    public function testSaveWithoutAResolvedConfigWritesThePlainBytes(): void
    {
        // Defensive fallback: every text open resolves a config, but a buffer
        // whose config was dropped (nullable property) saves its bytes verbatim.
        $project = new TempProject([
            'composer.json' => '{}',
            '.editorconfig' => "root = true\n[*.txt]\ntrim_trailing_whitespace = true\n",
            'note.txt' => "keep  \nno final newline",
        ]);
        $ide = $this->ide($project);
        $buffer = $this->open($ide, $project->path('note.txt'));

        $buffer->editorConfig = null;
        $this->save($ide, $buffer);

        $this->assertSame("keep  \nno final newline", (string) file_get_contents($project->path('note.txt')), 'no transforms without a config');
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
