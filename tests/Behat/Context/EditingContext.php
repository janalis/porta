<?php

declare(strict_types=1);

namespace Porta\Tests\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Behat\Behat\Context\Environment\InitializedContextEnvironment;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Janalis\Tuink\Context\TuinkAwareContextInterface;
use Janalis\Tuink\Context\TuinkAwareTrait;
use Porta\Editor\BufferService;
use Porta\Editor\Document;
use Porta\Editor\OpenFocus;
use Porta\Editor\SelectionMode;
use Porta\Widget\TabBarWidget;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Widgets;

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
        $this->getSession()->getWorkspace()->write($file, "<?php\n".\implode("\n", $lines)."\n");
    }

    // --- The caret and the selection -------------------------------------------

    #[Given('the caret is at line :line, column :column')]
    #[When('I put the caret at line :line, column :column')]
    public function theCaretIsAt(int $line, int $column): void
    {
        $this->document()->setCursor($line - 1, $column - 1);
        $this->getSession()->settle();
    }

    #[Given('I select from line :fromLine, column :fromColumn to line :toLine, column :toColumn')]
    #[Given('the text from line :fromLine, column :fromColumn to line :toLine, column :toColumn is selected')]
    public function iSelect(int $fromLine, int $fromColumn, int $toLine, int $toColumn): void
    {
        $document = $this->document();
        $document->setCursor($fromLine - 1, $fromColumn - 1);
        $document->beginSelect();
        $document->setCursor($toLine - 1, $toColumn - 1, SelectionMode::Keep);
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
            fn (): string => \sprintf("The selection is \"%s\", not \"%s\".", $this->document()->getSelectedText(), $expected),
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
        $this->assertPinned($file, pinned: true);
    }

    #[Then('the tab :file should not be pinned')]
    public function theTabShouldNotBePinned(string $file): void
    {
        $this->assertPinned($file, pinned: false);
    }

    private function assertPinned(string $file, bool $pinned): void
    {
        $session = $this->getSession();
        $path = (string) \realpath($session->getWorkspace()->resolve($file));
        $buffers = $this->porta()->service(BufferService::class);
        $session->waitUntil(
            static fn (): bool => $pinned === $buffers->isPinned($path),
            \sprintf('The tab "%s" is %s.', $file, $pinned ? 'not pinned' : 'still pinned'),
        );
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
        $provider = Probe::get($tabBar, property: 'provider');
        \assert($provider instanceof \Closure);
        /** @var list<array{name: string}> $tabs */
        $tabs = $provider();

        return \array_column($tabs, 'name');
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
