<?php

declare(strict_types=1);

namespace Porta\Tests\Behat\Context;

use Behat\Behat\Context\Environment\InitializedContextEnvironment;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Janalis\Tuink\Context\TuinkAwareContextInterface;
use Janalis\Tuink\Context\TuinkAwareTrait;
use Porta\Editor\BufferService;
use Porta\Editor\Document;
use Porta\Editor\OpenFocus;
use Porta\Editor\SelectionMode;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Widgets;
use Porta\Widget\TabBarWidget;

/**
 * Files, tabs and editing: where the caret is, what is selected, which tabs
 * are open — the editor's state a screen shows only as a highlight.
 *
 * Lines and columns count from 1, as the status bar does.
 */
final class EditingContext implements TuinkAwareContextInterface
{
    use TuinkAwareTrait;

    private ?PortaContext $porta = null;

    #[BeforeScenario]
    public function gatherContexts(BeforeScenarioScope $scope): void
    {
        $environment = $scope->getEnvironment();
        if ($environment instanceof InitializedContextEnvironment) {
            $this->porta = $environment->getContext(PortaContext::class);
        }
    }

    // --- Files ------------------------------------------------------------------

    #[Given('a file :file containing :count numbered lines')]
    public function aFileOfNumberedLines(string $file, int $count): void
    {
        $lines = \array_map(static fn (int $n): string => \sprintf('// line %d ', $n), \range(1, $count));
        $this
            ->getSession()
            ->getWorkspace()
            ->write($file, "<?php\n".\implode("\n", $lines)."\n");
    }

    #[Given('a file :file containing a class with a :count-line method')]
    public function aFileWithALongMethod(string $file, int $count): void
    {
        $class = \pathinfo($file, \PATHINFO_FILENAME);
        $body = \str_repeat("        \$x = 1;\n", \max(0, $count));
        $this->getSession()->getWorkspace()->write($file, "<?php\n\nclass {$class}\n{\n    public function handle(): void\n    {\n{$body}    }\n}\n");
    }

    #[Given('a file :file containing a line of :count characters ending in :end')]
    public function aFileWithALongLine(string $file, int $count, string $end): void
    {
        $this
            ->getSession()
            ->getWorkspace()
            ->write($file, "<?php\n// ".\str_repeat('x', \max(0, $count - 3 - \strlen($end))).$end."\n");
    }

    /**
     * Replaces the shown file's text in one edit, then saves it with its key.
     */
    #[Given('I save the file with the text :text')]
    public function iSaveAsTheContent(string $text): void
    {
        $content = \str_replace('\\n', "\n", $text);
        $document = $this->document();
        $document->selectAll();
        $document->insert($content);
        $this->porta()->iUseTheAction('save');
        $path = (string) Widgets::editor($this->porta()->tui())->getFilePath();
        $this->getSession()->waitUntil(
            static fn (): bool => \file_get_contents($path) === $content,
            \sprintf('"%s" was not saved.', $path),
        );
    }

    /**
     * What .porta.json's "settings" hold for $key: `true`, `false` and numbers as JSON, anything else as a string.
     */
    #[Then('the project setting :key should be :value')]
    public function theProjectSettingShouldBe(string $key, string $value): void
    {
        $expected = match ($value) {
            'true' => true,
            'false' => false,
            default => \is_numeric($value) ? (int) $value : $value,
        };
        $porta = $this->porta();
        $this->getSession()->waitUntil(
            static fn (): bool => self::setting($porta, $key) === $expected,
            static fn (): string => \sprintf('.porta.json has "%s": %s.', $key, \json_encode(self::setting($porta, $key), \JSON_THROW_ON_ERROR)),
        );
    }

    #[When('I restart the app with the project configuration:')]
    public function iRestartWithTheProjectConfiguration(PyStringNode $json): void
    {
        $session = $this->getSession();
        $session->stop();
        $this->porta()->theProjectConfiguration($json);
        $session->start();
    }

    // --- The caret and the selection -------------------------------------------

    #[Given('the caret is at line :line, column :column')]
    #[When('I put the caret at line :line, column :column')]
    public function theCaretIsAt(int $line, int $column): void
    {
        $this->document()->setCursor($line - 1, $column - 1);
        $this->porta()->tui()->requestRender();
        $this->getSession()->settle();
    }

    /**
     * Go to Line, as a user would: the prompt, the number, Enter.
     */
    #[When('I go to line :line')]
    public function iGoToLine(int $line): void
    {
        $this->porta()->iUseTheAction('goto_line');
        $session = $this->getSession();
        $session->type((string) $line);
        $session->press('Enter');
        $this->theCaretShouldBeOnLine($line);
    }

    #[Given('I select from line :fromLine, column :fromColumn to line :toLine, column :toColumn')]
    #[Given('the text from line :fromLine, column :fromColumn to line :toLine, column :toColumn is selected')]
    public function iSelect(int $fromLine, int $fromColumn, int $toLine, int $toColumn): void
    {
        $document = $this->document();
        $document->setCursor($fromLine - 1, $fromColumn - 1);
        $document->beginSelect();
        $document->setCursor($toLine - 1, $toColumn - 1, SelectionMode::Keep);
        $this->porta()->tui()->requestRender();
        $this->getSession()->settle();
    }

    #[Then('the caret should be at line :line, column :column')]
    public function theCaretShouldBeAt(int $line, int $column): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => [$line, $column] === $this->caret(),
            fn (): string => \sprintf('The caret is at line %d, column %d, not line %d, column %d.', ...[...$this->caret(), $line, $column]),
        );
    }

    #[Then('the caret should be on line :line')]
    public function theCaretShouldBeOnLine(int $line): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => $line === $this->caret()[0],
            fn (): string => \sprintf('The caret is on line %d, not %d.', $this->caret()[0], $line),
        );
    }

    #[Then('the caret should be below line :line')]
    public function theCaretShouldBeBelowLine(int $line): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => $this->caret()[0] > $line,
            fn (): string => \sprintf('The caret is on line %d, not below line %d.', $this->caret()[0], $line),
        );
    }

    #[Then('the selection should be :text')]
    public function theSelectionShouldBe(string $text): void
    {
        $expected = \str_replace('\n', "\n", $text);
        $this->getSession()->waitUntil(
            fn (): bool => $expected === $this->document()->getSelectedText(),
            fn (): string => \sprintf('The selection is "%s", not "%s".', $this->document()->getSelectedText(), $expected),
        );
    }

    #[Then('the selection should be:')]
    public function theSelectionShouldBeBlock(PyStringNode $text): void
    {
        $this->theSelectionShouldBe(\str_replace("\n", '\n', $text->getRaw()));
    }

    #[Then('nothing should be selected')]
    public function nothingShouldBeSelected(): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => !$this->document()->hasSelection(),
            fn (): string => \sprintf('"%s" is still selected.', $this->document()->getSelectedText()),
        );
    }

    #[Then('there should be :count carets')]
    #[Then('there should be :count caret')]
    public function thereShouldBeCarets(int $count): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => $count === $this->caretCount(),
            fn (): string => \sprintf('There are %d carets, not %d.', $this->caretCount(), $count),
        );
    }

    // --- Buffers and tabs ---------------------------------------------------------

    #[When('I open :file')]
    public function iOpen(string $file): void
    {
        $session = $this->getSession();
        $this->porta()->service(BufferService::class)->openFile($session->getWorkspace()->resolve($file), OpenFocus::Editor);
        $session->settle();
        $this->porta()->theEditorShouldShow($file);
    }

    #[Given('the files :files are open')]
    public function theFilesAreOpen(string $files): void
    {
        foreach (self::names($files) as $file) {
            $this->iOpen($file);
        }
    }

    #[Then('the tabs should be :names')]
    public function theTabsShouldBe(string $names): void
    {
        $expected = self::names($names);
        $this->getSession()->waitUntil(
            fn (): bool => $expected === $this->tabNames(),
            fn (): string => \sprintf('The tabs are "%s".', \implode(', ', $this->tabNames())),
        );
    }

    #[Then('the tab :file should be pinned')]
    public function theTabShouldBePinned(string $file): void
    {
        $session = $this->getSession();
        $session->waitUntil(fn (): bool => $this->isPinned($file), \sprintf('The tab "%s" is not pinned.', $file));
    }

    #[Then('the tab :file should not be pinned')]
    public function theTabShouldNotBePinned(string $file): void
    {
        $session = $this->getSession();
        $session->waitUntil(fn (): bool => !$this->isPinned($file), \sprintf('The tab "%s" is still pinned.', $file));
    }

    private function isPinned(string $file): bool
    {
        $path = (string) \realpath($this->getSession()->getWorkspace()->resolve($file));

        return $this->porta()->service(BufferService::class)->isPinned($path);
    }

    private static function setting(PortaContext $porta, string $key): mixed
    {
        $config = $porta->projectConfig();

        return \is_array($config['settings'] ?? null) ? $config['settings'][$key] ?? null : null;
    }

    // --- Reads ------------------------------------------------------------------

    public function document(): Document
    {
        return Widgets::editor($this->porta()->tui())->getDocument();
    }

    /**
     * @return array{int, int}
     */
    private function caret(): array
    {
        $document = $this->document();

        return [$document->cursorLine + 1, $document->cursorCol + 1];
    }

    private function caretCount(): int
    {
        return \count(EditorState::multiCaret(Widgets::editor($this->porta()->tui()))->allCarets());
    }

    /**
     * @return list<string>
     */
    private function tabNames(): array
    {
        $tabBar = Widgets::byId($this->porta()->tui(), id: 'tabs', class: TabBarWidget::class);
        $provider = Probe::object($tabBar, 'provider');
        if (!$provider instanceof \Closure) {
            throw new \LogicException('The tab bar has no provider.');
        }
        /** @var list<array{name: string}> $tabs */
        $tabs = $provider();

        // A pinned tab's name carries the pin glyph; the pin has a step of its own.
        return \array_map(static fn (string $name): string => \preg_replace('/^⌾ /u', '', $name) ?? $name, \array_column($tabs, 'name'));
    }

    /**
     * @return list<string>
     */
    private static function names(string $list): array
    {
        return \array_values(\array_filter(\array_map(\trim(...), \explode(',', $list)), static fn (string $name): bool => '' !== $name));
    }

    private function porta(): PortaContext
    {
        return $this->porta ?? throw new \LogicException('The PortaContext is not in this suite.');
    }
}
