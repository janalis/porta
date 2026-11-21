<?php

declare(strict_types=1);

namespace Porta\Tests\Behat\Context;

use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;
use Behat\Behat\Context\Environment\InitializedContextEnvironment;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Step\Given;
use Behat\Step\Then;
use Janalis\Tuink\Context\TuinkAwareContextInterface;
use Janalis\Tuink\Context\TuinkAwareTrait;
use Porta\Config\WorkspaceTrust;
use Porta\Tests\Support\Widgets;
use Porta\Widget\FileTreeWidget;
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
            new Filesystem()->remove($this->home);
            $this->home = null;
        }
    }

    // --- Trust ------------------------------------------------------------------

    /**
     * The suite trusts its whole temp dir through PORTA_TRUSTED_FOLDERS, which
     * no answer outranks: these scenarios clear it, and keep the answers in a
     * home of their own (the shared fixture home's notes copied, so nothing
     * checks for updates).
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

    private function trust(): WorkspaceTrust
    {
        if (null === $this->home) {
            $home = \sys_get_temp_dir().'/porta-behat-home-'.\bin2hex(\random_bytes(4));
            new Filesystem()->mirror(\dirname(__DIR__, 2).'/Support/fixtures/home', $home);
            $this->home = $home;
            $session = $this->getSession();
            $session->setLaunch($session->getLaunch()->withEnv('PORTA_HOME', $home)->withEnv('PORTA_TRUSTED_FOLDERS', ''));
        }

        return new WorkspaceTrust($this->home);
    }

    // --- Where the IDE goes next ------------------------------------------------

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
        $tree = Widgets::byId($this->porta()->tui(), id: 'tree', class: FileTreeWidget::class);
        $node = $tree->selectedNode();

        return $node?->path;
    }

    private function porta(): PortaContext
    {
        return $this->porta ?? throw new \LogicException('The Porta context is not in this suite.');
    }
}
