<?php

declare(strict_types=1);

namespace Porta\Tests\Behat\Context;

use Behat\Behat\Context\Environment\InitializedContextEnvironment;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Janalis\Tuink\Context\TuinkAwareContextInterface;
use Janalis\Tuink\Context\TuinkAwareTrait;
use Porta\Config\WorkspaceTrust;
use Porta\Help\RenderedDoc;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Widgets;
use Porta\Ui\Graphics\TerminalGraphics;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\ReopenRequest;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The IDE's chrome: trust, the tree's selection, where the IDE goes next —
 * what the screen alone does not say.
 */
final class ChromeContext implements TuinkAwareContextInterface
{
    use TuinkAwareTrait;

    private ?PortaContext $porta = null;

    /** A home of the scenario's own, where the trust answers are kept. */
    private ?string $home = null;

    /** The bottom panel's height when the scenario noted it. */
    private ?int $notedPanelRows = null;

    #[BeforeScenario]
    public function gatherContexts(BeforeScenarioScope $scope): void
    {
        $environment = $scope->getEnvironment();
        if ($environment instanceof InitializedContextEnvironment) {
            $this->porta = $environment->getContext(PortaContext::class);
        }
    }

    #[AfterScenario]
    public function removeHome(): void
    {
        if (null !== $this->home) {
            // Behat runs this hook before tuink stops the app: shut the Ide down first, so its
            // teardown (the session save, the desktop recovery) does not write into a removed home
            $this->getSession()->stop();
            new Filesystem()->remove($this->home);
            $this->home = null;
        }
    }

    // --- Trust ------------------------------------------------------------------

    /**
     * The suite trusts its whole temp dir through PORTA_TRUSTED_FOLDERS, which
     * no answer outranks: these scenarios clear it, and keep the answers in a
     * home of their own.
     */
    #[Given('the project was trusted')]
    public function theProjectWasTrusted(): void
    {
        $this->trust()->trust($this->getSession()->getWorkspace()->path);
    }

    #[Given('the project was opened restricted')]
    public function theProjectWasOpenedRestricted(): void
    {
        $this->trust()->restrict($this->getSession()->getWorkspace()->path);
    }

    #[Then('the project should be trusted from now on')]
    public function theProjectShouldBeTrusted(): void
    {
        $session = $this->getSession();
        $session->waitUntil(
            fn (): bool => $this->trust()->isTrusted($session->getWorkspace()->path),
            'The project is not trusted.',
        );
    }

    #[Then('the project should open restricted from now on')]
    public function theProjectShouldOpenRestricted(): void
    {
        $session = $this->getSession();
        $session->waitUntil(
            fn (): bool => !$this->trust()->isTrusted($session->getWorkspace()->path) && !$this->trust()->shouldAsk($session->getWorkspace()->path),
            'The project is not answered as restricted.',
        );
    }

    #[Given('no project was opened before')]
    public function noProjectWasOpenedBefore(): void
    {
        new Filesystem()->remove($this->home().'/.porta/recent-projects.json');
    }

    private function trust(): WorkspaceTrust
    {
        $session = $this->getSession();
        $session->setLaunch($session->getLaunch()->withEnv('PORTA_TRUSTED_FOLDERS', ''));

        return new WorkspaceTrust($this->home());
    }

    /**
     * A home of the scenario's own: the shared fixture home's notes copied, so
     * nothing checks for updates, and its answers and recent projects kept
     * apart from every other scenario's.
     */
    private function home(): string
    {
        if (null === $this->home) {
            $home = \sys_get_temp_dir().'/porta-behat-home-'.\bin2hex(\random_bytes(4));
            new Filesystem()->mirror(\dirname(__DIR__, 2).'/Support/fixtures/home', $home);
            $this->home = $home;
            $session = $this->getSession();
            $session->setLaunch($session->getLaunch()->withEnv('PORTA_HOME', $home));
        }

        return $this->home;
    }

    // --- Where the IDE goes next ------------------------------------------------

    #[When('I type the path of the folder :dir')]
    public function iTypeThePathOf(string $dir): void
    {
        $session = $this->getSession();
        $session->type($session->getWorkspace()->resolve($dir));
    }

    #[Then('the IDE should close to reopen the project in :dir')]
    public function theIdeShouldReopenIn(string $dir): void
    {
        $this->assertReopens($this->getSession()->getWorkspace()->resolve($dir));
    }

    #[Then('the IDE should close to reopen the project')]
    public function theIdeShouldReopenTheProject(): void
    {
        $this->assertReopens($this->getSession()->getWorkspace()->path);
    }

    private function assertReopens(string $root): void
    {
        $session = $this->getSession();
        $session->waitUntil(
            fn (): bool => null !== ($next = $this->porta()->service(ReopenRequest::class)->root()) && \realpath($next) === \realpath($root),
            fn (): string => \sprintf('The IDE is not set to reopen in "%s" (it would reopen in "%s").', $root, $this->porta()->service(ReopenRequest::class)->root() ?? 'nothing'),
        );
    }

    // --- The file tree ----------------------------------------------------------

    #[Then('the tree should have :path selected')]
    public function theTreeShouldHaveSelected(string $path): void
    {
        $session = $this->getSession();
        $expected = $session->getWorkspace()->resolve($path);
        $session->waitUntil(
            fn (): bool => \rtrim($this->selectedTreePath() ?? '', '/') === \rtrim($expected, '/'),
            fn (): string => \sprintf('The tree has "%s" selected, not "%s".', $this->selectedTreePath() ?? 'nothing', $path),
        );
    }

    private function selectedTreePath(): ?string
    {
        return $this->tree()->selectedNode()?->path;
    }

    // --- The documentation browser ---------------------------------------------

    /**
     * Read before Enter: a web link would open the browser of the machine
     * running the suite.
     */
    #[Then('the focused link should lead to :target')]
    public function theFocusedLinkShouldLeadTo(string $target): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => $this->focusedLink() === $target,
            fn (): string => \sprintf('The focused link leads to "%s", not "%s".', $this->focusedLink() ?? 'nothing', $target),
        );
    }

    #[Then('the documentation should show :file')]
    public function theDocumentationShouldShow(string $file): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => \str_ends_with($this->helpViewer()->path() ?? '', '/'.$file),
            fn (): string => \sprintf('The documentation shows "%s", not "%s".', $this->helpViewer()->path() ?? 'nothing', $file),
        );
    }

    private function focusedLink(): ?string
    {
        $viewer = $this->helpViewer();
        $doc = Probe::object($viewer, 'doc');
        $link = (int) Probe::get($viewer, property: 'currentLink');

        return $doc instanceof RenderedDoc ? $doc->target($link) : null;
    }

    private function helpViewer(): HelpViewerWidget
    {
        return Widgets::byId($this->porta()->tui(), id: 'help-viewer', class: HelpViewerWidget::class);
    }

    // --- Appearance ---------------------------------------------------------------

    /**
     * What a 100% zoom means in the image and graph viewers: no terminal here
     * draws pixels, so the ratio they would scale by is read where they read it.
     */
    #[Then('the viewers should draw :ratio device pixels per logical pixel')]
    public function theViewersShouldDrawAtRatio(float $ratio): void
    {
        $graphics = $this->porta()->service(TerminalGraphics::class);
        $this->getSession()->waitUntil(
            static fn (): bool => \abs($graphics->pixelRatio() - $ratio) < 1e-9,
            static fn (): string => \sprintf('The viewers draw %s device pixels per logical pixel, not %s.', $graphics->pixelRatio(), $ratio),
        );
    }

    // --- Layout -------------------------------------------------------------------

    #[Then('the tree should be :columns columns wide')]
    public function theTreeShouldBeWide(int $columns): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => $this->tree()->width() === $columns,
            fn (): string => \sprintf('The tree is %d columns wide, not %d.', $this->tree()->width(), $columns),
        );
    }

    #[When('I note the height of the bottom panel')]
    public function iNoteTheBottomPanelHeight(): void
    {
        $this->notedPanelRows = $this->panelRows() ?? throw new \LogicException('No bottom panel is showing.');
    }

    #[Then('the bottom panel should be taller than noted')]
    public function theBottomPanelShouldBeTaller(): void
    {
        $this->waitForPanelRows(fn (int $rows): bool => $rows > $this->noted(), 'taller than');
    }

    #[Then('the bottom panel should be as tall as noted')]
    public function theBottomPanelShouldBeAsTall(): void
    {
        $this->waitForPanelRows(fn (int $rows): bool => $rows === $this->noted(), 'as tall as');
    }

    /** @param \Closure(int): bool $holds */
    private function waitForPanelRows(\Closure $holds, string $relation): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => null !== ($rows = $this->panelRows()) && $holds($rows),
            fn (): string => \sprintf('The bottom panel is %s rows tall, not %s the %d noted.', $this->panelRows() ?? 'no', $relation, $this->noted()),
        );
    }

    private function noted(): int
    {
        return $this->notedPanelRows ?? throw new \LogicException('No height was noted.');
    }

    private function panelRows(): ?int
    {
        return $this->porta()->service(BottomDock::class)->visiblePanel()?->visibleRows();
    }

    private function tree(): FileTreeWidget
    {
        return Widgets::byId($this->porta()->tui(), id: 'tree', class: FileTreeWidget::class);
    }

    private function porta(): PortaContext
    {
        return $this->porta ?? throw new \LogicException('The Porta context is not in this suite.');
    }
}
