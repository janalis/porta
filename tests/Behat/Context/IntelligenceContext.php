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
use Janalis\Tuink\Style;
use PHPUnit\Framework\Assert;
use Porta\Editor\Document;
use Porta\Tests\Support\LspWireLog;
use Porta\Tests\Support\Toasts;
use Porta\Tests\Support\Widgets;

/**
 * Code intelligence, search and the language-server plugins: the caret, the
 * completion popup and the toasts a language server's answer raises.
 *
 * Lines and columns in these steps count from 1, as the gutter and the status
 * bar show them.
 */
final class IntelligenceContext implements TuinkAwareContextInterface
{
    use TuinkAwareTrait;

    private PortaContext $porta;

    #[BeforeScenario]
    public function gatherContexts(BeforeScenarioScope $scope): void
    {
        $environment = $scope->getEnvironment();
        Assert::assertInstanceOf(InitializedContextEnvironment::class, $environment, 'Behat hands its contexts to an initialized environment');
        $this->porta = $environment->getContext(PortaContext::class);
    }

    // --- The language server --------------------------------------------------

    /**
     * A request made before `initialize` answers is dropped in silence: the
     * status bar naming the server is the sign it is up.
     */
    #[Given('the language server is ready')]
    public function theLanguageServerIsReady(): void
    {
        $this->getSession()->assertSee('LSP:mock-lsp-server');
    }

    // --- The caret ---------------------------------------------------------------

    #[When('I put the caret at line :line, column :column')]
    public function iPutTheCaretAt(int $line, int $column): void
    {
        $this->document()->setCursor($line - 1, $column - 1);
        $this->porta->tui()->requestRender();
        $this->getSession()->settle();
    }

    #[When('I select from line :fromLine, column :fromColumn to line :toLine, column :toColumn')]
    public function iSelect(int $fromLine, int $fromColumn, int $toLine, int $toColumn): void
    {
        $this->document()->setSelectionRange($fromLine - 1, $fromColumn - 1, $toLine - 1, $toColumn - 1);
        $this->porta->tui()->requestRender();
        $this->getSession()->settle();
    }

    #[Then('the caret should be at line :line, column :column')]
    public function theCaretShouldBeAt(int $line, int $column): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => $this->document()->cursorLine === ($line - 1) && $this->document()->cursorCol === ($column - 1),
            fn (): string => \sprintf('The caret is at line %d, column %d.', $this->document()->cursorLine + 1, $this->document()->cursorCol + 1),
        );
    }

    #[Then('the caret should be on line :line')]
    public function theCaretShouldBeOnLine(int $line): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => $this->document()->cursorLine === ($line - 1),
            fn (): string => \sprintf('The caret is on line %d.', $this->document()->cursorLine + 1),
        );
    }

    #[Then('line :line of the editor should be :text')]
    public function lineOfTheEditorShouldBe(int $line, string $text): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => $line <= $this->document()->getLineCount() && $this->document()->getLine($line - 1) === $text,
            fn (): string => \sprintf("Line %d of the editor is not \"%s\".\n\nThe editor holds:\n%s", $line, $text, $this->porta->editorText()),
        );
    }

    #[Then('the selected text should be :text')]
    public function theSelectedTextShouldBe(string $text): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => $this->document()->getSelectedText() === $text,
            fn (): string => \sprintf('The selection is "%s".', $this->document()->getSelectedText()),
        );
    }

    // --- Completion --------------------------------------------------------------

    #[Then('the completion popup should be open')]
    public function theCompletionPopupShouldBeOpen(): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => Widgets::editor($this->porta->tui())->isCompletionOpen(),
            'The completion popup is not open.',
        );
    }

    #[Then('the completion popup should be closed')]
    public function theCompletionPopupShouldBeClosed(): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => !Widgets::editor($this->porta->tui())->isCompletionOpen(),
            'The completion popup is still open.',
        );
    }

    // --- Toasts --------------------------------------------------------------------

    /**
     * Reads the toast center rather than the card, so a wrap point cannot hide
     * the words; a toast is short-lived, so this waits for it to be raised.
     */
    #[Then('a toast should say :text')]
    public function aToastShouldSay(string $text): void
    {
        $ide = $this->porta->ide();
        $this->getSession()->waitUntil(
            static fn (): bool => [] !== \array_filter(Toasts::all($ide), static fn (string $m): bool => \str_contains($m, $text)),
            static fn (): string => \sprintf("No toast says \"%s\". Toasts:\n%s", $text, \implode("\n", Toasts::all($ide))),
        );
    }

    // --- Stub tools ---------------------------------------------------------------

    /**
     * A stand-in for a language server or a CLI, in the workspace. `%php%` is
     * this PHP binary and `%support%` the PHPUnit rig's directory, where the
     * mock and logging language servers live.
     */
    #[Given('an executable file :path containing:')]
    public function anExecutableFile(string $path, PyStringNode $body): void
    {
        $workspace = $this->getSession()->getWorkspace();
        $workspace->write($path, \strtr($body->getRaw(), [
            '%php%' => \PHP_BINARY,
            '%support%' => \dirname(__DIR__, 2).'/Support',
            '%workspace%' => $workspace->path,
        ])
            ."\n");
        \chmod($workspace->resolve($path), 0o755);
    }

    #[Given('the :plugin plugin setting :key is the workspace path :path')]
    public function thePluginSettingIsTheWorkspacePath(string $plugin, string $key, string $path): void
    {
        $this->porta->mergeProjectConfig(['plugins' => [$plugin => [$key => $this->getSession()->getWorkspace()->resolve($path)]]]);
    }

    /**
     * The `initializationOptions` the logging language server was started
     * with, the last time: every key given must have the value given.
     */
    #[Then('the language server logged in :log should have been initialized with:')]
    public function theServerShouldHaveBeenInitializedWith(string $log, PyStringNode $json): void
    {
        $path = $this->getSession()->getWorkspace()->resolve($log);
        $last = static function () use ($path): string {
            $all = LspWireLog::initializeOptions($path);

            return [] === $all ? '{}' : \json_encode(\array_last($all), \JSON_THROW_ON_ERROR);
        };
        $expected = self::object($json->getRaw());
        $this->getSession()->waitUntil(
            static fn (): bool => \array_intersect_key(self::object($last()), $expected) === $expected,
            static fn (): string => \sprintf('The server was initialized with %s.', $last()),
        );
    }

    // --- Settings -------------------------------------------------------------------

    /**
     * What `.porta.json` holds for a core setting, as JSON: `"none"`, `false`.
     */
    #[Then('the project setting :key should be :value')]
    public function theProjectSettingShouldBe(string $key, string $value): void
    {
        $this->waitForConfig(['settings', $key], $value);
    }

    #[Then('the :plugin plugin setting :key should be :value')]
    public function thePluginSettingShouldBe(string $plugin, string $key, string $value): void
    {
        $this->waitForConfig(['plugins', $plugin, $key], $value);
    }

    /**
     * What `.porta.json` holds at $path, compared as JSON: `"none"`, `false`.
     *
     * @param list<string> $path
     */
    private function waitForConfig(array $path, string $value): void
    {
        $expected = \json_encode(\json_decode($value, associative: true) ?? $value, \JSON_THROW_ON_ERROR);
        $read = fn (): string => self::at($this->porta->projectConfig(), $path);
        $this->getSession()->waitUntil(
            static fn (): bool => $read() === $expected,
            static fn (): string => \sprintf('The project configuration has %s at %s.', $read(), \implode('.', $path)),
        );
    }

    /**
     * @param list<string> $path
     *
     * @return string the JSON of what $node holds at $path, `null` when nothing
     */
    private static function at(mixed $node, array $path): string
    {
        if ([] === $path) {
            return \json_encode($node, \JSON_THROW_ON_ERROR);
        }
        $key = \array_shift($path);

        return self::at(\is_array($node) ? $node[$key] ?? null : null, $path);
    }

    /**
     * @return array<array-key, mixed> sorted by key, so two objects compare whatever their order
     */
    private static function object(string $json): array
    {
        return self::sorted(\json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function sorted(mixed $decoded): array
    {
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('Expected a JSON object.');
        }
        \ksort($decoded);

        return $decoded;
    }

    // --- Generic: belongs in tuink ---------------------------------------------------

    /**
     * The first screen line showing $anchor, checked for $text: what trails a
     * line of code, wherever the layout puts it.
     */
    #[Then('the line showing :anchor should contain :text')]
    public function theLineShowingShouldContain(string $anchor, string $text): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => \str_contains((string) $this->lineShowing($anchor), $text),
            fn (): string => \sprintf('The line showing "%s" does not contain "%s": %s', $anchor, $text, $this->lineShowing($anchor) ?? 'no line shows it'),
        );
    }

    #[Then('the line showing :anchor should not contain :text')]
    public function theLineShowingShouldNotContain(string $anchor, string $text): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => null !== $this->lineShowing($anchor) && !\str_contains((string) $this->lineShowing($anchor), $text),
            fn (): string => \sprintf('The line showing "%s" contains "%s": %s', $anchor, $text, $this->lineShowing($anchor) ?? 'no line shows it'),
        );
    }

    #[Then('the text :text should have no color')]
    public function theTextShouldHaveNoColor(string $text): void
    {
        $session = $this->getSession();
        $session->waitUntil(
            static fn (): bool => [] === \array_filter($session->getTextStyles($text), static fn (Style $style): bool => null !== $style->foreground),
            \sprintf('The text "%s" is colored.', $text),
        );
    }

    #[Then('the text :text should have a color')]
    public function theTextShouldHaveAColor(string $text): void
    {
        $session = $this->getSession();
        $session->waitUntil(
            static fn (): bool => [] === \array_filter($session->getTextStyles($text), static fn (Style $style): bool => null === $style->foreground),
            \sprintf('The text "%s" is not colored throughout.', $text),
        );
    }

    private function lineShowing(string $anchor): ?string
    {
        foreach ($this->getSession()->getLines() as $line) {
            if (\str_contains($line, $anchor)) {
                return $line;
            }
        }

        return null;
    }

    private function document(): Document
    {
        return Widgets::editor($this->porta->tui())->getDocument();
    }
}
