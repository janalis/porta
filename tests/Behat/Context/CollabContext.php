<?php

declare(strict_types=1);

namespace Porta\Tests\Behat\Context;

use Behat\Behat\Context\Environment\InitializedContextEnvironment;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Janalis\Tuink\Context\TuinkAwareContextInterface;
use Janalis\Tuink\Context\TuinkAwareTrait;
use PHPUnit\Framework\Assert;
use Porta\Editor\ChangeMarkKind;
use Porta\ReviewPlugin\Tests\Support\FakeForge;
use Porta\Tests\Behat\Support\McpPeer;
use Porta\Tests\Support\DummyServer;
use Porta\Tests\Support\Widgets;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * The collaborative and version-control plugins: git against a bare remote
 * kept in the workspace, the review plugin's stub forge CLIs, the remote
 * project servers, assist's two IDEs and the MCP server — and what of them the
 * screen cannot show.
 */
final class CollabContext implements TuinkAwareContextInterface
{
    use TuinkAwareTrait;

    /** Where the forge stand-in logs its calls and finds its answers, in the workspace. */
    private const string FORGE = '.forge';

    /** Where the bare remotes and their other clones live, in the workspace. */
    private const string REMOTES = '.remote';

    /** Claude Code's configuration folder, in the workspace. */
    private const string CLAUDE = '.claude';

    private PortaContext $porta;

    /** Porta's home, when a scenario keeps it in the workspace. */
    private const string HOME = '.home';

    private ?McpPeer $peer = null;

    private ?DummyServer $server = null;

    /** What the agent's last call was answered. */
    private string $answer = '';

    /** The request id of Claude Code's last proposal, its verdict still to come. */
    private ?int $proposal = null;

    #[BeforeScenario]
    public function gatherContexts(BeforeScenarioScope $scope): void
    {
        $environment = $scope->getEnvironment();
        Assert::assertInstanceOf(InitializedContextEnvironment::class, $environment, 'Behat hands its contexts to an initialized environment');
        $this->porta = $environment->getContext(PortaContext::class);
    }

    // --- Stand-ins -------------------------------------------------------------------

    /**
     * A script standing in for a tool, in the workspace: `%php%` is this PHP,
     * `%workspace%` the workspace, `%support%` the PHPUnit rig's folder,
     * `%plugins%` the bundled plugins'.
     */
    #[Given('a stand-in :path running:')]
    public function aStandIn(string $path, PyStringNode $script): void
    {
        $workspace = $this->getSession()->getWorkspace();
        $workspace->write($path, $this->expand($script->getRaw())."\n");
        \chmod($workspace->resolve($path), 0o755);
    }

    #[Given('the project configuration, its paths filled in:')]
    public function theProjectConfigurationWithPaths(PyStringNode $json): void
    {
        $this->porta->mergeProjectConfig(self::json($this->expand($json->getRaw())));
    }

    public function expand(string $text): string
    {
        return \strtr($text, [
            '%php%' => \PHP_BINARY,
            '%support%' => \dirname(__DIR__, 2).'/Support',
            '%plugins%' => \dirname(__DIR__, 3).'/plugins',
            '%workspace%' => $this->getSession()->getWorkspace()->path,
            '%port%' => (string) $this->server?->port,
            '%root%' => (string) $this->server?->root,
            '%ssh-client%' => DummyServer::SSH_CLIENT,
        ]);
    }

    // --- Remote projects ---------------------------------------------------------------

    /**
     * A Porta home of the scenario's own, where a remote project's cache and
     * the global configuration go: the suite's fixture home must not keep
     * them. It carries the fixture home's notes that keep the update checks
     * off the network.
     */
    #[Given("Porta's home is in the workspace")]
    public function portasHomeIsInTheWorkspace(): void
    {
        $workspace = $this->getSession()->getWorkspace();
        $fixture = \dirname(__DIR__, 2).'/Support/fixtures/home/.porta/';
        foreach (['ide-updates.json', 'tool-updates.json', 'cache/store/catalog.json'] as $note) {
            $workspace->write(self::HOME.'/.porta/'.$note, (string) \file_get_contents($fixture.$note));
        }
        $session = $this->getSession();
        $session->setLaunch($session->getLaunch()->withEnv('PORTA_HOME', $workspace->resolve(self::HOME)));
    }

    #[Given("Porta's global configuration:")]
    public function portasGlobalConfiguration(PyStringNode $json): void
    {
        $this->getSession()->getWorkspace()->write(self::HOME.'/.porta/config.json', $this->expand($json->getRaw())."\n");
    }

    /**
     * The stand-in ssh server (tests/Support/Servers), running commands in
     * the workspace folder $folder; the stand-in client reaches it.
     */
    #[Given('an ssh server serving :folder')]
    public function anSshServer(string $folder): void
    {
        $this->server = DummyServer::ssh((string) \realpath($this->getSession()->getWorkspace()->resolve($folder)));
    }

    /** The small real FTP server of tests/Support/Servers, serving $folder as `/`. */
    #[Given('an FTP server serving :folder')]
    public function anFtpServer(string $folder): void
    {
        $this->server = DummyServer::ftp((string) \realpath($this->getSession()->getWorkspace()->resolve($folder)));
    }

    #[AfterScenario]
    public function stopTheServer(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    /**
     * Types $address with `%port%` the server's port and `%root%` the folder it serves.
     */
    #[When('I type the address :address')]
    public function iTypeTheAddress(string $address): void
    {
        $this->getSession()->type($this->expand($address));
    }

    /**
     * The real entry point reopens where the session asked once it ends: so does this.
     */
    #[When('Porta reopens on the project it was asked for')]
    public function portaReopens(): void
    {
        $session = $this->getSession();
        $root = null;
        $session->waitUntil(function () use (&$root): bool {
            $root = $this->porta->ide()->reopenAt();

            return null !== $root;
        }, 'The IDE was asked to open no other project.');
        \assert(\is_string($root), description: 'waited for');
        $session->stop();
        $session->setLaunch($session->getLaunch()->withArguments([$root]));
        $session->start();
    }

    // --- Git ------------------------------------------------------------------------

    /**
     * Who Porta's own commits are by: the repository's config, so the user's
     * global identity never reaches a scenario.
     */
    #[Given('git commits here as :name')]
    public function gitCommitsHereAs(string $name): void
    {
        $this->porta->git('config', 'user.name', $name);
        $this->porta->git('config', 'user.email', \strtolower($name).'@example.invalid');
        $this->porta->git('config', 'commit.gpgsign', 'false');
    }

    #[Given('git :arguments was run')]
    public function gitWasRun(string $arguments): void
    {
        $this->porta->git(...self::words($arguments));
    }

    /**
     * A git command expected to stop with an error: a merge on a conflict.
     */
    #[Given('git :arguments fails')]
    public function gitFails(string $arguments): void
    {
        try {
            $this->porta->git(...self::words($arguments));
        } catch (ProcessFailedException) {
            return;
        }

        throw new \LogicException(\sprintf('git %s succeeded.', $arguments));
    }

    /**
     * A bare repository in the workspace (`.remote/origin.git`, which the
     * scenario's .gitignore should leave out) as the only remote, the current
     * branch pushed to it and tracking it.
     */
    #[Given('a bare remote :name the branch is pushed to')]
    public function aBareRemote(string $name): void
    {
        $this->porta->git('init', '-q', '--bare', '-b', 'main', self::REMOTES.'/'.$name.'.git');
        $this->porta->git('remote', 'add', $name, self::REMOTES.'/'.$name.'.git');
        $this->porta->git('push', '-q', '-u', $name, 'HEAD');
    }

    /**
     * A commit pushed to the remote from another clone of it: what a pull or a fetch brings in.
     */
    #[Given('someone else pushes :file to :name as :message')]
    public function someoneElsePushes(string $file, string $name, string $message): void
    {
        $other = self::REMOTES.'/'.$name.'-other';
        if (!\is_dir($this->getSession()->getWorkspace()->resolve($other))) {
            $this->porta->git('clone', '-q', self::REMOTES.'/'.$name.'.git', $other);
        }
        $this->getSession()->getWorkspace()->write($other.'/'.$file, $message."\n");
        $this->porta->git('-C', $other, 'add', '-A');
        $this->porta->git('-C', $other, 'commit', '-q', '-m', $message);
        $this->porta->git('-C', $other, 'push', '-q', 'origin', 'HEAD');
    }

    /**
     * The remote's side answers only after a while: a fetch from it hangs until cancelled.
     */
    #[Given('the remote :name is slow to answer')]
    public function theRemoteIsSlow(string $name): void
    {
        $this->porta->git('config', 'remote.'.$name.'.uploadpack', 'sleep 20; git-upload-pack');
    }

    /**
     * Checks what `git <arguments>` prints, retried until it does: Porta runs git in the background.
     */
    #[Then('git :arguments should print :text')]
    public function gitShouldPrint(string $arguments, string $text): void
    {
        $this->waitForGit($arguments, static fn (string $out): bool => \str_contains($out, $text), \sprintf('does not print "%s"', $text));
    }

    #[Then('git :arguments should not print :text')]
    public function gitShouldNotPrint(string $arguments, string $text): void
    {
        $this->waitForGit($arguments, static fn (string $out): bool => !\str_contains($out, $text), \sprintf('still prints "%s"', $text));
    }

    #[Then('git :arguments should print no output')]
    public function gitShouldPrintNothing(string $arguments): void
    {
        $this->waitForGit($arguments, static fn (string $out): bool => '' === \trim($out), 'prints something');
    }

    #[Then('git :arguments should print exactly:')]
    public function gitShouldPrintExactly(string $arguments, PyStringNode $expected): void
    {
        $text = \rtrim($expected->getRaw(), "\n");
        $this->waitForGit($arguments, static fn (string $out): bool => \rtrim($out, "\n") === $text, "does not print exactly:\n".$text);
    }

    // --- Code review: the forge's CLI, faked ------------------------------------------

    /**
     * The review plugin's `fake-forge`: a `gh`, `glab` (or `curl`) that logs
     * each call in the workspace and answers by route — a call whose argv or
     * stdin holds a needle gets that needle's JSON.
     */
    #[Given('the review plugin runs the forge stand-in as :key')]
    public function theForgeStandIn(string $key): void
    {
        $this->porta->mergeProjectConfig(['plugins' => ['review' => [$key => FakeForge::SCRIPT]]]);
    }

    /**
     * @param list<array{author: string, line: string, file: string, comment: string, resolved?: string}> $threads
     */
    #[Given('GitHub has the pull request :title for this branch, with the threads:')]
    public function gitHubHasThePullRequest(string $title, TableNode $threads): void
    {
        $head = \trim($this->porta->git('rev-parse', 'HEAD'));
        $comment = static fn (string $author, string $body): array => ['author' => ['login' => $author], 'body' => $body, 'createdAt' => '2026-09-28T10:00:00Z'];
        $nodes = [];
        foreach ($threads->getHash() as $n => $row) {
            $nodes[] = [
                'id' => 'PRRT_'.$n,
                'isResolved' => 'yes' === ($row['resolved'] ?? 'no'),
                'isOutdated' => false,
                'path' => $row['file'],
                'line' => (int) $row['line'],
                'originalLine' => (int) $row['line'],
                'diffSide' => 'RIGHT',
                'comments' => ['nodes' => [$comment($row['author'], $row['comment'])]],
            ];
        }
        $this->forgeRoute('query(', ['data' => ['repository' => ['pullRequests' => ['nodes' => [[
            'id' => 'PR_node',
            'number' => 12,
            'title' => $title,
            'url' => 'https://github.com/acme/app/pull/12',
            'baseRefOid' => $head,
            'headRefOid' => $head,
            'comments' => ['nodes' => [$comment('alice', 'Please review.')]],
            'reviewThreads' => ['nodes' => $nodes],
        ]]]]]]);
    }

    /**
     * Merge request !7, "Tidy the dumper", as the review plugin's own fixtures have GitLab answer it.
     */
    #[Given('GitLab has a merge request for this branch')]
    public function gitLabHasAMergeRequest(): void
    {
        $fixtures = \dirname(__DIR__, 3).'/plugins/review/tests/fixtures/';
        // Routes are tried in order, and the discussions' path holds the merge request's.
        $this->forgeRoute('/discussions', self::json((string) \file_get_contents($fixtures.'gitlab-discussions.json')));
        $this->forgeRoute('merge_requests?', [['iid' => 7]]);
        $this->forgeRoute('merge_requests/7', self::json((string) \file_get_contents($fixtures.'gitlab-merge-request.json')));
    }

    #[Then("the editor's review marks should be empty")]
    public function theReviewMarksShouldBeEmpty(): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => [] === $this->reviewMarks(),
            fn (): string => 'The editor marks review threads on lines '.\implode(', ', \array_map(static fn (int $l): int => $l + 1, \array_keys($this->reviewMarks()))),
        );
    }

    #[Then("the editor's review marks should be on line :line")]
    public function theReviewMarksShouldBeOnLine(int $line): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => [$line - 1] === \array_keys($this->reviewMarks()),
            fn (): string => 'The editor marks review threads on lines '.\implode(', ', \array_map(static fn (int $l): int => $l + 1, \array_keys($this->reviewMarks()))),
        );
    }

    /**
     * A thread the IDE wrote among the local review's files: its thread.json names the line and keeps its text.
     */
    #[Then('the review should have a thread on :file line :line reading :text')]
    public function theReviewShouldHaveAThread(string $file, int $line, string $text): void
    {
        $expected = ['path' => $file, 'line' => $line, 'side' => 'new', 'lineText' => $text];
        $this->getSession()->waitUntil(
            fn (): bool => \in_array($expected, $this->localThreads(), strict: true),
            fn (): string => 'The local reviews hold the threads '.\json_encode($this->localThreads(), \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function localThreads(): array
    {
        $threads = [];
        foreach (\glob($this->getSession()->getWorkspace()->resolve('.porta/reviews').'/*/*/thread.json') ?: [] as $file) {
            $threads[] = self::json((string) \file_get_contents($file));
        }

        return $threads;
    }

    /**
     * @return array<int, string> 0-based line => the thread's mark text
     */
    private function reviewMarks(): array
    {
        return Widgets::editor($this->porta->tui())->decorations->commentMarks;
    }

    #[Then('the forge stand-in should have been asked for the review :count times or more')]
    public function theForgeShouldHaveBeenAsked(int $count): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => $this->forgeReads() >= $count,
            fn (): string => \sprintf('The forge was asked for the review %d times.', $this->forgeReads()),
        );
    }

    private function forgeReads(): int
    {
        $log = $this->getSession()->getWorkspace()->read(self::FORGE.'/calls.jsonl') ?? '';

        return \substr_count($log, 'query(');
    }

    /**
     * @param array<array-key, mixed> $json
     */
    private function forgeRoute(string $needle, array $json): void
    {
        $workspace = $this->getSession()->getWorkspace();
        $routes = self::json($workspace->read(self::FORGE.'/routes.json') ?? '{}');
        $file = 'route'.\count($routes).'.json';
        $workspace->write(self::FORGE.'/'.$file, \json_encode($json, \JSON_THROW_ON_ERROR));
        $routes[$needle] = $file;
        $workspace->write(self::FORGE.'/routes.json', \json_encode($routes, \JSON_THROW_ON_ERROR));
        $session = $this->getSession();
        $session->setLaunch($session->getLaunch()->withEnv('FAKE_FORGE_DIR', $workspace->resolve(self::FORGE)));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(string $json): array
    {
        $decoded = \json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded), description: 'a fixture holds an object or a list');

        return $decoded;
    }

    // --- Agents: an MCP client, and Claude Code -----------------------------------

    /**
     * Where the claude CLI looks for editors, kept in the workspace: the lock
     * file the IDE writes there is this scenario's alone.
     */
    #[Given("Claude Code's configuration is in the workspace")]
    public function claudeCodesConfiguration(): void
    {
        $session = $this->getSession();
        $session->getWorkspace()->createDirectory(self::CLAUDE.'/ide');
        $session->setLaunch($session->getLaunch()->withEnv('CLAUDE_CONFIG_DIR', $session->getWorkspace()->resolve(self::CLAUDE)));
    }

    #[Given('an agent is connected over MCP')]
    public function anAgentIsConnected(): void
    {
        $socket = $this->getSession()->getWorkspace()->resolve('.porta/mcp.sock');
        $this->getSession()->waitUntil(static fn (): bool => \file_exists($socket), 'The IDE does not listen on .porta/mcp.sock.');
        $this->agent()->open($socket);
    }

    #[When('the agent calls :tool')]
    public function theAgentCalls(string $tool): void
    {
        $this->answer = $this->agent()->tool($tool, []);
    }

    #[When('the agent calls :tool with:')]
    public function theAgentCallsWith(string $tool, PyStringNode $arguments): void
    {
        $this->answer = $this->agent()->tool($tool, self::json($this->expand($arguments->getRaw())));
    }

    #[Then("the agent's answer should contain :text")]
    public function theAgentsAnswerShouldContain(string $text): void
    {
        Assert::assertStringContainsString($text, $this->answer, 'The agent was answered: '.$this->answer);
    }

    #[Then("the agent's answer should report the exit code :code")]
    public function theAgentsAnswerShouldReportTheExitCode(int $code): void
    {
        $answer = \json_decode($this->answer, associative: true);
        Assert::assertSame($code, \is_array($answer) ? $answer['exitCode'] ?? null : null, 'The agent was answered: '.$this->answer);
    }

    #[Then('the agent should be offered :tool')]
    public function theAgentShouldBeOffered(string $tool): void
    {
        Assert::assertContains($tool, $this->agent()->tools(), 'The tools offered');
    }

    #[Then('the agent should not be offered :tool')]
    public function theAgentShouldNotBeOffered(string $tool): void
    {
        Assert::assertNotContains($tool, $this->agent()->tools(), 'The tools offered');
    }

    #[Given('Claude Code is connected to the IDE')]
    public function claudeCodeIsConnected(): void
    {
        $dir = $this->getSession()->getWorkspace()->resolve(self::CLAUDE);
        $this->getSession()->waitUntil(static fn (): bool => [] !== (\glob($dir.'/ide/*.lock') ?: []), 'The IDE announced itself to no Claude Code.');
        $this->agent()->claude($dir);
    }

    /**
     * Claude Code's openDiff, as the CLI sends it before an edit it would ask about: it waits for the verdict.
     */
    #[When('Claude Code proposes to change :file to:')]
    public function claudeCodeProposes(string $file, PyStringNode $text): void
    {
        $path = (string) \realpath($this->getSession()->getWorkspace()->resolve($file));
        $this->proposal = $this->agent()->propose($path, $text->getRaw()."\n", \basename($file).' (proposed)');
    }

    #[Then('Claude Code should hear :verdict')]
    public function claudeCodeShouldHear(string $verdict): void
    {
        Assert::assertNotNull($this->proposal, 'Claude Code proposed nothing.');
        Assert::assertSame($verdict, $this->agent()->verdict($this->proposal));
    }

    #[Then('Claude Code should be pointed at :file')]
    public function claudeCodeShouldBePointedAt(string $file): void
    {
        $mention = $this->agent()->mention();
        Assert::assertSame((string) \realpath($this->getSession()->getWorkspace()->resolve($file)), $mention['filePath'] ?? null, 'The mention: '.\json_encode($mention, \JSON_THROW_ON_ERROR));
    }

    #[Then('no IDE should be announced to Claude Code')]
    public function noIdeShouldBeAnnounced(): void
    {
        Assert::assertSame([], \glob($this->getSession()->getWorkspace()->resolve(self::CLAUDE).'/ide/*.lock') ?: []);
    }

    #[Then('the IDE should be announced to Claude Code')]
    public function theIdeShouldBeAnnounced(): void
    {
        $dir = $this->getSession()->getWorkspace()->resolve(self::CLAUDE);
        $this->getSession()->waitUntil(static fn (): bool => [] !== (\glob($dir.'/ide/*.lock') ?: []), 'The IDE announced itself to no Claude Code.');
    }

    /**
     * One peer per scenario, speaking MCP the way the mcp plugin's own E2e
     * tests do — their traits, the loop pumped by tuink while it waits.
     */
    private function agent(): McpPeer
    {
        return $this->peer ??= new McpPeer($this->getSession());
    }

    // --- The gutter -------------------------------------------------------------------

    #[Then('the gutter should mark line :line as :kind')]
    public function theGutterShouldMark(int $line, string $kind): void
    {
        $expected = ChangeMarkKind::from($kind);
        $this->getSession()->waitUntil(
            fn (): bool => ($this->changeMarks()[$line - 1] ?? null) === $expected,
            fn (): string => 'The gutter marks '.$this->describeMarks(),
        );
    }

    #[Then('the gutter should mark no line')]
    public function theGutterShouldMarkNoLine(): void
    {
        $this->getSession()->waitUntil(
            fn (): bool => [] === $this->changeMarks(),
            fn (): string => 'The gutter marks '.$this->describeMarks(),
        );
    }

    /**
     * @return array<int, ChangeMarkKind> 0-based line => kind, as the gutter shows them
     */
    private function changeMarks(): array
    {
        return Widgets::editor($this->porta->tui())->decorations->shownChangeMarks();
    }

    private function describeMarks(): string
    {
        $marks = $this->changeMarks();
        if ([] === $marks) {
            return 'no line.';
        }
        $said = [];
        foreach ($marks as $line => $kind) {
            $said[] = \sprintf('line %d as %s', $line + 1, $kind->value);
        }

        return \implode(', ', $said).'.';
    }

    /**
     * @param \Closure(string): bool $holds
     */
    private function waitForGit(string $arguments, \Closure $holds, string $failure): void
    {
        $words = self::words($arguments);
        $last = '';
        $this->getSession()->waitUntil(
            function () use ($words, $holds, &$last): bool {
                // A command that fails now may not once Porta's own git is done: it is retried too.
                try {
                    $last = $this->porta->git(...$words);
                } catch (ProcessFailedException $failed) {
                    $last = $failed->getProcess()->getErrorOutput();

                    return false;
                }

                return $holds($last);
            },
            static function () use ($arguments, $failure, &$last): string {
                return \sprintf("git %s %s. It prints:\n%s", $arguments, $failure, $last);
            },
        );
    }

    /**
     * @return list<string> git's arguments, split on spaces: no argument a scenario passes has one
     */
    private static function words(string $arguments): array
    {
        $words = \preg_split('/\s+/', \trim($arguments), flags: \PREG_SPLIT_NO_EMPTY);
        \assert(\is_array($words), description: 'a split of a string is a list');

        return $words;
    }
}
