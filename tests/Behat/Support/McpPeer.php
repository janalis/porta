<?php

declare(strict_types=1);

namespace Porta\Tests\Behat\Support;

use Janalis\Tuink\Session;
use PHPUnit\Framework\Assert;
use Porta\McpPlugin\Tests\Support\TalksClaudeCode;
use Porta\McpPlugin\Tests\Support\TalksMcp;

/**
 * The agents a scenario plays: an MCP client on the IDE's socket, and the
 * Claude Code CLI on its announced port — the mcp plugin's own E2e traits,
 * waiting through tuink so the in-process IDE's loop runs meanwhile.
 */
final class McpPeer extends Assert
{
    // @mago-expect analysis:non-existent-class-like -- the mcp plugin's E2e support, which the analyzer leaves out with the plugin's tests
    use TalksClaudeCode;
    // @mago-expect analysis:non-existent-class-like -- the same
    use TalksMcp;

    /** Request ids of Claude Code's proposals, apart from the trait's own. */
    private int $proposals = 1_000;

    public function __construct(
        private readonly Session $session,
    ) {}

    /** Connect to the IDE's MCP socket and shake hands, as `porta mcp` does. */
    public function open(string $socket): void
    {
        $client = \stream_socket_client('unix://'.$socket, timeout: 1.0);
        self::assertIsResource($client, 'the plugin listens once the IDE is up');
        \stream_set_blocking($client, enable: false);
        $this->client = $client;
        $this->request('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']]);
        \fwrite($client, '{"jsonrpc":"2.0","method":"notifications/initialized"}'."\n");
    }

    /**
     * A tool's text, whether it succeeded or not.
     *
     * @param array<array-key, mixed> $arguments
     */
    public function tool(string $name, array $arguments): string
    {
        /** @var array<string, mixed> $answer */
        $answer = $this->request('tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
        if (!\is_array($answer['result'] ?? null)) {
            return \json_encode($answer, \JSON_THROW_ON_ERROR);
        }
        /** @var array{content: list<array{text: string}>, isError: bool} $result */
        $result = $answer['result'];

        return ($result['isError'] ? 'ERROR: ' : '').($result['content'][0]['text'] ?? '');
    }

    /** @return list<string> the names of the tools the agent is offered */
    public function tools(): array
    {
        /** @var array{result: array{tools: list<array{name: string}>}} $answer */
        $answer = $this->request('tools/list');

        return \array_column($answer['result']['tools'], 'name');
    }

    /** Connect as the claude CLI does, through the lock file in $configDir. */
    public function claude(string $configDir): void
    {
        $this->connectCli($configDir);
    }

    /** Send Claude Code's openDiff, and return its id: the verdict comes when the user gives it. */
    public function propose(string $path, string $text, string $tab): int
    {
        $id = ++$this->proposals;
        $this->cliWrite(self::clientFrame(\json_encode(
            [
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => 'tools/call',
                'params' => ['name' => 'openDiff', 'arguments' => ['old_file_path' => $path, 'new_file_path' => $path, 'new_file_contents' => $text, 'tab_name' => $tab]],
            ],
            \JSON_THROW_ON_ERROR,
        )));

        return $id;
    }

    /** The first word of the user's verdict on proposal $id: FILE_SAVED, DIFF_REJECTED. */
    public function verdict(int $id): string
    {
        /** @var array{result: array{content: list<array{text: string}>}} $answer */
        $answer = $this->cliAwait(static fn (array $m): bool => $id === ($m['id'] ?? null), what: 'the verdict');

        return $answer['result']['content'][0]['text'] ?? '';
    }

    /** @return array<array-key, mixed> the next @-mention the IDE sends the CLI */
    public function mention(): array
    {
        /** @var array<string, mixed> $mention */
        $mention = $this->cliAwait(static fn (array $m): bool => 'at_mentioned' === ($m['method'] ?? null), what: 'the mention');
        /** @var mixed $params */
        $params = $mention['params'] ?? [];

        return \is_array($params) ? $params : [];
    }

    private function await(callable $done, float $timeout = 10.0, string $what = 'condition'): void
    {
        $this->session->waitUntil(static fn (): bool => true === $done(), \sprintf('Waited in vain for %s.', $what), $timeout);
    }
}
